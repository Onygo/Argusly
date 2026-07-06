<?php

namespace App\Services\PageIntelligence\Serp;

use InvalidArgumentException;

class SerpProviderRegistry
{
    /**
     * @param array<string,SerpProviderAdapter> $providers
     */
    public function __construct(private array $providers = [])
    {
    }

    public function register(string $key, SerpProviderAdapter $provider): void
    {
        $this->providers[$this->normalizeKey($key)] = $provider;
    }

    public function has(string $key): bool
    {
        return array_key_exists($this->normalizeKey($key), $this->providers);
    }

    public function get(string $key): SerpProviderAdapter
    {
        $key = $this->normalizeKey($key);

        if (! $this->has($key)) {
            throw new InvalidArgumentException('Unknown SERP provider: '.$key);
        }

        return $this->providers[$key];
    }

    /**
     * @return array<string,SerpProviderAdapter>
     */
    public function all(): array
    {
        return $this->providers;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
