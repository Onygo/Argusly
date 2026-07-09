<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorToken;
use Throwable;

class ConnectorHealthCheckService
{
    public function __construct(
        private readonly ConnectorTokenVault $vault,
        private readonly ConnectorAccessTokenService $tokens,
        private readonly ConnectorHealthService $health,
    ) {}

    public function check(ConnectorAccount $account): ConnectorHealthEvent
    {
        if ($account->status === ConnectorAccount::STATUS_DISABLED || $account->status === ConnectorAccount::STATUS_REVOKED) {
            return $this->recordDisabled($account);
        }

        $token = $this->vault->latestFor($account);

        if (! $token instanceof ConnectorToken) {
            return $this->recordReconnect($account, 'Connector account has no stored token.');
        }

        if ($token->revoked_at !== null) {
            return $this->recordReconnect($account, 'Connector token has been revoked.');
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            if (trim((string) $token->refresh_token) === '') {
                return $this->recordExpired($account, 'Connector access token has expired.');
            }

            try {
                $this->tokens->refresh($account);
            } catch (Throwable $exception) {
                return $this->recordReconnect($account, $exception->getMessage());
            }
        }

        if ((string) data_get($account->rate_limit_json, 'remaining') === '0') {
            return $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_WARNING,
                eventType: ConnectorHealthEvent::EVENT_RATE_LIMITED,
                message: 'Provider API rate limit is currently exhausted.',
                context: ['rate_limit' => $account->rate_limit_json],
            );
        }

        $event = $this->health->resolve($account, 'Connector health check passed.', [
            'token_expires_at' => $token->expires_at?->toIso8601String(),
            'last_api_call_at' => $account->last_api_call_at?->toIso8601String(),
        ]);

        $account->forceFill([
            'health_score' => 100,
            'last_error' => null,
        ])->save();

        return $event;
    }

    private function recordDisabled(ConnectorAccount $account): ConnectorHealthEvent
    {
        $account->forceFill(['health_score' => 0])->save();

        return $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_INFO,
            eventType: ConnectorHealthEvent::EVENT_DISABLED,
            message: 'Connector is disabled.',
        );
    }

    private function recordExpired(ConnectorAccount $account, string $message): ConnectorHealthEvent
    {
        $account->forceFill([
            'status' => ConnectorAccount::STATUS_EXPIRED,
            'health_score' => 20,
            'last_error' => $message,
        ])->save();

        return $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_ERROR,
            eventType: ConnectorHealthEvent::EVENT_TOKEN_EXPIRED,
            message: $message,
        );
    }

    private function recordReconnect(ConnectorAccount $account, string $message): ConnectorHealthEvent
    {
        $account->forceFill([
            'status' => ConnectorAccount::STATUS_EXPIRED,
            'health_score' => 10,
            'last_error' => $message,
        ])->save();

        return $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_CRITICAL,
            eventType: ConnectorHealthEvent::EVENT_RECONNECT_REQUIRED,
            message: $message,
        );
    }
}
