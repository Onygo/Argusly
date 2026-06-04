<?php

namespace App\Services;

use App\Models\LlmProvider;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class LlmProviderRegistry
{
    /**
     * @return array<string, array{name: string, base_url: ?string, api_key_env: ?string, settings?: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return config('llm.providers', []);
    }

    public function find(string $provider): LlmProvider
    {
        return LlmProvider::query()
            ->where('provider', $provider)
            ->firstOrFail();
    }

    public function active(string $provider): LlmProvider
    {
        $record = LlmProvider::query()
            ->where('provider', $provider)
            ->where('status', 'active')
            ->first();

        return $record ?? throw new InvalidArgumentException("Unsupported or inactive LLM provider [{$provider}].");
    }

    /**
     * @return Collection<int, LlmProvider>
     */
    public function activeProviders(): Collection
    {
        return LlmProvider::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function envDefinition(string $provider): ?array
    {
        return $this->definitions()[$provider] ?? null;
    }

    public function envFallbackProvider(): array
    {
        $provider = (string) config('llm.default_provider', 'openai');
        $definition = $this->envDefinition($provider);

        if ($definition === null) {
            throw new InvalidArgumentException("Unsupported LLM env fallback provider [{$provider}].");
        }

        return $this->serializeEnvProvider($provider, $definition);
    }

    public function envConfiguredFallbackProvider(): ?array
    {
        $provider = config('llm.fallback_provider');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $definition = $this->envDefinition($provider);

        if ($definition === null) {
            throw new InvalidArgumentException("Unsupported LLM env fallback provider [{$provider}].");
        }

        return $this->serializeEnvProvider($provider, $definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function serializeEnvProvider(string $provider, array $definition): array
    {
        return [
            'provider' => $provider,
            'name' => $definition['name'],
            'base_url' => $definition['base_url'] ?? null,
            'api_key_env' => $definition['api_key_env'] ?? null,
            'status' => 'active',
        ];
    }
}
