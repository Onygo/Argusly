<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmSetting;
use App\Services\LlmResolver;
use App\Services\LlmSettingsService;
use Database\Seeders\LlmProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class LlmProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_foundation_tables_have_expected_columns(): void
    {
        foreach (['llm_providers', 'llm_models', 'llm_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('llm_providers', [
            'id', 'uuid', 'provider', 'name', 'status', 'base_url', 'api_key_env', 'settings',
        ]));

        $this->assertTrue(Schema::hasColumns('llm_models', [
            'id', 'uuid', 'provider_id', 'model', 'name', 'type', 'context_window',
            'supports_json', 'supports_tools', 'supports_vision', 'supports_streaming',
            'input_cost_per_1k', 'output_cost_per_1k', 'status', 'metadata',
        ]));

        $this->assertTrue(Schema::hasColumns('llm_settings', [
            'id', 'uuid', 'account_id', 'brand_id', 'default_provider_id', 'default_model_id',
            'fallback_provider_id', 'fallback_model_id', 'temperature', 'max_tokens', 'settings',
        ]));
    }

    public function test_seeder_creates_supported_providers_and_common_models(): void
    {
        $this->seed(LlmProviderSeeder::class);

        $this->assertSame(
            ['anthropic', 'google', 'groq', 'mistral', 'openai', 'openrouter'],
            LlmProvider::query()->orderBy('provider')->pluck('provider')->all(),
        );

        $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
        $this->assertSame('OPENAI_API_KEY', $openai->api_key_env);

        $this->assertDatabaseHas('llm_models', [
            'provider_id' => $openai->id,
            'model' => 'gpt-4.1-mini',
            'type' => 'chat',
            'supports_json' => true,
            'supports_tools' => true,
        ]);
    }

    public function test_resolver_uses_brand_account_global_then_env_fallback_order(): void
    {
        $this->seed(LlmProviderSeeder::class);

        [$account, $brand] = $this->tenant();
        $service = app(LlmSettingsService::class);
        $resolver = app(LlmResolver::class);

        $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
        $openaiModel = LlmModel::query()->whereBelongsTo($openai, 'provider')->where('model', 'gpt-4.1-mini')->firstOrFail();
        $anthropic = LlmProvider::query()->where('provider', 'anthropic')->firstOrFail();
        $anthropicModel = LlmModel::query()->whereBelongsTo($anthropic, 'provider')->where('model', 'claude-sonnet-4-20250514')->firstOrFail();
        $google = LlmProvider::query()->where('provider', 'google')->firstOrFail();
        $googleModel = LlmModel::query()->whereBelongsTo($google, 'provider')->where('model', 'gemini-2.5-flash')->firstOrFail();

        $service->upsertGlobal([
            'default_provider_id' => $openai->id,
            'default_model_id' => $openaiModel->id,
            'temperature' => 0.30,
            'max_tokens' => 1200,
        ]);

        $this->assertSame('global', $resolver->resolve($account, $brand)['source']);
        $this->assertSame('openai', $resolver->resolve($account, $brand)['provider']['provider']);

        $service->upsertAccount($account, [
            'default_provider_id' => $anthropic->id,
            'default_model_id' => $anthropicModel->id,
            'temperature' => 0.50,
            'max_tokens' => 2000,
        ]);

        $accountResolved = $resolver->resolve($account, $brand);
        $this->assertSame('account', $accountResolved['source']);
        $this->assertSame('anthropic', $accountResolved['provider']['provider']);
        $this->assertSame('claude-sonnet-4-20250514', $accountResolved['model']['model']);

        $service->upsertBrand($account, $brand, [
            'default_provider_id' => $google->id,
            'default_model_id' => $googleModel->id,
            'fallback_provider_id' => $openai->id,
            'fallback_model_id' => $openaiModel->id,
            'temperature' => 0.70,
            'max_tokens' => 4000,
            'settings' => ['purpose' => 'drafting'],
        ]);

        $brandResolved = $resolver->resolve(null, $brand);
        $this->assertSame('brand', $brandResolved['source']);
        $this->assertSame('google', $brandResolved['provider']['provider']);
        $this->assertSame('gemini-2.5-flash', $brandResolved['model']['model']);
        $this->assertSame('openai', $brandResolved['fallback_provider']['provider']);
        $this->assertSame('0.70', $brandResolved['temperature']);
        $this->assertSame(['purpose' => 'drafting'], $brandResolved['settings']);
    }

    public function test_resolver_uses_env_fallback_when_no_database_setting_exists(): void
    {
        config()->set('llm.default_provider', 'mistral');
        config()->set('llm.default_model', 'mistral-large-latest');
        config()->set('llm.fallback_provider', 'openrouter');
        config()->set('llm.fallback_model', 'anthropic/claude-sonnet-4');
        config()->set('llm.temperature', 0.20);
        config()->set('llm.max_tokens', 900);

        $resolved = app(LlmResolver::class)->resolve();

        $this->assertSame('env', $resolved['source']);
        $this->assertSame('mistral', $resolved['provider']['provider']);
        $this->assertSame('mistral-large-latest', $resolved['model']['model']);
        $this->assertSame('openrouter', $resolved['fallback_provider']['provider']);
        $this->assertSame('anthropic/claude-sonnet-4', $resolved['fallback_model']['model']);
        $this->assertSame(0.20, $resolved['temperature']);
        $this->assertSame(900, $resolved['max_tokens']);
    }

    public function test_settings_reject_cross_account_brand(): void
    {
        $this->seed(LlmProviderSeeder::class);

        [$account] = $this->tenant('llm-a');
        [, $foreignBrand] = $this->tenant('llm-b');
        $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
        $anthropic = LlmProvider::query()->where('provider', 'anthropic')->firstOrFail();
        $anthropicModel = LlmModel::query()->whereBelongsTo($anthropic, 'provider')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        LlmSetting::query()->create([
            'account_id' => $account->id,
            'brand_id' => $foreignBrand->id,
            'default_provider_id' => $openai->id,
            'default_model_id' => $anthropicModel->id,
        ]);
    }

    public function test_settings_reject_model_provider_mismatch(): void
    {
        $this->seed(LlmProviderSeeder::class);

        [$account, $brand] = $this->tenant('llm-mismatch');
        $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
        $anthropic = LlmProvider::query()->where('provider', 'anthropic')->firstOrFail();
        $anthropicModel = LlmModel::query()->whereBelongsTo($anthropic, 'provider')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        LlmSetting::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'default_provider_id' => $openai->id,
            'default_model_id' => $anthropicModel->id,
        ]);
    }

    /**
     * @return array{Account, Brand}
     */
    private function tenant(string $slug = 'llm-account'): array
    {
        $account = Account::query()->create([
            'name' => str($slug)->headline(),
            'slug' => fake()->unique()->slug(),
        ]);

        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en'],
        ]);

        return [$account, $brand];
    }
}
