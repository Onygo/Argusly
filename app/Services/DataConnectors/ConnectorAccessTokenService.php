<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorToken;
use RuntimeException;
use Throwable;

class ConnectorAccessTokenService
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorTokenVault $vault,
        private readonly ConnectorOAuthTokenManager $tokens,
        private readonly ConnectorHealthService $health,
        private readonly ConnectorAuditLogger $audit,
    ) {}

    public function accessToken(ConnectorAccount $account, bool $forceRefresh = false): string
    {
        return (string) $this->validToken($account, $forceRefresh)->access_token;
    }

    public function validToken(ConnectorAccount $account, bool $forceRefresh = false): ConnectorToken
    {
        $token = $this->vault->latestFor($account);

        if (! $token instanceof ConnectorToken || trim((string) $token->access_token) === '') {
            $this->markReconnectRequired($account, 'Connector account does not have an access token.');

            throw new RuntimeException('Connector account does not have an access token.');
        }

        if (! $forceRefresh && ! $this->shouldRefresh($token)) {
            return $token;
        }

        if (trim((string) $token->refresh_token) === '') {
            $this->markExpired($account, 'Connector access token expired and no refresh token is available.');

            throw new RuntimeException('Connector access token expired and no refresh token is available.');
        }

        return $this->refresh($account);
    }

    public function refresh(ConnectorAccount $account): ConnectorToken
    {
        try {
            $token = $this->tokens->refresh($account, $this->oauthConfig($account));
        } catch (Throwable $exception) {
            $this->markReconnectRequired($account, $exception->getMessage());

            throw $exception;
        }

        $account->forceFill([
            'status' => ConnectorAccount::STATUS_CONNECTED,
            'last_error' => null,
            'metadata_json' => array_merge((array) ($account->metadata_json ?? []), [
                'last_token_refresh_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->health->resolve($account, 'Connector token refreshed.', [
            'token_id' => $token->id,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        $this->audit->record($account, 'connector.token_refreshed', null, [
            'workspace_id' => $account->workspace_id,
            'provider_key' => $account->provider_key,
            'token_id' => $token->id,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        return $token;
    }

    public function shouldRefresh(ConnectorToken $token): bool
    {
        return $token->expires_at !== null
            && $token->expires_at->lessThanOrEqualTo(now()->addMinutes(2));
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthConfig(ConnectorAccount $account): array
    {
        return (array) data_get($this->registry->provider($account->provider_key), 'config_json.oauth', []);
    }

    private function markExpired(ConnectorAccount $account, string $message): void
    {
        $account->forceFill([
            'status' => ConnectorAccount::STATUS_EXPIRED,
            'last_error' => $message,
        ])->save();

        $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_ERROR,
            eventType: ConnectorHealthEvent::EVENT_TOKEN_EXPIRED,
            message: $message,
        );
    }

    private function markReconnectRequired(ConnectorAccount $account, string $message): void
    {
        $account->forceFill([
            'status' => ConnectorAccount::STATUS_EXPIRED,
            'last_error' => $message,
        ])->save();

        $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_CRITICAL,
            eventType: ConnectorHealthEvent::EVENT_RECONNECT_REQUIRED,
            message: $message,
        );
    }
}
