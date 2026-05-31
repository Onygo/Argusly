<?php

namespace App\Services\Integrations\LinkedIn;

use App\Data\Integrations\LinkedIn\LinkedInToken;
use App\Models\IntegrationConnection;
use App\Models\SocialProfile;
use App\Services\ActivityLogger;
use App\Services\Signals\SignalManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LinkedInTokenService
{
    public function __construct(
        private readonly LinkedInProvider $provider,
        private readonly ActivityLogger $activity,
        private readonly SignalManager $signals,
    ) {}

    public function isExpired(IntegrationConnection $connection): bool
    {
        return $connection->status === 'expired'
            || ($connection->token_expires_at !== null && $connection->token_expires_at->isPast());
    }

    public function willExpireSoon(IntegrationConnection $connection, int $minutes = 10): bool
    {
        if ($connection->status !== 'active' || $connection->token_expires_at === null) {
            return false;
        }

        return $connection->token_expires_at->lte(now()->addMinutes($minutes));
    }

    public function refreshIfPossible(IntegrationConnection $connection): IntegrationConnection
    {
        $connection->loadMissing(['integration', 'account', 'brand', 'owner']);

        if ($connection->integration?->key !== $this->provider->key()) {
            return $connection;
        }

        if (! $this->isExpired($connection) && ! $this->willExpireSoon($connection)) {
            return $connection;
        }

        if (blank($connection->refresh_token) || ($connection->refresh_expires_at !== null && $connection->refresh_expires_at->isPast())) {
            return $this->markExpired($connection, 'LinkedIn refresh token is unavailable or expired.');
        }

        try {
            $payload = Http::asForm()
                ->acceptJson()
                ->post($this->provider->tokenUrl(), [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                    'client_id' => $this->provider->clientId(),
                    'client_secret' => $this->provider->clientSecret(),
                ])
                ->throw()
                ->json();
        } catch (RequestException) {
            return $this->markExpired($connection, 'LinkedIn token refresh failed.');
        }

        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            return $this->markExpired($connection, 'LinkedIn token refresh returned an invalid response.');
        }

        $token = LinkedInToken::fromOAuthPayload($payload, $connection->scopes ?? $this->provider->scopes());

        $connection->forceFill([
            'status' => 'active',
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? $connection->refresh_token,
            'token_payload' => [
                ...($connection->token_payload ?? []),
                ...$payload,
            ],
            'token_expires_at' => $token->expiresAt,
            'refresh_expires_at' => $token->refreshExpiresAt ?? $connection->refresh_expires_at,
            'metadata' => [
                ...($connection->metadata ?? []),
                'token_health' => 'refreshed',
                'token_health_checked_at' => now()->toIso8601String(),
                'token_error_message' => null,
            ],
        ])->save();

        SocialProfile::query()
            ->where('integration_connection_id', $connection->id)
            ->where('provider', $this->provider->key())
            ->where('status', 'expired')
            ->update(['status' => 'connected']);

        $this->activity->log(
            event: 'linkedin.token_refreshed',
            description: 'LinkedIn access token was refreshed.',
            account: $connection->account,
            brand: $connection->brand,
            user: $connection->owner,
            subject: $connection,
            properties: ['integration_key' => $this->provider->key()],
        );

        return $connection->refresh();
    }

    public function markExpired(IntegrationConnection $connection, string $reason = 'LinkedIn access token expired.'): IntegrationConnection
    {
        return DB::transaction(function () use ($connection, $reason): IntegrationConnection {
            $connection->loadMissing(['integration', 'account', 'brand', 'owner']);

            $connection->forceFill([
                'status' => 'expired',
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'token_health' => 'expired',
                    'token_health_checked_at' => now()->toIso8601String(),
                    'token_error_message' => $reason,
                    'retry_action' => route('settings.integrations.linkedin.connect'),
                ],
            ])->save();

            SocialProfile::query()
                ->where('integration_connection_id', $connection->id)
                ->where('provider', $this->provider->key())
                ->update(['status' => 'expired']);

            $this->activity->log(
                event: 'linkedin.token_expired',
                description: 'LinkedIn profile needs to be reconnected.',
                account: $connection->account,
                brand: $connection->brand,
                user: $connection->owner,
                subject: $connection,
                properties: [
                    'integration_key' => $this->provider->key(),
                    'reason' => $reason,
                ],
            );

            if ($connection->account !== null) {
                $this->signals->record($connection->account, [
                    'source' => 'linkedin_token_health',
                    'type' => 'integration_event',
                    'category' => 'integration',
                    'priority' => 'high',
                    'dedupe_key' => "linkedin-reconnect:{$connection->id}",
                    'title' => 'Reconnect LinkedIn profile',
                    'summary' => $reason,
                    'impact_score' => 76,
                    'confidence_score' => 95,
                    'recommended_action' => 'Reconnect the LinkedIn profile to restore publishing.',
                    'payload' => [
                        'integration_connection_id' => $connection->id,
                        'provider' => $this->provider->key(),
                        'reason' => $reason,
                    ],
                ], $connection->brand);
            }

            return $connection->refresh();
        });
    }

    public function markRevoked(IntegrationConnection $connection, string $reason = 'LinkedIn access was revoked.'): IntegrationConnection
    {
        return DB::transaction(function () use ($connection, $reason): IntegrationConnection {
            $connection->loadMissing(['integration', 'account', 'brand', 'owner']);

            $connection->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
                'access_token' => null,
                'refresh_token' => null,
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'token_health' => 'revoked',
                    'token_health_checked_at' => now()->toIso8601String(),
                    'token_error_message' => $reason,
                    'retry_action' => route('settings.integrations.linkedin.connect'),
                ],
            ])->save();

            SocialProfile::query()
                ->where('integration_connection_id', $connection->id)
                ->where('provider', $this->provider->key())
                ->update(['status' => 'revoked']);

            return $connection->refresh();
        });
    }
}
