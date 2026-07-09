<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorOAuthState;
use App\Models\Connectors\ConnectorToken;
use RuntimeException;

class ConnectorOAuthTokenManager
{
    public function __construct(
        private readonly ConnectorOAuthTokenClient $client,
        private readonly ConnectorTokenVault $vault,
        private readonly ConnectorProviderConfigValidator $validator,
    ) {}

    /**
     * @param array<string, mixed> $oauthConfig
     */
    public function exchangeAndStore(ConnectorAccount $account, ConnectorOAuthState $state, string $code, array $oauthConfig): ConnectorToken
    {
        $this->validator->validateOAuthConfig($account->provider_key, $oauthConfig);

        $payload = $this->client->exchangeAuthorizationCode($oauthConfig, $state, $code);

        return $this->vault->storeOAuthResponse($account, $payload, [
            'oauth_state_id' => $state->id,
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * @param array<string, mixed> $oauthConfig
     */
    public function refresh(ConnectorAccount $account, array $oauthConfig): ConnectorToken
    {
        $this->validator->validateOAuthConfig($account->provider_key, $oauthConfig);

        $current = $this->vault->latestFor($account);
        if (! $current instanceof ConnectorToken || trim((string) $current->refresh_token) === '') {
            throw new RuntimeException('Connector account does not have a refresh token.');
        }

        $payload = $this->client->refreshAccessToken($oauthConfig, (string) $current->refresh_token);
        $payload['refresh_token'] = $payload['refresh_token'] ?? $current->refresh_token;

        $token = $this->vault->storeOAuthResponse($account, $payload, [
            'previous_token_id' => $current->id,
            'grant_type' => 'refresh_token',
        ]);

        $this->vault->revoke($current);

        return $token;
    }

    /**
     * @param array<string, mixed> $oauthConfig
     */
    public function revoke(ConnectorToken $token, array $oauthConfig): ConnectorToken
    {
        $this->validator->validateOAuthConfig((string) $token->account?->provider_key, $oauthConfig, requireTokenUrl: false, requireRevokeUrl: false);

        if (trim((string) ($oauthConfig['revoke_url'] ?? '')) !== '') {
            $this->client->revokeToken($oauthConfig, (string) $token->access_token);
        }

        return $this->vault->revoke($token);
    }
}
