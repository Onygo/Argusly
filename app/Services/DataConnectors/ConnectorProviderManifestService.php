<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;

class ConnectorProviderManifestService
{
    public function __construct(private readonly DataConnectorRegistry $registry)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(string|ConnectorAccount $provider): array
    {
        $providerKey = $provider instanceof ConnectorAccount ? $provider->provider_key : $provider;
        $definition = $this->registry->provider((string) $providerKey);
        $config = (array) ($definition['config_json'] ?? []);

        return array_merge([
            'auth_type' => ($definition['supports_oauth'] ?? false) ? 'oauth2' : 'none',
            'supported_datasets' => (array) ($config['datasets'] ?? []),
            'sync_modes' => ($definition['supports_sync'] ?? false) ? ['scheduled', 'manual'] : [],
            'rate_limit_model' => 'provider_default',
            'supports_async_reports' => false,
            'supports_webhooks' => (bool) ($definition['supports_webhooks'] ?? false),
            'supports_incremental_sync' => false,
            'required_scopes' => (array) ($config['required_scopes'] ?? []),
        ], (array) ($config['capabilities'] ?? []));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $manifests = [];

        foreach ($this->registry->keys() as $providerKey) {
            $manifests[$providerKey] = $this->manifest($providerKey);
        }

        return $manifests;
    }
}
