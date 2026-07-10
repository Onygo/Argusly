<?php

namespace App\Services\DataConnectors;

use InvalidArgumentException;

class ConnectorOAuthAuthorizationUrlGenerator
{
    public function __construct(
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorOAuthStateService $states,
        private readonly ConnectorProviderConfigValidator $validator,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $overrides
     */
    public function generate(string $providerKey, array $context = [], array $overrides = []): ConnectorOAuthAuthorization
    {
        $definition = $this->registry->provider($providerKey);
        $oauth = $this->oauthConfig($providerKey, $definition, $overrides);
        $scopes = $this->scopes($definition, $oauth, $context);

        $issued = $this->states->issue(array_merge($context, [
            'provider_key' => $providerKey,
            'redirect_uri' => $oauth['redirect_uri'],
            'scopes' => $scopes,
        ]));

        $params = array_filter([
            'response_type' => (string) ($oauth['response_type'] ?? 'code'),
            'client_id' => (string) $oauth['client_id'],
            'redirect_uri' => (string) $oauth['redirect_uri'],
            'scope' => implode((string) ($oauth['scope_separator'] ?? ' '), $scopes),
            'state' => $issued->state,
            'nonce' => $this->shouldIncludeNonce($oauth, $scopes) ? $issued->nonce : '',
            'code_challenge' => $issued->codeChallenge,
            'code_challenge_method' => 'S256',
        ], fn ($value): bool => $value !== '');

        $params = array_merge($params, (array) ($oauth['authorization_params'] ?? []));
        $separator = str_contains((string) $oauth['authorization_url'], '?') ? '&' : '?';

        return new ConnectorOAuthAuthorization(
            (string) $oauth['authorization_url'].$separator.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            $issued,
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function oauthConfig(string $providerKey, array $definition, array $overrides): array
    {
        $oauth = array_merge((array) data_get($definition, 'config_json.oauth', []), $overrides);

        if ($oauth === []) {
            throw new InvalidArgumentException("Data connector provider [{$providerKey}] does not have OAuth configuration.");
        }

        $this->validator->validateOAuthConfig($providerKey, $oauth, requireTokenUrl: false);

        return $oauth;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $oauth
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function scopes(array $definition, array $oauth, array $context): array
    {
        $scopes = $context['scopes']
            ?? $oauth['scopes']
            ?? data_get($definition, 'config_json.required_scopes', []);

        return array_values(array_filter((array) $scopes, fn ($scope): bool => is_string($scope) && trim($scope) !== ''));
    }

    /**
     * @param array<string, mixed> $oauth
     * @param list<string> $scopes
     */
    private function shouldIncludeNonce(array $oauth, array $scopes): bool
    {
        if (array_key_exists('include_nonce', $oauth)) {
            return (bool) $oauth['include_nonce'];
        }

        return in_array('openid', $scopes, true);
    }
}
