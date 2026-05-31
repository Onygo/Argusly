<?php

namespace App\Services\Integrations\LinkedIn;

use App\Data\Integrations\LinkedIn\LinkedInAccount;
use App\Data\Integrations\LinkedIn\LinkedInToken;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DomainEventService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LinkedInConnectionService
{
    public function __construct(
        private readonly LinkedInProvider $provider,
        private readonly IntegrationConnectionService $connections,
        private readonly ActivityLogger $activity,
        private readonly DomainEventService $events,
        private readonly SocialProfileService $socialProfiles,
    ) {}

    public function provider(): LinkedInProvider
    {
        return $this->provider;
    }

    public function createPersonalProfileConnection(
        User $owner,
        LinkedInAccount $account,
        LinkedInToken $token,
        ?Account $tenantAccount = null,
        ?Brand $brand = null,
    ): IntegrationConnection {
        $connection = $this->connections->createOAuthConnection(
            owner: $owner,
            integration: $this->provider->key(),
            name: $account->name,
            account: $tenantAccount,
            brand: $brand,
            scopes: $token->scopes ?: $this->provider->scopes(),
            accessToken: $token->accessToken,
            refreshToken: $token->refreshToken,
            tokenPayload: $token->payload,
            tokenExpiresAt: $token->expiresAt,
            refreshExpiresAt: $token->refreshExpiresAt,
            providerAccountId: $account->id,
            providerAccountName: $account->name,
            metadata: [
                'provider' => $this->provider->key(),
                'oauth_implemented' => false,
                'api_calls_enabled' => false,
                'supports_organization_pages_later' => true,
                ...$account->metadata(),
            ],
        );

        $this->activity->log(
            event: 'linkedin.profile_connection.prepared',
            description: 'LinkedIn personal profile connection was prepared.',
            account: $tenantAccount ?? $brand?->account,
            brand: $brand,
            user: $owner,
            subject: $connection,
            properties: [
                'integration_key' => $this->provider->key(),
                'provider_account_id' => $account->id,
                'scopes' => $connection->scopes,
                'oauth_implemented' => false,
            ],
        );

        if ($connection->account_id !== null) {
            $this->events->recordForSubject('LinkedInProfileConnectionPrepared', $connection, $owner, [
                'integration_key' => $this->provider->key(),
                'provider_account_id' => $account->id,
                'scopes' => $connection->scopes,
                'brand_aware' => $connection->brand_id !== null,
                'organization_pages_prepared' => true,
            ]);
        }

        $this->socialProfiles->createFromIntegrationConnection(
            connection: $connection,
            owner: $owner,
            provider: $this->provider->key(),
            displayName: $account->name,
            type: $account->type === 'personal_profile' ? 'person' : $account->type,
            providerProfileId: $account->id,
            profileUrl: $account->profileUrl,
            account: $tenantAccount,
            brand: $brand,
            metadata: [
                'email' => $account->email,
                'organization_id' => $account->organizationId,
                'oauth_implemented' => false,
            ],
        );

        return $connection;
    }

    public function connectPersonalProfile(
        User $owner,
        LinkedInAccount $account,
        LinkedInToken $token,
        Account $tenantAccount,
        ?Brand $brand = null,
    ): IntegrationConnection {
        $this->assertOwnerCanUseTenant($owner, $tenantAccount, $brand);

        return DB::transaction(function () use ($owner, $account, $token, $tenantAccount, $brand): IntegrationConnection {
            $integration = Integration::query()->where('key', $this->provider->key())->firstOrFail();

            $connection = IntegrationConnection::query()
                ->where('integration_id', $integration->id)
                ->where('owner_user_id', $owner->id)
                ->where('account_id', $tenantAccount->id)
                ->when($brand, fn ($query) => $query->where('brand_id', $brand->id), fn ($query) => $query->whereNull('brand_id'))
                ->where('provider_account_id', $account->id)
                ->first();
            $wasCreated = false;

            if ($connection) {
                $connection->update([
                    'name' => $account->name,
                    'status' => 'active',
                    'provider_account_name' => $account->name,
                    'scopes' => $token->scopes ?: $this->provider->scopes(),
                    'access_token' => $token->accessToken,
                    'refresh_token' => $token->refreshToken,
                    'token_payload' => $token->payload,
                    'token_expires_at' => $token->expiresAt,
                    'refresh_expires_at' => $token->refreshExpiresAt,
                    'revoked_at' => null,
                    'metadata' => [
                        ...($connection->metadata ?? []),
                        'provider' => $this->provider->key(),
                        'oauth_implemented' => true,
                        'api_calls_enabled' => true,
                        'profile_fetch_failed' => false,
                        'error_message' => null,
                        'supports_organization_pages_later' => true,
                        ...$account->metadata(),
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
                    name: $account->name,
                    account: $tenantAccount,
                    brand: $brand,
                    scopes: $token->scopes ?: $this->provider->scopes(),
                    accessToken: $token->accessToken,
                    refreshToken: $token->refreshToken,
                    tokenPayload: $token->payload,
                    tokenExpiresAt: $token->expiresAt,
                    refreshExpiresAt: $token->refreshExpiresAt,
                    providerAccountId: $account->id,
                    providerAccountName: $account->name,
                    metadata: [
                        'provider' => $this->provider->key(),
                        'oauth_implemented' => true,
                        'api_calls_enabled' => true,
                        'profile_fetch_failed' => false,
                        'supports_organization_pages_later' => true,
                        ...$account->metadata(),
                    ],
                );
            }

            $this->activity->log(
                event: 'linkedin.profile_connected',
                description: 'LinkedIn personal profile was connected.',
                account: $tenantAccount,
                brand: $brand,
                user: $owner,
                subject: $connection,
                properties: [
                    'integration_key' => $this->provider->key(),
                    'provider_account_id' => $account->id,
                    'scopes' => $connection->scopes,
                    'oauth_implemented' => true,
                ],
            );

            if (! $wasCreated) {
                $this->events->recordForSubject('IntegrationConnected', $connection, $owner, [
                    'integration_id' => $integration->id,
                    'integration_key' => $integration->key,
                    'connection_name' => $connection->name,
                    'provider_account_id' => $account->id,
                    'scopes' => $connection->scopes,
                ]);
            }

            $this->syncPersonalSocialProfile($connection, $owner, $account, $tenantAccount, $brand);

            return $connection->fresh(['integration', 'owner', 'account', 'brand']);
        });
    }

    public function disconnect(IntegrationConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $this->connections->revoke($connection);

            SocialProfile::query()
                ->where('integration_connection_id', $connection->id)
                ->where('provider', $this->provider->key())
                ->update(['status' => 'revoked']);
        });
    }

    public function storeProfileFetchError(
        User $owner,
        LinkedInToken $token,
        Account $tenantAccount,
        ?Brand $brand,
        string $errorMessage,
    ): IntegrationConnection {
        $this->assertOwnerCanUseTenant($owner, $tenantAccount, $brand);

        return DB::transaction(function () use ($owner, $token, $tenantAccount, $brand, $errorMessage): IntegrationConnection {
            $integration = Integration::query()->where('key', $this->provider->key())->firstOrFail();

            $connection = IntegrationConnection::query()->create([
                'integration_id' => $integration->id,
                'owner_user_id' => $owner->id,
                'account_id' => $tenantAccount->id,
                'brand_id' => $brand?->id,
                'name' => 'LinkedIn profile',
                'status' => 'error',
                'provider_account_id' => null,
                'provider_account_name' => null,
                'scopes' => $token->scopes ?: $this->provider->scopes(),
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'token_payload' => $token->payload,
                'token_expires_at' => $token->expiresAt,
                'refresh_expires_at' => $token->refreshExpiresAt,
                'metadata' => [
                    'provider' => $this->provider->key(),
                    'oauth_implemented' => true,
                    'api_calls_enabled' => false,
                    'profile_fetch_failed' => true,
                    'error_message' => $errorMessage,
                    'retry_action' => route('settings.integrations.linkedin.connect'),
                ],
            ]);

            $connection->permissions()->create([
                'user_id' => $owner->id,
                'permission' => 'manage',
                'granted_by_user_id' => $owner->id,
                'starts_at' => now(),
            ]);

            $this->activity->log(
                event: 'linkedin.profile_fetch_failed',
                description: 'LinkedIn token was received, but profile lookup failed.',
                account: $tenantAccount,
                brand: $brand,
                user: $owner,
                subject: $connection,
                properties: [
                    'integration_key' => $this->provider->key(),
                    'scopes' => $connection->scopes,
                    'error_message' => $errorMessage,
                ],
            );

            return $connection;
        });
    }

    private function syncPersonalSocialProfile(
        IntegrationConnection $connection,
        User $owner,
        LinkedInAccount $account,
        Account $tenantAccount,
        ?Brand $brand,
    ): SocialProfile {
        $profile = SocialProfile::query()->updateOrCreate(
            [
                'integration_connection_id' => $connection->id,
                'owner_user_id' => $owner->id,
                'provider' => $this->provider->key(),
                'provider_profile_id' => $account->id,
            ],
            [
                'account_id' => $tenantAccount->id,
                'brand_id' => $brand?->id,
                'display_name' => $account->name,
                'profile_url' => $account->profileUrl,
                'avatar_url' => $account->avatarUrl,
                'type' => 'person',
                'status' => 'connected',
                'metadata' => [
                    'email' => $account->email,
                    'organization_id' => $account->organizationId,
                    'oauth_implemented' => true,
                    'provider_member_id' => $account->id,
                    'raw_profile' => $account->rawProfile,
                ],
            ],
        );

        if ($profile->wasRecentlyCreated) {
            $this->activity->log(
                event: 'social_profile.connected',
                description: "Social profile {$profile->display_name} was connected.",
                account: $tenantAccount,
                brand: $brand,
                user: $owner,
                subject: $profile,
                properties: [
                    'provider' => $this->provider->key(),
                    'type' => 'person',
                    'integration_connection_id' => $connection->id,
                ],
            );

            $this->events->recordForSubject('SocialProfileConnected', $profile, $owner, [
                'provider' => $this->provider->key(),
                'type' => 'person',
                'integration_connection_id' => $connection->id,
            ]);
        }

        $this->socialProfiles->grantOwnerDefaultPermissions($profile, $owner, $tenantAccount, $brand);

        return $profile;
    }

    private function assertOwnerCanUseTenant(User $owner, Account $account, ?Brand $brand): void
    {
        if ($brand && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('The LinkedIn connection brand must belong to the connection account.');
        }

        if (! $owner->memberships()->where('account_id', $account->id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('The LinkedIn connection owner must be an active member of the connection account.');
        }

        if ($brand && ! $owner->brandMemberships()->where('brand_id', $brand->id)->where('account_id', $account->id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('The LinkedIn connection owner must be an active member of the connection brand.');
        }
    }
}
