<?php

namespace App\Services\Llm;

use App\Models\LlmGlobalSetting;
use App\Models\LlmRoutingRule;
use Illuminate\Database\QueryException;

class LlmRoutingService
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_WORKSPACE = 'workspace';
    public const SCOPE_SITE = 'site';

    /**
     * @return array<string,array<string,string>>
     */
    public function features(): array
    {
        return (array) config('llm.features', [
            'brief_generation' => ['label' => 'Brief generation', 'modality' => 'text'],
            'draft_generation' => ['label' => 'Draft generation', 'modality' => 'text'],
            'rewrite' => ['label' => 'Rewrite', 'modality' => 'text'],
            'seo_optimization' => ['label' => 'SEO optimization', 'modality' => 'text'],
            'intelligence_analysis' => ['label' => 'Intelligence analysis', 'modality' => 'text'],
            'link_suggestions' => ['label' => 'Link suggestions', 'modality' => 'text'],
            'llm_tracking' => ['label' => 'LLM tracking', 'modality' => 'text'],
            'image_generation' => ['label' => 'Image generation', 'modality' => 'image'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalSettings(): array
    {
        $defaults = [
            'id' => 1,
            'default_text_provider' => (string) config('llm.default_provider', 'openai'),
            'default_image_provider' => (string) config('publishlayer.ai.images.provider', 'openai'),
            'default_text_model_map' => [
                'openai' => (string) config('llm.providers.openai.default_model', ''),
                'anthropic' => (string) config('llm.providers.anthropic.default_model', ''),
                'gemini' => (string) config('llm.providers.gemini.default_model', ''),
                'mistral' => (string) config('llm.providers.mistral.default_model', ''),
            ],
            'default_image_model_map' => [
                'openai' => (string) config('publishlayer.ai.images.openai.model', ''),
                'anthropic' => '',
                'gemini' => (string) config('publishlayer.ai.images.gemini.model', ''),
                'mistral' => '',
            ],
            'timeout_seconds' => (int) config('llm.timeouts.request_seconds', 180),
            'retry_max' => (int) config('llm.retries.max_attempts', 2),
            'retry_backoff_ms' => (int) config('llm.retries.base_backoff_ms', 800),
        ];

        try {
            $row = LlmGlobalSetting::query()->find(1);
        } catch (QueryException) {
            return $defaults;
        }

        if (! $row) {
            return $defaults;
        }

        return array_merge($defaults, $row->toArray());
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveGlobalSettings(array $data): LlmGlobalSetting
    {
        return LlmGlobalSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'default_text_provider' => (string) $data['default_text_provider'],
                'default_image_provider' => (string) $data['default_image_provider'],
                'default_text_model_map' => (array) ($data['default_text_model_map'] ?? []),
                'default_image_model_map' => (array) ($data['default_image_model_map'] ?? []),
                'timeout_seconds' => (int) ($data['timeout_seconds'] ?? 180),
                'retry_max' => (int) ($data['retry_max'] ?? 2),
                'retry_backoff_ms' => (int) ($data['retry_backoff_ms'] ?? 800),
            ],
        );
    }

    /**
     * @return array{provider:string,model:string,fallback_provider:?string,fallback_model:?string,fallback_enabled:bool}
     */
    public function resolve(
        string $feature,
        string $modality = 'text',
        ?string $workspaceId = null,
        ?string $siteId = null,
        ?string $requestedProvider = null,
        ?string $requestedModel = null,
    ): array {
        $global = $this->getGlobalSettings();

        $provider = $modality === 'image'
            ? (string) ($global['default_image_provider'] ?? 'openai')
            : (string) ($global['default_text_provider'] ?? 'openai');

        $modelMap = $modality === 'image'
            ? (array) ($global['default_image_model_map'] ?? [])
            : (array) ($global['default_text_model_map'] ?? []);

        $model = (string) ($modelMap[$provider] ?? '');

        $rule = $this->resolveRule($feature, $workspaceId, $siteId);
        $fallbackEnabled = false;
        $fallbackProvider = null;
        $fallbackModel = null;

        if ($rule && $rule->is_enabled) {
            if (! $rule->inherit_global) {
                $provider = '';
                $model = '';
            }

            if (trim((string) $rule->provider) !== '') {
                $provider = (string) $rule->provider;
            }

            if (trim((string) $rule->model) !== '') {
                $model = (string) $rule->model;
            }

            $fallbackEnabled = (bool) $rule->fallback_enabled;
            $fallbackProvider = $rule->fallback_provider ?: null;
            $fallbackModel = $rule->fallback_model ?: null;
        }

        if (trim((string) $requestedProvider) !== '') {
            $provider = (string) $requestedProvider;
        }

        if (trim((string) $requestedModel) !== '') {
            $model = (string) $requestedModel;
        }

        if ($model === '' && $provider !== '') {
            $model = (string) ($modelMap[$provider] ?? '');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'fallback_provider' => $fallbackEnabled ? $fallbackProvider : null,
            'fallback_model' => $fallbackEnabled ? $fallbackModel : null,
            'fallback_enabled' => $fallbackEnabled,
        ];
    }

    public function supportsModality(string $provider, string $modality): bool
    {
        $capabilities = (array) config('llm.capabilities', [
            'openai' => ['text', 'image'],
            'anthropic' => ['text'],
            'gemini' => ['text', 'image'],
            'mistral' => ['text'],
        ]);

        return in_array($modality, (array) ($capabilities[$provider] ?? []), true);
    }

    private function resolveRule(string $feature, ?string $workspaceId, ?string $siteId): ?LlmRoutingRule
    {
        try {
            if ($siteId) {
                $rule = LlmRoutingRule::query()
                    ->where('scope_type', self::SCOPE_SITE)
                    ->where('scope_id', $siteId)
                    ->where('feature', $feature)
                    ->first();

                if ($rule) {
                    return $rule;
                }
            }

            if ($workspaceId) {
                $rule = LlmRoutingRule::query()
                    ->where('scope_type', self::SCOPE_WORKSPACE)
                    ->where('scope_id', $workspaceId)
                    ->where('feature', $feature)
                    ->first();

                if ($rule) {
                    return $rule;
                }
            }

            return LlmRoutingRule::query()
                ->where('scope_type', self::SCOPE_GLOBAL)
                ->whereNull('scope_id')
                ->where('feature', $feature)
                ->first();
        } catch (QueryException) {
            return null;
        }
    }
}
