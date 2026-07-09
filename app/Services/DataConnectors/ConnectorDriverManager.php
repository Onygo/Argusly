<?php

namespace App\Services\DataConnectors;

use Illuminate\Contracts\Container\Container;

class ConnectorDriverManager
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorProviderKeyResolver $keys,
        private readonly Container $container,
    ) {}

    public function driver(string $provider): ConnectorProviderDriver
    {
        $providerKey = $this->keys->resolve($provider);

        return new GenericOAuthConnectorDriver(
            providerKey: $providerKey,
            registry: $this->registry,
            authorizations: $this->container->make(ConnectorOAuthAuthorizationUrlGenerator::class),
            states: $this->container->make(ConnectorOAuthStateService::class),
            tokens: $this->container->make(ConnectorOAuthTokenManager::class),
            vault: $this->container->make(ConnectorTokenVault::class),
            scopes: $this->container->make(ConnectorScopeSynchronizer::class),
            discovery: $this->container->make(ConnectorDatasetDiscoveryService::class),
            sync: $this->container->make(ConnectorSyncEngine::class),
            healthChecks: $this->container->make(ConnectorHealthCheckService::class),
            health: $this->container->make(ConnectorHealthService::class),
            audit: $this->container->make(ConnectorAuditLogger::class),
        );
    }
}
