<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorToken;
use Illuminate\Support\Carbon;

class ConnectorTokenVault
{
    /**
     * @param array<string, mixed> $rotationMetadata
     */
    public function store(
        ConnectorAccount $account,
        string $accessToken,
        ?string $refreshToken = null,
        string $tokenType = 'Bearer',
        Carbon|string|null $expiresAt = null,
        array $rotationMetadata = [],
    ): ConnectorToken {
        return $account->tokens()->create([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => $tokenType,
            'expires_at' => $expiresAt,
            'refreshed_at' => now(),
            'rotation_metadata_json' => $rotationMetadata,
        ]);
    }

    public function latestFor(ConnectorAccount $account): ?ConnectorToken
    {
        return $account->tokens()
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    public function revoke(ConnectorToken $token): ConnectorToken
    {
        $token->forceFill(['revoked_at' => now()])->save();

        return $token;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $rotationMetadata
     */
    public function storeOAuthResponse(
        ConnectorAccount $account,
        array $payload,
        array $rotationMetadata = [],
    ): ConnectorToken {
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('OAuth token response did not include an access_token.');
        }

        $expiresAt = null;
        if (isset($payload['expires_at'])) {
            $expiresAt = $payload['expires_at'];
        } elseif (isset($payload['expires_in']) && is_numeric($payload['expires_in'])) {
            $expiresAt = now()->addSeconds(max(0, (int) $payload['expires_in']));
        }

        return $this->store(
            account: $account,
            accessToken: $accessToken,
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            tokenType: (string) ($payload['token_type'] ?? 'Bearer'),
            expiresAt: $expiresAt,
            rotationMetadata: array_merge($rotationMetadata, [
                'scope' => $payload['scope'] ?? null,
                'response_token_type' => $payload['token_type'] ?? null,
            ]),
        );
    }
}
