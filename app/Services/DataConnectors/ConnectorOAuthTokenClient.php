<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorOAuthState;

interface ConnectorOAuthTokenClient
{
    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(array $oauthConfig, ConnectorOAuthState $state, string $code, array $payload = []): array;

    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function refreshAccessToken(array $oauthConfig, string $refreshToken, array $payload = []): array;

    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function revokeToken(array $oauthConfig, string $token, array $payload = []): array;
}
