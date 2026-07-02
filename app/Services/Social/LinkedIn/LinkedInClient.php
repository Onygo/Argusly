<?php

namespace App\Services\Social\LinkedIn;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    /**
     * @return array{asset:string,upload_url:string,response:array<string,mixed>}
     */
    public function registerImageUpload(string $accessToken, string $ownerUrn): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/assets?action=registerUpload', [
                'registerUploadRequest' => [
                    'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                    'owner' => $ownerUrn,
                    'serviceRelationships' => [[
                        'relationshipType' => 'OWNER',
                        'identifier' => 'urn:li:userGeneratedContent',
                    ]],
                ],
            ]);

        $payload = $response->throw()->json();
        $uploadMechanism = (array) data_get($payload, 'value.uploadMechanism', []);
        $httpRequest = (array) ($uploadMechanism['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest'] ?? []);

        return [
            'asset' => (string) data_get($payload, 'value.asset'),
            'upload_url' => (string) ($httpRequest['uploadUrl'] ?? ''),
            'response' => $payload,
        ];
    }

    /**
     * @return array{asset:string,upload_url:string,register_response:array<string,mixed>,image_status:int,upload_status:int}
     */
    public function uploadImage(string $accessToken, string $ownerUrn, string $imageUrl): array
    {
        $registered = $this->registerImageUpload($accessToken, $ownerUrn);

        if ($registered['asset'] === '' || $registered['upload_url'] === '') {
            throw new \RuntimeException('LinkedIn image upload registration did not return an asset URN.');
        }

        $image = Http::get($imageUrl);
        if ($image->failed()) {
            throw new \RuntimeException('LinkedIn image source could not be fetched.');
        }

        $upload = Http::withBody($image->body(), $this->contentType($imageUrl, $image->header('Content-Type')))
            ->put($registered['upload_url']);
        if ($upload->failed()) {
            throw new \RuntimeException('LinkedIn image upload failed.');
        }

        return [
            'asset' => $registered['asset'],
            'upload_url' => $registered['upload_url'],
            'register_response' => $registered['response'],
            'image_status' => $image->status(),
            'upload_status' => $upload->status(),
        ];
    }

    private function contentType(string $imageUrl, ?string $responseContentType): string
    {
        $contentType = trim((string) $responseContentType);
        if (Str::startsWith($contentType, 'image/')) {
            return $contentType;
        }

        $extension = strtolower((string) pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
