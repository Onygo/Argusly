<?php

namespace App\Services\DataConnectors;

class ConnectorSyncCursor
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(private readonly array $state = [])
    {
    }

    /**
     * @param array<string, mixed>|null $state
     */
    public static function from(?array $state): self
    {
        return new self($state ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }

    public function has(string $key): bool
    {
        return data_get($this->state, $key) !== null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): self
    {
        return new self(array_replace_recursive($this->state, $values));
    }

    public function isEmpty(): bool
    {
        return $this->state === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }
}
