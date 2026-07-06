<?php

namespace App\Services\PageIntelligence\Geo;

use InvalidArgumentException;

class AnswerEngineProviderRegistry
{
    /**
     * @param array<string,AnswerEngineAdapter> $providers
     */
    public function __construct(private array $providers = [])
    {
    }

    public function register(string $key, AnswerEngineAdapter $provider): void
    {
        $this->providers[$this->normalizeKey($key)] = $provider;
    }

    public function has(string $key): bool
    {
        return array_key_exists($this->normalizeKey($key), $this->providers);
    }

    public function get(string $key): AnswerEngineAdapter
    {
        $key = $this->normalizeKey($key);

        if (! $this->has($key)) {
            throw new InvalidArgumentException('Unknown answer engine provider: '.$key);
        }

        return $this->providers[$key];
    }

    /**
     * @return array<string,AnswerEngineAdapter>
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
