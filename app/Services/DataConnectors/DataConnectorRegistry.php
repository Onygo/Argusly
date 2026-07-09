<?php

namespace App\Services\DataConnectors;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

class DataConnectorRegistry
{
    /**
     * @param array<string, array<string, mixed>> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly Container $container,
        private readonly ?ConnectorProviderConfigValidator $validator = null,
    ) {
        ($this->validator ?? new ConnectorProviderConfigValidator)->validateAll($providers);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $providerKey): bool
    {
        return array_key_exists($providerKey, $this->providers);
    }

    /**
     * @return array<string, mixed>
     */
    public function provider(string $providerKey): array
    {
        if (! $this->has($providerKey)) {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] is not configured.");
        }

        return $this->providers[$providerKey];
    }

    public function adapter(string $providerKey): DataConnectorAdapter
    {
        $definition = $this->provider($providerKey);
        $adapterClass = $definition['adapter'] ?? null;

        if (! is_string($adapterClass) || trim($adapterClass) === '') {
            throw new RuntimeException("Data connector provider [{$providerKey}] does not have an adapter configured.");
        }

        $adapter = $this->container->make($adapterClass);

        if (! $adapter instanceof DataConnectorAdapter) {
            throw new RuntimeException("Data connector adapter [{$adapterClass}] must implement DataConnectorAdapter.");
        }

        return $adapter;
    }

    public function syncAdapter(string $providerKey): ConnectorSyncAdapter
    {
        $definition = $this->provider($providerKey);
        $adapterClass = data_get($definition, 'sync.adapter')
            ?? $definition['sync_adapter']
            ?? null;

        if (! is_string($adapterClass) || trim($adapterClass) === '') {
            throw new RuntimeException("Data connector provider [{$providerKey}] does not have a sync adapter configured.");
        }

        $adapter = $this->container->make($adapterClass);

        if (! $adapter instanceof ConnectorSyncAdapter) {
            throw new RuntimeException("Data connector sync adapter [{$adapterClass}] must implement ConnectorSyncAdapter.");
        }

        return $adapter;
    }

    public function datasetDiscoveryAdapter(string $providerKey): ConnectorDatasetDiscoveryAdapter
    {
        $definition = $this->provider($providerKey);
        $adapterClass = data_get($definition, 'dataset_discovery.adapter')
            ?? $definition['dataset_discovery_adapter']
            ?? $definition['adapter']
            ?? null;

        if (! is_string($adapterClass) || trim($adapterClass) === '') {
            throw new RuntimeException("Data connector provider [{$providerKey}] does not have a dataset discovery adapter configured.");
        }

        $adapter = $this->container->make($adapterClass);

        if (! $adapter instanceof ConnectorDatasetDiscoveryAdapter) {
            throw new RuntimeException("Dataset discovery adapter [{$adapterClass}] must implement ConnectorDatasetDiscoveryAdapter.");
        }

        return $adapter;
    }
}
