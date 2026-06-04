<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\VisibilityCheck;
use App\Services\CreditService;
use App\Services\Visibility\ProviderRegistry;
use App\Services\Visibility\ProviderRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AiVisibilityProviderAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_registry_exposes_prepared_fake_providers(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame([
            'chatgpt',
            'claude',
            'gemini',
            'perplexity',
            'google_ai_overviews',
        ], $registry->keys());
        $this->assertSame('ChatGPT', $registry->get('chatgpt')->name());
        $this->assertSame('Google AI Overviews', $registry->get('Google AI Overviews')->name());
    }

    public function test_provider_run_service_stores_normalized_artifacts_and_domain_event(): void
    {
        [$account, $brand] = $this->tenant();
        $check = VisibilityCheck::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'brand' => 'Argusly',
            'query' => 'best AI visibility platform',
            'status' => 'active',
        ]);

        $run = app(ProviderRunService::class)->runPrompt(
            account: $account,
            brand: $brand,
            provider: 'chatgpt',
            prompt: 'best AI visibility platform',
            check: $check,
            context: [
                'brand' => 'Argusly',
                'language' => 'de',
                'locale' => 'de_DE',
                'market' => 'DE',
                'persona' => 'marketing leader',
                'intent' => 'commercial',
            ],
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame('ChatGPT', $run->provider);
        $this->assertSame(config('llm.default_model'), $run->model);
        $this->assertSame('de', $run->language);
        $this->assertSame('de_DE', $run->locale);
        $this->assertSame('DE', $run->market);
        $this->assertSame('de', $run->input_language);
        $this->assertSame('DE', $run->target_market);
        $this->assertSame('de', $run->normalized_answer_language);
        $this->assertSame('de', $run->detected_language);
        $this->assertSame('marketing leader', $run->persona);
        $this->assertSame('commercial', $run->intent);
        $this->assertStringContainsString('Argusly', $run->normalized_answer);
        $this->assertSame('chatgpt', $run->metadata['adapter_key']);
        $this->assertIsInt($run->metadata['visibility_score']);
        $this->assertGreaterThan(0, $run->citations()->count());
        $this->assertGreaterThan(0, $run->answerEntities()->count());
        $this->assertDatabaseHas('visibility_citations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider_run_id' => $run->id,
            'rank' => 1,
        ]);
        $this->assertDatabaseHas('visibility_answer_entities', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider_run_id' => $run->id,
            'entity_name' => 'Argusly',
            'entity_type' => 'brand',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'VisibilityProviderRunCompleted',
            'subject_id' => $run->id,
        ]);
    }

    public function test_provider_run_service_rejects_unknown_provider(): void
    {
        [$account, $brand] = $this->tenant();

        $this->expectException(InvalidArgumentException::class);

        app(ProviderRunService::class)->runPrompt(
            account: $account,
            brand: $brand,
            provider: 'unknown',
            prompt: 'test prompt',
            context: ['brand' => 'Argusly'],
        );
    }

    /**
     * @return array{0: Account, 1: Brand}
     */
    private function tenant(): array
    {
        $account = Account::query()->create(['name' => 'Visibility Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Argusly', 'slug' => fake()->unique()->slug()]);
        app(CreditService::class)->grant($account, 1000, null, 'Test LLM credits');

        return [$account, $brand];
    }
}
