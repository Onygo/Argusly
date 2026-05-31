<?php

namespace App\Services\Integrations\Google;

use App\Data\Integrations\Google\GoogleToken;
use App\Models\Ga4Property;
use App\Models\IntegrationConnection;
use App\Models\SearchConsoleSite;
use App\Services\ActivityLogger;
use App\Services\Signals\SignalManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleTokenService
{
    public function __construct(
        private readonly GoogleProvider $provider,
        private readonly ActivityLogger $activity,
        private readonly SignalManager $signals,
    ) {}

    public function isExpired(IntegrationConnection $connection): bool
    {
        return in_array($connection->status, ['expired', 'revoked'], true)
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

        if ($connection->status === 'revoked') {
            return $connection;
        }

        if (! $this->isExpired($connection) && ! $this->willExpireSoon($connection)) {
            return $connection;
        }

        if (blank($connection->refresh_token)) {
            return $this->markExpired($connection, 'Google refresh token is unavailable.');
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
            return $this->markExpired($connection, 'Google token refresh failed.');
        }

        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            return $this->markExpired($connection, 'Google token refresh returned an invalid response.');
        }

        $token = GoogleToken::fromOAuthPayload($payload, $connection->scopes ?? $this->provider->scopes());

        $connection->forceFill([
            'status' => 'active',
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? $connection->refresh_token,
            'token_payload' => [
                ...($connection->token_payload ?? []),
                ...$payload,
            ],
            'token_expires_at' => $token->expiresAt,
            'metadata' => [
                ...($connection->metadata ?? []),
                'token_health' => 'refreshed',
                'token_health_checked_at' => now()->toIso8601String(),
                'token_error_message' => null,
            ],
        ])->save();

        $connection->sourceConnections()
            ->where('status', 'error')
            ->update(['status' => 'configured']);

        $this->activity->log(
            event: 'google.token_refreshed',
            description: 'Google access token was refreshed.',
            account: $connection->account,
            brand: $connection->brand,
            user: $connection->owner,
            subject: $connection,
            properties: ['integration_key' => $this->provider->key()],
        );

        return $connection->refresh();
    }

    public function markExpired(IntegrationConnection $connection, string $reason = 'Google access token expired.'): IntegrationConnection
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
                    'retry_action' => route('settings.integrations.google.connect'),
                ],
            ])->save();

            $connection->sourceConnections()->update(['status' => 'error']);

            Ga4Property::query()
                ->where('integration_connection_id', $connection->id)
                ->whereIn('status', ['connected', 'syncing'])
                ->get()
                ->each(fn (Ga4Property $property) => $property->forceFill([
                    'status' => 'error',
                    'metadata' => [
                        ...($property->metadata ?? []),
                        'token_error_message' => $reason,
                        'token_health_checked_at' => now()->toIso8601String(),
                    ],
                ])->save());

            SearchConsoleSite::query()
                ->where('integration_connection_id', $connection->id)
                ->whereIn('status', ['connected', 'syncing'])
                ->get()
                ->each(fn (SearchConsoleSite $site) => $site->forceFill([
                    'status' => 'error',
                    'metadata' => [
                        ...($site->metadata ?? []),
                        'token_error_message' => $reason,
                        'token_health_checked_at' => now()->toIso8601String(),
                    ],
                ])->save());

            $this->activity->log(
                event: 'google.token_expired',
                description: 'Google connection needs to be reconnected.',
                account: $connection->account,
                brand: $connection->brand,
                user: $connection->owner,
                subject: $connection,
                properties: [
                    'integration_key' => $this->provider->key(),
                    'reason' => $reason,
                ],
            );

            $this->recordReconnectSignal($connection, $reason);

            return $connection->refresh();
        });
    }

    public function markRevoked(IntegrationConnection $connection, string $reason = 'Google access was revoked.'): IntegrationConnection
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
                    'retry_action' => route('settings.integrations.google.connect'),
                ],
            ])->save();

            $connection->sourceConnections()->update(['status' => 'paused']);

            Ga4Property::query()
                ->where('integration_connection_id', $connection->id)
                ->whereIn('status', ['connected', 'syncing'])
                ->update(['status' => 'error']);

            SearchConsoleSite::query()
                ->where('integration_connection_id', $connection->id)
                ->whereIn('status', ['connected', 'syncing'])
                ->update(['status' => 'error']);

            $this->recordReconnectSignal($connection, $reason);

            return $connection->refresh();
        });
    }

    private function recordReconnectSignal(IntegrationConnection $connection, string $reason): void
    {
        if ($connection->account === null) {
            return;
        }

        $this->signals->record($connection->account, [
            'source' => 'google_token_health',
            'type' => 'integration_event',
            'category' => 'integration',
            'priority' => 'high',
            'dedupe_key' => "google-reconnect:{$connection->id}",
            'title' => 'Reconnect Google integration',
            'summary' => $reason,
            'impact_score' => 78,
            'confidence_score' => 95,
            'recommended_action' => 'Reconnect Google to restore GA4 and Search Console sync.',
            'payload' => [
                'integration_connection_id' => $connection->id,
                'provider' => $this->provider->key(),
                'reason' => $reason,
            ],
        ], $connection->brand);
    }
}
