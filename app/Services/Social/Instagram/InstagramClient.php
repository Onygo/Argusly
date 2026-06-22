<?php

namespace App\Services\Social\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class InstagramClient
{
    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.client_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'state' => $state,
            'scope' => implode(',', (array) config('services.meta.scopes', [])),
            'response_type' => 'code',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code): array
    {
        $this->assertConfigured();

        return Http::get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('services.meta.client_id'),
            'client_secret' => config('services.meta.client_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'code' => $code,
        ])->throw()->json();
    }

    /**
     * @return array<string,mixed>
     */
    public function longLivedToken(string $accessToken): array
    {
        return Http::get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.client_id'),
            'client_secret' => config('services.meta.client_secret'),
            'fb_exchange_token' => $accessToken,
        ])->throw()->json();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function instagramAccounts(string $accessToken): array
    {
        $pages = Http::withToken($accessToken)
            ->get($this->graphUrl('/me/accounts'), [
                'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url,account_type}',
            ])
            ->throw()
            ->json('data') ?? [];

        return collect($pages)
            ->map(function (array $page): ?array {
                $instagram = (array) data_get($page, 'instagram_business_account', []);
                $id = trim((string) data_get($instagram, 'id', ''));

                if ($id === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'username' => (string) data_get($instagram, 'username', ''),
                    'name' => (string) data_get($instagram, 'name', data_get($page, 'name', '')),
                    'account_type' => strtolower((string) data_get($instagram, 'account_type', '')),
                    'profile_picture_url' => data_get($instagram, 'profile_picture_url'),
                    'page_id' => (string) data_get($page, 'id', ''),
                    'page_name' => (string) data_get($page, 'name', ''),
                    'page_access_token' => data_get($page, 'access_token'),
                    'raw' => ['page' => $page, 'instagram' => $instagram],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function createMediaContainer(string $accessToken, string $instagramAccountId, string $imageUrl, string $caption): Response
    {
        return Http::withToken($accessToken)->post($this->graphUrl('/'.$instagramAccountId.'/media'), [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]);
    }

    public function publishMedia(string $accessToken, string $instagramAccountId, string $creationId): Response
    {
        return Http::withToken($accessToken)->post($this->graphUrl('/'.$instagramAccountId.'/media_publish'), [
            'creation_id' => $creationId,
        ]);
    }

    private function assertConfigured(): void
    {
        if (! config('services.meta.client_id') || ! config('services.meta.client_secret') || ! config('services.meta.redirect_uri')) {
            throw new InvalidArgumentException('Meta OAuth is not configured. Set META_CLIENT_ID, META_CLIENT_SECRET, and META_REDIRECT_URI.');
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().$path;
    }

    private function version(): string
    {
        return trim((string) config('services.meta.graph_api_version', 'v23.0'), '/');
    }
}
