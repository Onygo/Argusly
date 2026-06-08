<?php

namespace App\Services\Llm;

use App\Services\Llm\Providers\MistralProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LlmModelCatalog
{
    public function __construct(private readonly MistralProvider $mistralProvider)
    {
    }

    /**
     * @return array<string,array{all:array<int,string>,text:array<int,string>,image:array<int,string>}>
     */
    public function options(): array
    {
        $providers = array_keys((array) config('llm.providers', []));

        return collect($providers)
            ->mapWithKeys(fn (string $provider): array => [
                $provider => $this->optionsForProvider($provider),
            ])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public function allModelIds(): array
    {
        return collect($this->options())
            ->flatMap(fn (array $options): array => $options['all'])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{all:array<int,string>,text:array<int,string>,image:array<int,string>}
     */
    private function optionsForProvider(string $provider): array
    {
        $models = match ($provider) {
            'openai' => $this->openAiModels(),
            'anthropic' => $this->anthropicModels(),
            'gemini' => $this->geminiModels(),
            'mistral' => $this->mistralModels(),
            default => [],
        };

        $all = $this->sortModelIds(array_values(array_unique(array_merge($models, $this->fallbackModels($provider)))));

        return [
            'all' => $all,
            'text' => $this->sortModelIds(array_values(array_filter($all, fn (string $model): bool => ! $this->looksLikeImageModel($model)))),
            'image' => $this->sortModelIds(array_values(array_filter($all, fn (string $model): bool => $this->looksLikeImageModel($model)))),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function openAiModels(): array
    {
        $apiKey = trim((string) config('llm.providers.openai.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        $baseUrl = rtrim((string) config('llm.providers.openai.base_url', 'https://api.openai.com'), '/');
        $headers = array_filter([
            'OpenAI-Organization' => (string) config('llm.providers.openai.organization', ''),
            'OpenAI-Project' => (string) config('llm.providers.openai.project', ''),
        ]);

        try {
            return Cache::remember('llm:models:openai:' . sha1($baseUrl), now()->addMinutes(30), function () use ($apiKey, $baseUrl, $headers): array {
                $response = Http::withToken($apiKey)
                    ->withHeaders($headers)
                    ->acceptJson()
                    ->timeout(12)
                    ->get($baseUrl . '/v1/models');

                if (! $response->successful()) {
                    return [];
                }

                return collect((array) data_get($response->json(), 'data', []))
                    ->map(fn ($model): string => trim((string) data_get($model, 'id', '')))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    private function anthropicModels(): array
    {
        $apiKey = trim((string) config('llm.providers.anthropic.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        $baseUrl = rtrim((string) config('llm.providers.anthropic.base_url', 'https://api.anthropic.com'), '/');

        try {
            return Cache::remember('llm:models:anthropic:' . sha1($baseUrl), now()->addMinutes(30), function () use ($apiKey, $baseUrl): array {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) config('llm.providers.anthropic.version', '2023-06-01'),
                ])
                    ->acceptJson()
                    ->timeout(12)
                    ->get($baseUrl . '/v1/models');

                if (! $response->successful()) {
                    return [];
                }

                return collect((array) data_get($response->json(), 'data', []))
                    ->map(fn ($model): string => trim((string) data_get($model, 'id', '')))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    private function geminiModels(): array
    {
        $apiKey = trim((string) config('llm.providers.gemini.api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        $baseUrl = rtrim((string) config('llm.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        try {
            return Cache::remember('llm:models:gemini:' . sha1($baseUrl), now()->addMinutes(30), function () use ($apiKey, $baseUrl): array {
                $response = Http::acceptJson()
                    ->timeout(12)
                    ->get($baseUrl . '/models', ['key' => $apiKey]);

                if (! $response->successful()) {
                    return [];
                }

                return collect((array) data_get($response->json(), 'models', []))
                    ->filter(fn ($model): bool => in_array('generateContent', (array) data_get($model, 'supportedGenerationMethods', []), true))
                    ->map(fn ($model): string => Str::after(trim((string) data_get($model, 'name', '')), 'models/'))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    private function mistralModels(): array
    {
        try {
            return $this->mistralProvider->listModels();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    private function fallbackModels(string $provider): array
    {
        $configured = (array) config('llm.providers.' . $provider . '.allowed_models', []);
        $defaults = [
            (string) config('llm.providers.' . $provider . '.default_model', ''),
            (string) config('argusly.ai.images.' . $provider . '.model', ''),
        ];
        $pricing = array_keys((array) config('llm.pricing.model_rates_usd_per_1m.' . $provider, []));

        return collect(array_merge($configured, $defaults, $pricing))
            ->reject(fn (string $model): bool => $model === '' || $model === 'default')
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function sortModelIds(array $models): array
    {
        return collect($models)
            ->map(fn (string $model): string => trim($model))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function looksLikeImageModel(string $model): bool
    {
        return str_contains(strtolower($model), 'image')
            || str_contains(strtolower($model), 'dall-e');
    }
}
