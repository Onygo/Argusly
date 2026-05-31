<?php

namespace App\Services\Integrations\LinkedIn;

class LinkedInProvider
{
    public const KEY = 'linkedin';

    /**
     * @return array{name: string, auth_type: string, provider?: class-string, scopes: array<int, string>, future_scopes: array<int, string>, oauth: array<string, mixed>, supports: array<string, bool>}
     */
    public function config(): array
    {
        return config('integrations.providers.linkedin');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return $this->config()['name'];
    }

    public function authType(): string
    {
        return $this->config()['auth_type'];
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return $this->config()['scopes'];
    }

    /**
     * @return array<int, string>
     */
    public function futureScopes(): array
    {
        return $this->config()['future_scopes'];
    }

    public function oauthEnabled(): bool
    {
        return (bool) $this->config()['oauth']['enabled'];
    }

    public function oauthConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret()) && filled($this->redirectUri());
    }

    public function authorizationUrl(): string
    {
        return (string) $this->config()['oauth']['authorization_url'];
    }

    public function tokenUrl(): string
    {
        return (string) $this->config()['oauth']['token_url'];
    }

    public function userinfoUrl(): string
    {
        return (string) $this->config()['oauth']['userinfo_url'];
    }

    public function ugcPostsUrl(): string
    {
        return (string) $this->config()['oauth']['ugc_posts_url'];
    }

    public function clientId(): ?string
    {
        return $this->config()['oauth']['client_id'] ?? null;
    }

    public function clientSecret(): ?string
    {
        return $this->config()['oauth']['client_secret'] ?? null;
    }

    public function redirectUri(): ?string
    {
        return $this->config()['oauth']['redirect_uri'] ?? null;
    }

    public function supportsPersonalProfiles(): bool
    {
        return (bool) $this->config()['supports']['personal_profiles'];
    }

    public function supportsOrganizationPages(): bool
    {
        return (bool) $this->config()['supports']['organization_pages'];
    }
}
