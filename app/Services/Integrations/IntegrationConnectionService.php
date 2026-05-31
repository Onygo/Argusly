<?php

namespace App\Services\Integrations;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use App\Services\OutboxService;
use App\Services\Signals\SignalManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IntegrationConnectionService
{
    /**
     * Create an OAuth connection owned by a user.
     *
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $tokenPayload
     * @param  array<string, mixed>  $metadata
     */
    public function createOAuthConnection(
        User $owner,
        Integration|string $integration,
        string $name,
        ?Account $account = null,
        ?Brand $brand = null,
        array $scopes = [],
        ?string $accessToken = null,
        ?string $refreshToken = null,
        array $tokenPayload = [],
        ?\DateTimeInterface $tokenExpiresAt = null,
        ?\DateTimeInterface $refreshExpiresAt = null,
        ?string $providerAccountId = null,
        ?string $providerAccountName = null,
        array $metadata = [],
    ): IntegrationConnection {
        $integration = $integration instanceof Integration
            ? $integration
            : Integration::query()->where('key', $integration)->firstOrFail();

        if (! $integration->is_active) {
            throw new InvalidArgumentException("Integration [{$integration->key}] is not active.");
        }

        if ($brand && $account && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('The connection brand must belong to the connection account.');
        }

        if ($account && ! $this->userBelongsToAccount($owner, $account)) {
            throw new InvalidArgumentException('The connection owner must be an active member of the connection account.');
        }

        if ($brand && ! $this->userBelongsToBrand($owner, $brand)) {
            throw new InvalidArgumentException('The connection owner must be an active member of the connection brand.');
        }

        return DB::transaction(function () use ($owner, $integration, $name, $account, $brand, $scopes, $accessToken, $refreshToken, $tokenPayload, $tokenExpiresAt, $refreshExpiresAt, $providerAccountId, $providerAccountName, $metadata): IntegrationConnection {
            $connection = IntegrationConnection::query()->create([
                'integration_id' => $integration->id,
                'owner_user_id' => $owner->id,
                'account_id' => $account?->id ?? $brand?->account_id,
                'brand_id' => $brand?->id,
                'name' => $name,
                'status' => 'active',
                'provider_account_id' => $providerAccountId,
                'provider_account_name' => $providerAccountName,
                'scopes' => $scopes,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_payload' => $tokenPayload,
                'token_expires_at' => $tokenExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'metadata' => $metadata,
            ]);

            $connection->permissions()->create([
                'user_id' => $owner->id,
                'permission' => 'manage',
                'granted_by_user_id' => $owner->id,
                'starts_at' => now(),
            ]);

            app(ActivityLogger::class)->log(
                event: 'integration.connected',
                description: "Integration {$integration->name} was connected.",
                account: $account ?? $brand?->account,
                brand: $brand,
                user: $owner,
                subject: $connection,
                properties: [
                    'integration_id' => $integration->id,
                    'integration_key' => $integration->key,
                    'connection_name' => $name,
                ],
            );

            $connection->load(['integration', 'owner', 'permissions']);

            app(SignalManager::class)->produce($connection);

            if ($connection->account_id !== null) {
                app(DomainEventService::class)->recordForSubject('IntegrationConnected', $connection, $owner, [
                    'integration_id' => $integration->id,
                    'integration_key' => $integration->key,
                    'connection_name' => $connection->name,
                    'provider_account_id' => $providerAccountId,
                    'scopes' => $scopes,
                ], $connection->created_at);

                app(OutboxService::class)->enqueue(
                    $connection->account,
                    $connection->brand,
                    'oauth_callback',
                    [
                        'idempotency_key' => "integration-connection:{$connection->id}:oauth-callback",
                        'integration_connection_id' => $connection->id,
                        'integration_id' => $integration->id,
                        'integration_key' => $integration->key,
                        'provider_account_id' => $providerAccountId,
                        'prepared_for_external_call' => true,
                    ],
                );
            }

            return $connection;
        });
    }

    /**
     * Revoke a connection without deleting audit history.
     */
    public function revoke(IntegrationConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $connection->loadMissing(['integration', 'owner', 'account', 'brand']);

            $connection->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'access_token' => null,
                'refresh_token' => null,
                'token_payload' => null,
            ]);

            $connection->permissions()->update([
                'expires_at' => now(),
            ]);

            app(ActivityLogger::class)->log(
                event: 'integration.disconnected',
                description: "Integration {$connection->integration?->name} was disconnected.",
                account: $connection->account,
                brand: $connection->brand,
                user: $connection->owner,
                subject: $connection,
                properties: [
                    'integration_id' => $connection->integration_id,
                    'integration_key' => $connection->integration?->key,
                    'connection_name' => $connection->name,
                ],
            );

            if ($connection->account_id !== null) {
                app(DomainEventService::class)->recordForSubject('IntegrationDisconnected', $connection, $connection->owner, [
                    'integration_id' => $connection->integration_id,
                    'integration_key' => $connection->integration?->key,
                    'connection_name' => $connection->name,
                    'provider_account_id' => $connection->provider_account_id,
                ]);
            }
        });
    }

    private function userBelongsToAccount(User $user, Account $account): bool
    {
        return $user->memberships()
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    private function userBelongsToBrand(User $user, Brand $brand): bool
    {
        return $user->brandMemberships()
            ->where('brand_id', $brand->id)
            ->where('account_id', $brand->account_id)
            ->where('status', 'active')
            ->whereHas('brand', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }
}
