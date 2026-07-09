<?php

namespace App\Services\DataConnectors;

use InvalidArgumentException;

class ConnectorProviderKeyResolver
{
    public function __construct(private readonly DataConnectorRegistry $registry)
    {
    }

    public function resolve(string $value): string
    {
        $candidate = trim($value);
        $normalized = str_replace('-', '_', $candidate);

        foreach (array_unique([$candidate, $normalized]) as $key) {
            if ($this->registry->has($key)) {
                return $key;
            }
        }

        throw new InvalidArgumentException("Unknown connector provider [{$value}].");
    }

    public function slug(string $providerKey): string
    {
        return str_replace('_', '-', $providerKey);
    }
}
