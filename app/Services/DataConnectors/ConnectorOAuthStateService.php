<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorOAuthState;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConnectorOAuthStateService
{
    /**
     * @param array<string, mixed> $context
     */
    public function issue(array $context = []): IssuedConnectorOAuthState
    {
        $providerKey = trim((string) ($context['provider_key'] ?? ''));
        if ($providerKey === '') {
            throw new InvalidArgumentException('OAuth state requires a provider_key.');
        }

        $state = $this->randomUrlSafeString(64);
        $nonce = $this->randomUrlSafeString(48);
        $codeVerifier = $this->randomUrlSafeString(96);
        $codeChallenge = $this->codeChallenge($codeVerifier);
        $ttlMinutes = max(1, (int) config('data_connectors.oauth.state_ttl_minutes', 10));

        $record = ConnectorOAuthState::query()->create([
            'state_hash' => $this->hash($state),
            'nonce_hash' => $this->hash($nonce),
            'workspace_id' => $context['workspace_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'connector_provider_id' => $context['connector_provider_id'] ?? null,
            'connector_account_id' => $context['connector_account_id'] ?? null,
            'provider_key' => $providerKey,
            'redirect_uri' => $context['redirect_uri'] ?? null,
            'scopes_json' => array_values(array_filter((array) ($context['scopes'] ?? []), fn ($scope): bool => is_string($scope) && trim($scope) !== '')),
            'pkce_code_verifier' => $codeVerifier,
            'pkce_code_challenge' => $codeChallenge,
            'pkce_code_challenge_method' => 'S256',
            'context_json' => Arr::except($context, [
                'workspace_id',
                'user_id',
                'connector_provider_id',
                'connector_account_id',
                'provider_key',
                'redirect_uri',
                'scopes',
            ]),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return new IssuedConnectorOAuthState($record, $state, $nonce, $codeVerifier, $codeChallenge);
    }

    public function consume(string $state, ?string $nonce = null): ConnectorOAuthState
    {
        $record = ConnectorOAuthState::query()
            ->where('state_hash', $this->hash($state))
            ->first();

        if (! $record instanceof ConnectorOAuthState) {
            throw new InvalidArgumentException('OAuth state is invalid.');
        }

        if ($record->isConsumed()) {
            throw new InvalidArgumentException('OAuth state has already been consumed.');
        }

        if ($record->isExpired()) {
            throw new InvalidArgumentException('OAuth state has expired.');
        }

        if ($nonce !== null && ! hash_equals((string) $record->nonce_hash, $this->hash($nonce))) {
            throw new InvalidArgumentException('OAuth nonce is invalid.');
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return $record->fresh();
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function randomUrlSafeString(int $length): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '='), 0, $length);
    }
}
