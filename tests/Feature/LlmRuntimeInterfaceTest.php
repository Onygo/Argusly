<?php

namespace Tests\Feature;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Models\Account;
use App\Models\Brand;
use App\Models\DomainEvent;
use App\Models\LlmRequest as LlmRequestRecord;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Llm\Clients\FakeLlmClient;
use App\Services\Llm\Clients\OpenAiLlmClient;
use App\Services\Llm\LlmClientManager;
use Database\Seeders\CreditCostCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmRuntimeInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_client_interface_resolves_to_central_manager(): void
    {
        $this->assertInstanceOf(LlmClientManager::class, app(LlmClientInterface::class));
    }

    public function test_fake_provider_can_generate_chat_stream_embed_and_vision_responses(): void
    {
        $client = new FakeLlmClient('fake');
        $request = new LlmRequest(
            provider: 'fake',
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Write a draft.']],
            metadata: ['fake_content' => 'A controlled fake response.'],
        );

        $this->assertSame('A controlled fake response.', $client->chat($request)->content);
        $this->assertSame('A controlled fake response.', $client->generate($request)->content);
        $this->assertSame(['A controlled fake response.'], iterator_to_array($client->stream($request)));
        $this->assertSame('A controlled fake response.', $client->embed($request)->content);
        $this->assertSame('A controlled fake response.', $client->vision($request)->content);
    }

    public function test_manager_routes_configured_non_openai_providers_to_fake_runtime_clients(): void
    {
        $request = new LlmRequest(
            provider: 'anthropic',
            model: 'claude-sonnet-4-20250514',
            messages: [['role' => 'user', 'content' => 'Draft something.']],
        );

        $response = app(LlmClientInterface::class)->generate($request);

        $this->assertSame('anthropic', $response->provider);
        $this->assertSame('claude-sonnet-4-20250514', $response->model);
        $this->assertTrue($response->rawResponse['fake']);
    }

    public function test_successful_llm_call_records_usage_charges_credits_and_emits_events(): void
    {
        [$account, $brand, $user] = $this->tenant();
        app(CreditService::class)->grant($account, 1000, $user, 'LLM test credits');

        $response = app(LlmClientInterface::class)->generate(new LlmRequest(
            provider: 'fake',
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Generate content.']],
            metadata: [
                'purpose' => 'content_generation',
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'fake_content' => 'Tracked fake generation.',
            ],
        ));

        $record = LlmRequestRecord::query()->firstOrFail();

        $this->assertSame('Tracked fake generation.', $response->content);
        $this->assertSame('completed', $record->status);
        $this->assertSame('content_generation', $record->purpose);
        $this->assertSame($account->id, $record->account_id);
        $this->assertSame($brand->id, $record->brand_id);
        $this->assertSame($user->id, $record->user_id);
        $this->assertNotNull($record->prompt_tokens);
        $this->assertNotNull($record->completion_tokens);
        $this->assertNotNull($record->total_tokens);
        $this->assertSame(100, $record->credits_charged);
        $this->assertSame(900, app(CreditService::class)->balance($account));

        $this->assertDatabaseHas('domain_events', ['event_type' => 'LlmRequestCompleted', 'subject_id' => $record->id]);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'LlmCreditsConsumed', 'subject_id' => $record->id]);
    }

    public function test_failed_primary_llm_call_records_error_and_uses_fallback_without_charging_failure(): void
    {
        [$account, $brand, $user] = $this->tenant();
        app(CreditService::class)->grant($account, 1000, $user, 'LLM fallback credits');

        $response = app(LlmClientInterface::class)->generate(new LlmRequest(
            provider: 'unsupported-primary',
            model: 'missing-model',
            messages: [['role' => 'user', 'content' => 'Generate with fallback.']],
            metadata: [
                'purpose' => 'answer_block',
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'fallback_provider' => 'fake',
                'fallback_model' => 'fallback-model',
                'fake_content' => 'Fallback content.',
            ],
        ));

        $failed = LlmRequestRecord::query()->where('status', 'failed')->firstOrFail();
        $completed = LlmRequestRecord::query()->where('status', 'completed')->firstOrFail();

        $this->assertSame('Fallback content.', $response->content);
        $this->assertSame('unsupported-primary', $failed->provider);
        $this->assertSame(0, $failed->credits_charged);
        $this->assertStringContainsString('Unsupported LLM runtime provider', $failed->error_message);
        $this->assertSame('fake', $completed->provider);
        $this->assertSame('fallback-model', $completed->model);
        $this->assertSame($failed->id, $completed->metadata['fallback_of_llm_request_id']);
        $this->assertSame(25, $completed->credits_charged);
        $this->assertSame(975, app(CreditService::class)->balance($account));

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'LlmRequestFailed')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'LlmRequestCompleted')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'LlmCreditsConsumed')->count());
    }

    public function test_openai_client_uses_http_runtime_when_api_key_is_present(): void
    {
        config()->set('llm.allow_testing_http', true);

        $originalEnv = env('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=test-openai-key');
        $_ENV['OPENAI_API_KEY'] = 'test-openai-key';
        $_SERVER['OPENAI_API_KEY'] = 'test-openai-key';

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    [
                        'message' => ['content' => 'OpenAI runtime response.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ]),
        ]);

        try {
            $response = (new OpenAiLlmClient(new FakeLlmClient('openai')))->chat(new LlmRequest(
                provider: 'openai',
                model: 'gpt-4.1-mini',
                messages: [['role' => 'user', 'content' => 'Hello']],
                systemPrompt: 'Be concise.',
                temperature: 0.2,
                maxTokens: 100,
                responseFormat: 'json_object',
            ));
        } finally {
            if ($originalEnv === null) {
                putenv('OPENAI_API_KEY');
                unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
            } else {
                putenv("OPENAI_API_KEY={$originalEnv}");
                $_ENV['OPENAI_API_KEY'] = $originalEnv;
                $_SERVER['OPENAI_API_KEY'] = $originalEnv;
            }
        }

        $this->assertSame('OpenAI runtime response.', $response->content);
        $this->assertSame(15, $response->usage->totalTokens);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request['model'] === 'gpt-4.1-mini'
            && $request['messages'][0]['role'] === 'system'
            && $request['response_format']['type'] === 'json_object');
    }

    /**
     * @return array{Account, Brand, User}
     */
    private function tenant(): array
    {
        $this->seed(CreditCostCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'LLM Runtime Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'LLM Runtime Brand', 'slug' => fake()->unique()->slug()]);

        return [$account, $brand, $user];
    }
}
