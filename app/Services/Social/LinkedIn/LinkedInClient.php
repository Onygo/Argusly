<?php

namespace App\Services\Social\LinkedIn;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LinkedInClient
{
    public function authorizationUrl(string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => config('services.linkedin.redirect_uri'),
            'state' => $state,
            'scope' => implode(' ', (array) config('services.linkedin.scopes', ['w_member_social'])),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code): array
    {
        return Http::asForm()
            ->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.linkedin.redirect_uri'),
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array{id:string,name:?string,raw:array<string,mixed>}
     */
    public function member(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get('https://api.linkedin.com/v2/userinfo');

        $payload = $response->throw()->json();
        $id = (string) ($payload['sub'] ?? $payload['id'] ?? '');

        return [
            'id' => $id,
            'name' => $payload['name'] ?? trim((string) data_get($payload, 'localizedFirstName').' '.(string) data_get($payload, 'localizedLastName')) ?: null,
            'raw' => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createUgcPost(string $accessToken, array $payload): Response
    {
        return Http::withToken($accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', $payload);
    }
}
