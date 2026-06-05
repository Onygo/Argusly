<?php

namespace App\Services\Social\LinkedIn;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Http\Client\Response;

class LinkedInPublisher
{
    public function __construct(private readonly LinkedInClient $client) {}

    /**
     * @return array{payload:array<string,mixed>,response:Response,provider_post_id:?string}
     */
    public function publish(SocialPost $post): array
    {
        $account = $post->account;

        if (! $account instanceof SocialAccount || ! $account->isConnected()) {
            throw new \RuntimeException('LinkedIn account is not connected.');
        }

        $payload = $this->payload($post, $account);
        $response = $this->client->createUgcPost((string) $account->access_token, $payload);

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->body() ?: 'LinkedIn publish failed.');
            throw new LinkedInPublishException($message, $response->status(), $payload, $response->json() ?? []);
        }

        return [
            'payload' => $payload,
            'response' => $response,
            'provider_post_id' => $response->header('X-RestLi-Id'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(SocialPost $post, SocialAccount $account): array
    {
        $mediaCategory = $post->type === 'article' || filled($post->url) ? 'ARTICLE' : 'NONE';
        $media = [];

        if ($mediaCategory === 'ARTICLE') {
            $media[] = [
                'status' => 'READY',
                'originalUrl' => $post->url,
                'title' => ['text' => $post->title ?: $post->content?->title ?: 'Article'],
                'description' => ['text' => $post->description ?: ''],
            ];
        }

        $content = [
            'shareCommentary' => ['text' => $post->body],
            'shareMediaCategory' => $mediaCategory,
        ];

        if ($media !== []) {
            $content['media'] = $media;
        }

        $author = $account->provider_member_urn ?: $account->platform_account_id;
        if (! is_string($author) || trim($author) === '') {
            throw new \RuntimeException('LinkedIn account author URN is missing.');
        }

        return [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $content,
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => $post->visibility === 'connections' ? 'CONNECTIONS' : 'PUBLIC',
            ],
        ];
    }
}
