<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProviderRegistry
{
    public const SUPPORTED_PROVIDERS = [
        'linkedin',
        'google',
        'wordpress',
        'laravel',
        'meta',
        'x',
        'youtube',
    ];

    /**
     * @return Collection<string, array{name: string, auth_type: string, scopes: array<int, string>}>
     */
    public function definitions(): Collection
    {
        return collect(config('integrations.providers', []))
            ->only(self::SUPPORTED_PROVIDERS)
            ->map(fn (array $definition) => [
                'name' => $definition['name'],
                'auth_type' => $definition['auth_type'],
                'scopes' => $definition['scopes'] ?? [],
            ]);
    }

    /**
     * @return array{name: string, auth_type: string, scopes: array<int, string>}
     */
    public function definition(string $provider): array
    {
        $definition = $this->definitions()->get($provider);

        if (! $definition) {
            throw new InvalidArgumentException("Unsupported integration provider [{$provider}].");
        }

        return $definition;
    }

    public function isSupported(string $provider): bool
    {
        return $this->definitions()->has($provider);
    }

    public function integration(string $provider): Integration
    {
        $this->definition($provider);

        return Integration::query()->where('key', $provider)->firstOrFail();
    }
}
