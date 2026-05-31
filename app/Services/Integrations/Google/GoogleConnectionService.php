<?php

namespace App\Services\Integrations\Google;

use App\Data\Integrations\Google\GoogleToken;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use App\Services\Integrations\IntegrationConnectionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GoogleConnectionService
{
    public function __construct(
        private readonly GoogleProvider $provider,
        private readonly IntegrationConnectionService $connections,
        private readonly ActivityLogger $activity,
        private readonly DomainEventService $events,
    ) {}

    public function connect(
        User $owner,
        GoogleToken $token,
        Account $account,
        ?Brand $brand = null,
    ): IntegrationConnection {
        $this->assertOwnerCanUseTenant($owner, $account, $brand);

        return DB::transaction(function () use ($owner, $token, $account, $brand): IntegrationConnection {
            $integration = Integration::query()->where('key', $this->provider->key())->firstOrFail();

            $connection = IntegrationConnection::query()
                ->where('integration_id', $integration->id)
                ->where('owner_user_id', $owner->id)
                ->where('account_id', $account->id)
                ->when($brand, fn ($query) => $query->where('brand_id', $brand->id), fn ($query) => $query->whereNull('brand_id'))
                ->first();

            $wasCreated = false;
            $name = $brand ? "Google for {$brand->name}" : "Google for {$account->name}";

            if ($connection) {
                $connection->update([
                    'name' => $name,
                    'status' => 'active',
                    'scopes' => $token->scopes ?: $this->provider->scopes(),
                    'access_token' => $token->accessToken,
                    'refresh_token' => $token->refreshToken ?? $connection->refresh_token,
                    'token_payload' => [
                        ...($connection->token_payload ?? []),
                        ...$token->payload,
                    ],
                    'token_expires_at' => $token->expiresAt,
                    'revoked_at' => null,
                    'metadata' => [
                        ...($connection->metadata ?? []),
                        'provider' => $this->provider->key(),
                        'oauth_implemented' => true,
                        'api_calls_enabled' => true,
                        'offline_access_requested' => true,
                        'refresh_token_returned' => filled($token->refreshToken),
                        'token_error_message' => null,
                    ],
                ]);

                $connection->permissions()->updateOrCreate(
                    ['user_id' => $owner->id, 'permission' => 'manage'],
                    ['granted_by_user_id' => $owner->id, 'starts_at' => now(), 'expires_at' => null],
                );
            } else {
                $wasCreated = true;
                $connection = $this->connections->createOAuthConnection(
                    owner: $owner,
                    integration: $integration,
                    name: $name,
                    account: $account,
                    brand: $brand,
                    scopes: $token->scopes ?: $this->provider->scopes(),
                    accessToken: $token->accessToken,
                    refreshToken: $token->refreshToken,
                    tokenPayload: $token->payload,
                    tokenExpiresAt: $token->expiresAt,
                    providerAccountId: null,
                    providerAccountName: $name,
                    metadata: [
                        'provider' => $this->provider->key(),
                        'oauth_implemented' => true,
                        'api_calls_enabled' => true,
                        'offline_access_requested' => true,
                        'refresh_token_returned' => filled($token->refreshToken),
                    ],
                );
            }

            $this->syncSources($connection, $account, $brand);

            $this->activity->log(
                event: 'google.oauth_connected',
                description: 'Google OAuth connection was completed.',
                account: $account,
                brand: $brand,
                user: $owner,
                subject: $connection,
                properties: [
                    'integration_key' => $this->provider->key(),
                    'scopes' => $connection->scopes,
                    'refresh_token_returned' => filled($token->refreshToken),
                ],
            );

            if (! $wasCreated) {
                $this->events->recordForSubject('IntegrationConnected', $connection, $owner, [
                    'integration_id' => $integration->id,
                    'integration_key' => $integration->key,
                    'connection_name' => $connection->name,
                    'provider_account_id' => $connection->provider_account_id,
                    'scopes' => $connection->scopes,
                ]);
            }

            return $connection->fresh(['integration', 'owner', 'account', 'brand', 'sourceConnections.source']);
        });
    }

    public function disconnect(IntegrationConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $this->connections->revoke($connection);

            $connection->sourceConnections()->update([
                'status' => 'paused',
            ]);
        });
    }

    private function syncSources(IntegrationConnection $connection, Account $account, ?Brand $brand): void
    {
        foreach ($this->sourceDefinitions() as $definition) {
            $source = Source::query()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'brand_id' => $brand?->id,
                    'provider' => $this->provider->key(),
                    'name' => $definition['name'],
                ],
                [
                    'type' => 'search',
                    'status' => 'active',
                    'metadata' => [
                        'oauth_connection_id' => $connection->id,
                        'required_scope' => $definition['scope'],
                        'sync_implementation' => 'planned',
                    ],
                ],
            );

            $source->connections()->updateOrCreate(
                ['integration_connection_id' => $connection->id],
                [
                    'status' => 'configured',
                    'settings' => [
                        'sync_enabled' => false,
                        'required_scope' => $definition['scope'],
                    ],
                ],
            );
        }
    }

    /**
     * @return array<int, array{name: string, scope: string}>
     */
    private function sourceDefinitions(): array
    {
        return [
            [
                'name' => 'Google Analytics 4',
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            ],
            [
                'name' => 'Google Search Console',
                'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            ],
        ];
    }

    private function assertOwnerCanUseTenant(User $owner, Account $account, ?Brand $brand): void
    {
        if ($brand && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('The Google connection brand must belong to the connection account.');
        }

        if (! $owner->memberships()->where('account_id', $account->id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('The Google connection owner must be an active member of the connection account.');
        }

        if ($brand && ! $owner->brandMemberships()->where('brand_id', $brand->id)->where('account_id', $account->id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('The Google connection owner must be an active member of the connection brand.');
        }
    }
}
