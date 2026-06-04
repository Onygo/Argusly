<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\LlmModel;
use App\Models\LlmProvider;

class LlmResolver
{
    public function __construct(
        private readonly LlmSettingsService $settings,
        private readonly LlmProviderRegistry $providers,
        private readonly LlmModelRegistry $models,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     provider: array<string, mixed>,
     *     model: array<string, mixed>,
     *     fallback_provider: ?array<string, mixed>,
     *     fallback_model: ?array<string, mixed>,
     *     temperature: mixed,
     *     max_tokens: mixed,
     *     settings: ?array<string, mixed>
     * }
     */
    public function resolve(?Account $account = null, ?Brand $brand = null, string $type = 'chat'): array
    {
        $account ??= $brand?->account;
        $setting = $this->settings->settingFor($account, $brand);

        if ($setting === null) {
            return $this->envFallback();
        }

        $setting->loadMissing(['defaultProvider', 'defaultModel', 'fallbackProvider', 'fallbackModel']);

        $provider = $setting->defaultProvider;
        $model = $setting->defaultModel;

        if ($provider !== null && $model === null) {
            $model = $this->models->activeForType($provider, $type);
        }

        if ($provider === null || $model === null) {
            return $this->envFallback($setting->settings, $setting->temperature, $setting->max_tokens);
        }

        return [
            'source' => $this->sourceFor($setting->account_id, $setting->brand_id),
            'provider' => $this->serializeProvider($provider),
            'model' => $this->serializeModel($model),
            'fallback_provider' => $setting->fallbackProvider ? $this->serializeProvider($setting->fallbackProvider) : null,
            'fallback_model' => $setting->fallbackModel ? $this->serializeModel($setting->fallbackModel) : null,
            'temperature' => $setting->temperature,
            'max_tokens' => $setting->max_tokens,
            'settings' => $setting->settings,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    private function envFallback(?array $settings = null, mixed $temperature = null, mixed $maxTokens = null): array
    {
        return [
            'source' => 'env',
            'provider' => $this->providers->envFallbackProvider(),
            'model' => $this->models->envFallbackModel(),
            'fallback_provider' => $this->providers->envConfiguredFallbackProvider(),
            'fallback_model' => $this->models->envConfiguredFallbackModel(),
            'temperature' => $temperature ?? config('llm.temperature'),
            'max_tokens' => $maxTokens ?? config('llm.max_tokens'),
            'settings' => $settings,
        ];
    }

    private function sourceFor(?int $accountId, ?int $brandId): string
    {
        if ($brandId !== null) {
            return 'brand';
        }

        return $accountId !== null ? 'account' : 'global';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProvider(LlmProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'uuid' => $provider->uuid,
            'provider' => $provider->provider,
            'name' => $provider->name,
            'status' => $provider->status,
            'base_url' => $provider->base_url,
            'api_key_env' => $provider->api_key_env,
            'settings' => $provider->settings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeModel(LlmModel $model): array
    {
        return [
            'id' => $model->id,
            'uuid' => $model->uuid,
            'provider_id' => $model->provider_id,
            'model' => $model->model,
            'name' => $model->name,
            'type' => $model->type,
            'context_window' => $model->context_window,
            'supports_json' => $model->supports_json,
            'supports_tools' => $model->supports_tools,
            'supports_vision' => $model->supports_vision,
            'supports_streaming' => $model->supports_streaming,
            'input_cost_per_1k' => $model->input_cost_per_1k,
            'output_cost_per_1k' => $model->output_cost_per_1k,
            'status' => $model->status,
            'metadata' => $model->metadata,
        ];
    }
}
