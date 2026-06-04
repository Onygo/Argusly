<?php

namespace App\Services;

use App\Models\LlmModel;
use App\Models\LlmProvider;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class LlmModelRegistry
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function definitions(): array
    {
        return [
            'openai' => [
                $this->chat('gpt-4.1', 'GPT-4.1', 1047576, true, true, true),
                $this->chat('gpt-4.1-mini', 'GPT-4.1 mini', 1047576, true, true, true),
                $this->chat('gpt-4o', 'GPT-4o', 128000, true, true, true),
                $this->embedding('text-embedding-3-small', 'Text embedding 3 small', 8191),
            ],
            'anthropic' => [
                $this->chat('claude-opus-4-20250514', 'Claude Opus 4', 200000, true, true, true),
                $this->chat('claude-sonnet-4-20250514', 'Claude Sonnet 4', 200000, true, true, true),
                $this->chat('claude-3-5-haiku-20241022', 'Claude 3.5 Haiku', 200000, true, true, true),
            ],
            'google' => [
                $this->chat('gemini-2.5-pro', 'Gemini 2.5 Pro', 1048576, true, true, true),
                $this->chat('gemini-2.5-flash', 'Gemini 2.5 Flash', 1048576, true, true, true),
                $this->embedding('text-embedding-004', 'Text embedding 004', 2048),
            ],
            'mistral' => [
                $this->chat('mistral-large-latest', 'Mistral Large', 128000, true, true, false),
                $this->chat('mistral-small-latest', 'Mistral Small', 32000, true, true, false),
                $this->embedding('mistral-embed', 'Mistral Embed', 8192),
            ],
            'groq' => [
                $this->chat('llama-3.3-70b-versatile', 'Llama 3.3 70B Versatile', 128000, true, true, false),
                $this->chat('openai/gpt-oss-120b', 'GPT OSS 120B', 131072, true, true, false),
            ],
            'openrouter' => [
                $this->chat('openai/gpt-4.1-mini', 'OpenAI GPT-4.1 mini via OpenRouter', 1047576, true, true, true),
                $this->chat('anthropic/claude-sonnet-4', 'Claude Sonnet 4 via OpenRouter', 200000, true, true, true),
                $this->chat('google/gemini-2.5-pro', 'Gemini 2.5 Pro via OpenRouter', 1048576, true, true, true),
            ],
        ];
    }

    public function find(LlmProvider $provider, string $model): LlmModel
    {
        $record = LlmModel::query()
            ->where('provider_id', $provider->id)
            ->where('model', $model)
            ->first();

        return $record ?? throw new InvalidArgumentException("Unsupported LLM model [{$model}] for provider [{$provider->provider}].");
    }

    public function activeForType(LlmProvider $provider, string $type = 'chat'): LlmModel
    {
        $record = LlmModel::query()
            ->where('provider_id', $provider->id)
            ->where('type', $type)
            ->active()
            ->orderBy('id')
            ->first();

        return $record ?? throw new InvalidArgumentException("No active {$type} LLM model exists for provider [{$provider->provider}].");
    }

    /**
     * @return Collection<int, LlmModel>
     */
    public function modelsForProvider(LlmProvider $provider, ?string $type = null): Collection
    {
        return LlmModel::query()
            ->where('provider_id', $provider->id)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function envFallbackModel(): array
    {
        return [
            'model' => (string) config('llm.default_model', 'gpt-4.1-mini'),
            'name' => (string) config('llm.default_model', 'gpt-4.1-mini'),
            'type' => 'chat',
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function envConfiguredFallbackModel(): ?array
    {
        $model = config('llm.fallback_model');

        if (! is_string($model) || $model === '') {
            return null;
        }

        return [
            'model' => $model,
            'name' => $model,
            'type' => 'chat',
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chat(string $model, string $name, ?int $contextWindow, bool $json, bool $tools, bool $vision): array
    {
        return [
            'model' => $model,
            'name' => $name,
            'type' => 'chat',
            'context_window' => $contextWindow,
            'supports_json' => $json,
            'supports_tools' => $tools,
            'supports_vision' => $vision,
            'supports_streaming' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function embedding(string $model, string $name, ?int $contextWindow): array
    {
        return [
            'model' => $model,
            'name' => $name,
            'type' => 'embedding',
            'context_window' => $contextWindow,
            'supports_json' => false,
            'supports_tools' => false,
            'supports_vision' => false,
            'supports_streaming' => false,
        ];
    }
}
