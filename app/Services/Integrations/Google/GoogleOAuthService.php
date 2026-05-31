<?php

namespace App\Services\Integrations\Google;

use App\Data\Integrations\Google\GoogleToken;
use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleOAuthService
{
    private const SESSION_KEY = 'oauth.google.states';

    public function __construct(
        private readonly GoogleProvider $provider,
        private readonly GoogleConnectionService $connections,
    ) {}

    public function authorizationUrl(User $user, Account $account, ?Brand $brand = null): string
    {
        $this->assertConfigured();

        $state = Str::random(64);
        $states = session(self::SESSION_KEY, []);
        $states[$state] = [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'scopes' => $this->provider->scopes(),
            'created_at' => now()->timestamp,
        ];

        session()->put(self::SESSION_KEY, $states);

        return $this->provider->authorizationUrl().'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->provider->clientId(),
            'redirect_uri' => $this->provider->redirectUri(),
            'scope' => implode(' ', $this->provider->scopes()),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connectFromCallback(User $user, string $state, string $code): IntegrationConnection
    {
        $statePayload = $this->consumeState($user, $state);

        $account = Account::query()->findOrFail($statePayload['account_id']);
        $brand = isset($statePayload['brand_id']) ? Brand::query()->findOrFail($statePayload['brand_id']) : null;
        $token = $this->exchangeCode($code, $statePayload['scopes']);

        return $this->connections->connect($user, $token, $account, $brand);
    }

    /**
     * @param  array<int, string>  $fallbackScopes
     */
    private function exchangeCode(string $code, array $fallbackScopes): GoogleToken
    {
        $this->assertConfigured();

        try {
            $payload = Http::asForm()
                ->acceptJson()
                ->post($this->provider->tokenUrl(), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->provider->redirectUri(),
                    'client_id' => $this->provider->clientId(),
                    'client_secret' => $this->provider->clientSecret(),
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Google token exchange failed. Please try connecting again.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Google token exchange returned an invalid response.');
        }

        $token = GoogleToken::fromOAuthPayload($payload, $fallbackScopes);

        if (! $token->accessToken) {
            throw new RuntimeException('Google token exchange did not return an access token.');
        }

        return $token;
    }

    /**
     * @return array{user_id: int, account_id: int, brand_id: int|null, scopes: array<int, string>, created_at: int}
     */
    private function consumeState(User $user, string $state): array
    {
        $states = session(self::SESSION_KEY, []);
        $payload = $states[$state] ?? null;

        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $user->id) {
            throw new RuntimeException('Google connection state could not be verified. Please try again.');
        }

        unset($states[$state]);
        session()->put(self::SESSION_KEY, $states);

        if (((int) ($payload['created_at'] ?? 0)) < now()->subMinutes(10)->timestamp) {
            throw new RuntimeException('Google connection state expired. Please try again.');
        }

        return [
            'user_id' => (int) $payload['user_id'],
            'account_id' => (int) $payload['account_id'],
            'brand_id' => isset($payload['brand_id']) ? (int) $payload['brand_id'] : null,
            'scopes' => array_values(array_filter($payload['scopes'] ?? [])),
            'created_at' => (int) $payload['created_at'],
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->provider->oauthConfigured()) {
            throw new RuntimeException('Google OAuth is not configured yet.');
        }
    }
}
