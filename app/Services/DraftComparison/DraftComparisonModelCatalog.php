<?php

namespace App\Services\DraftComparison;

use App\Services\Llm\LlmRoutingService;
use Illuminate\Support\Str;

class DraftComparisonModelCatalog
{
    public function __construct(
        private readonly LlmRoutingService $llmRoutingService,
    ) {}

    /**
     * @return array<int, array{key:string,provider:string,provider_label:string,model:string,label:string}>
     */
    public function options(): array
    {
        $global = $this->llmRoutingService->getGlobalSettings();
        $globalModelMap = (array) ($global['default_text_model_map'] ?? []);

        $options = collect($this->textProviders())
            ->map(function (string $provider) use ($globalModelMap): ?array {
                $model = trim((string) ($globalModelMap[$provider] ?? config('llm.providers.' . $provider . '.default_model', '')));
                if ($model === '' || ! $this->isValidModel($model)) {
                    return null;
                }

                return $this->buildOption($provider, $model);
            })
            ->filter()
            ->values();

        return $options->all();
    }

    /**
     * @return array<int, string>
     */
    public function optionKeys(): array
    {
        return collect($this->options())
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $keys
     * @return array<int, array{key:string,provider:string,provider_label:string,model:string,label:string}>
     */
    public function resolveSelections(array $keys): array
    {
        $byKey = collect($this->options())->keyBy('key');

        return collect($keys)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->map(function (string $key) use ($byKey): ?array {
                $resolved = $byKey->get($key);
                if (is_array($resolved)) {
                    return $resolved;
                }

                [$provider, $model] = $this->parseCompositeKey($key);
                if ($provider === null || $model === null) {
                    return null;
                }

                if (! in_array($provider, $this->textProviders(), true) || ! $this->isValidModel($model)) {
                    return null;
                }

                return $this->buildOption($provider, $model);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function textProviders(): array
    {
        return collect((array) config('llm.capabilities', []))
            ->filter(fn (mixed $capabilities): bool => in_array('text', (array) $capabilities, true))
            ->keys()
            ->values()
            ->all();
    }

    private function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            default => Str::headline($provider),
        };
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function parseCompositeKey(string $key): array
    {
        if (! str_contains($key, ':')) {
            return [null, null];
        }

        [$provider, $model] = explode(':', $key, 2);
        $provider = strtolower(trim($provider));
        $model = trim($model);

        if ($provider === '' || $model === '') {
            return [null, null];
        }

        return [$provider, $model];
    }

    /**
     * @return array{key:string,provider:string,provider_label:string,model:string,label:string}
     */
    private function buildOption(string $provider, string $model): array
    {
        $providerLabel = $this->providerLabel($provider);

        return [
            'key' => $provider . ':' . $model,
            'provider' => $provider,
            'provider_label' => $providerLabel,
            'model' => $model,
            'label' => $providerLabel . ' - ' . $model,
        ];
    }

    private function isValidModel(string $model): bool
    {
        return strlen($model) <= 120 && preg_match('/^[A-Za-z0-9._:-]+$/', $model) === 1;
    }
}
