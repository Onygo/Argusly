<?php

namespace App\Services\DataConnectors;

use InvalidArgumentException;

class ConnectorProviderConfigValidator
{
    /**
     * @param array<string, array<string, mixed>> $providers
     */
    public function validateAll(array $providers): void
    {
        foreach ($providers as $providerKey => $definition) {
            $this->validateProviderDefinition((string) $providerKey, (array) $definition);
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function validateProviderDefinition(string $providerKey, array $definition): void
    {
        if (trim($providerKey) === '') {
            throw new InvalidArgumentException('Data connector provider keys may not be empty.');
        }

        $declaredKey = trim((string) ($definition['provider_key'] ?? $providerKey));
        if ($declaredKey === '' || $declaredKey !== $providerKey) {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] has a mismatched provider_key.");
        }

        if (trim((string) ($definition['name'] ?? '')) === '') {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] requires a name.");
        }

        $requiredScopes = (array) data_get($definition, 'config_json.required_scopes', []);
        foreach ($requiredScopes as $scope) {
            if (! is_string($scope) || trim($scope) === '') {
                throw new InvalidArgumentException("Data connector provider [{$providerKey}] has an invalid required scope.");
            }
        }

        $adapter = $definition['adapter'] ?? null;
        if ($adapter !== null) {
            $this->validateClass($providerKey, $adapter, DataConnectorAdapter::class, 'adapter');
        }

        $discoveryAdapter = data_get($definition, 'dataset_discovery.adapter')
            ?? $definition['dataset_discovery_adapter']
            ?? null;

        if ($discoveryAdapter !== null) {
            $this->validateClass($providerKey, $discoveryAdapter, ConnectorDatasetDiscoveryAdapter::class, 'dataset discovery adapter');
        }

        $syncAdapter = data_get($definition, 'sync.adapter')
            ?? $definition['sync_adapter']
            ?? null;

        if ($syncAdapter !== null) {
            $this->validateClass($providerKey, $syncAdapter, ConnectorSyncAdapter::class, 'sync adapter');
        }

        $oauth = data_get($definition, 'config_json.oauth');
        if (is_array($oauth)) {
            $this->validateOAuthConfig($providerKey, $oauth, requireClientId: false);
        }
    }

    /**
     * @param array<string, mixed> $oauth
     */
    public function validateOAuthConfig(
        string $providerKey,
        array $oauth,
        bool $requireTokenUrl = true,
        bool $requireRevokeUrl = false,
        bool $requireClientId = true,
    ): void {
        foreach (['authorization_url', 'redirect_uri'] as $key) {
            if (trim((string) ($oauth[$key] ?? '')) === '') {
                throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] requires [{$key}].");
            }
        }

        $clientId = trim((string) ($oauth['client_id'] ?? ''));
        if ($requireClientId && ($clientId === '' || $this->isPlaceholderClientId($providerKey, $clientId))) {
            throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] requires a real [client_id].");
        }

        if ($requireTokenUrl && trim((string) ($oauth['token_url'] ?? '')) === '') {
            throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] requires [token_url].");
        }

        if ($requireRevokeUrl && trim((string) ($oauth['revoke_url'] ?? '')) === '') {
            throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] requires [revoke_url].");
        }

        foreach (['authorization_url', 'token_url', 'revoke_url', 'redirect_uri'] as $key) {
            $value = trim((string) ($oauth[$key] ?? ''));
            if ($value !== '' && ! filter_var($value, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] has an invalid [{$key}].");
            }
        }

        foreach ((array) ($oauth['scopes'] ?? []) as $scope) {
            if (! is_string($scope) || trim($scope) === '') {
                throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] has an invalid scope.");
            }
        }

        if (array_key_exists('authorization_params', $oauth) && ! is_array($oauth['authorization_params'])) {
            throw new InvalidArgumentException("OAuth configuration for [{$providerKey}] requires authorization_params to be an array.");
        }
    }

    private function validateClass(string $providerKey, mixed $class, string $contract, string $label): void
    {
        if (! is_string($class) || trim($class) === '') {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] has an invalid {$label}.");
        }

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] {$label} [{$class}] does not exist.");
        }

        if (! is_subclass_of($class, $contract)) {
            $contractName = class_basename($contract);

            throw new InvalidArgumentException("Data connector provider [{$providerKey}] {$label} [{$class}] must implement {$contractName}.");
        }
    }

    private function isPlaceholderClientId(string $providerKey, string $clientId): bool
    {
        $value = strtolower(trim($clientId));
        $providerSlug = str_replace('_', '-', strtolower($providerKey));
        $knownPlaceholders = [
            $providerSlug.'-client-id',
            'linkedin-analytics-client-id',
        ];

        return in_array($value, $knownPlaceholders, true)
            || str_contains($value, 'placeholder');
    }
}
