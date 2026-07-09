<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorOAuthState;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class HttpConnectorOAuthTokenClient implements ConnectorOAuthTokenClient
{
    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(array $oauthConfig, ConnectorOAuthState $state, string $code, array $payload = []): array
    {
        $this->requireUrl($oauthConfig, 'token_url');

        return $this->post((string) $oauthConfig['token_url'], array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $state->redirect_uri,
            'client_id' => $oauthConfig['client_id'] ?? null,
            'client_secret' => $oauthConfig['client_secret'] ?? null,
            'code_verifier' => $state->pkce_code_verifier,
        ], $payload), $oauthConfig);
    }

    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function refreshAccessToken(array $oauthConfig, string $refreshToken, array $payload = []): array
    {
        $this->requireUrl($oauthConfig, 'token_url');

        return $this->post((string) $oauthConfig['token_url'], array_merge([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $oauthConfig['client_id'] ?? null,
            'client_secret' => $oauthConfig['client_secret'] ?? null,
        ], $payload), $oauthConfig);
    }

    /**
     * @param array<string, mixed> $oauthConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function revokeToken(array $oauthConfig, string $token, array $payload = []): array
    {
        $this->requireUrl($oauthConfig, 'revoke_url');

        return $this->post((string) $oauthConfig['revoke_url'], array_merge([
            'token' => $token,
            'client_id' => $oauthConfig['client_id'] ?? null,
            'client_secret' => $oauthConfig['client_secret'] ?? null,
        ], $payload), $oauthConfig);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $oauthConfig
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload, array $oauthConfig): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(max(1, (int) config('data_connectors.oauth.http_timeout_seconds', 15)))
            ->post($url, array_filter($payload, fn ($value): bool => $value !== null && $value !== ''));

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $oauthConfig
     */
    private function requireUrl(array $oauthConfig, string $key): void
    {
        $url = trim((string) ($oauthConfig[$key] ?? ''));

        if ($url === '') {
            throw new InvalidArgumentException("OAuth configuration is missing [{$key}].");
        }
    }
}
