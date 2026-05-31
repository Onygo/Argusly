<?php

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\User;
use InvalidArgumentException;

class ConnectionManager
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly IntegrationConnectionService $connections,
    ) {}

    /**
     * @param  array<int, string>|null  $scopes
     * @param  array<string, mixed>  $metadata
     */
    public function createRuntimeConnection(
        User $owner,
        string $provider,
        string $name,
        ?Account $account = null,
        ?Brand $brand = null,
        ?array $scopes = null,
        array $metadata = [],
    ): IntegrationConnection {
        if (! $this->integrations->providerEnabled($provider)) {
            throw new InvalidArgumentException("Integration provider [{$provider}] is not enabled.");
        }

        if ($account && ! $this->integrations->checkAccountAccess($owner, $account)) {
            throw new InvalidArgumentException('The connection owner must be able to access the connection account.');
        }

        if ($brand && ! $this->integrations->checkBrandAccess($owner, $brand)) {
            throw new InvalidArgumentException('The connection owner must be able to access the connection brand.');
        }

        $integration = app(ProviderRegistry::class)->integration($provider);

        return $this->connections->createOAuthConnection(
            owner: $owner,
            integration: $integration,
            name: $name,
            account: $account,
            brand: $brand,
            scopes: $scopes ?? $integration->default_scopes ?? [],
            metadata: [
                'runtime' => true,
                'oauth_implemented' => false,
                'api_calls_enabled' => false,
                ...$metadata,
            ],
        );
    }

    public function disableConnection(IntegrationConnection $connection): void
    {
        $this->connections->revoke($connection);
    }

    public function canUse(User $user, IntegrationConnection $connection, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->integrations->validatePermissions($user, $connection, 'use', $account, $brand);
    }

    public function canManage(User $user, IntegrationConnection $connection, ?Account $account = null, ?Brand $brand = null): bool
    {
        return $this->integrations->validatePermissions($user, $connection, 'manage', $account, $brand);
    }
}
