<?php

namespace App\Services\Social\LinkedIn;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Http\Client\Response;

class LinkedInPublisher
{
    public function __construct(private readonly LinkedInClient $client) {}

    /**
     * @return array{payload:array<string,mixed>,response:Response,provider_post_id:?string,image:array<string,mixed>}
     */
    public function publish(SocialPost $post): array
    {
        $account = $post->account;

        if (! $account instanceof SocialAccount || ! $account->isConnected()) {
            throw new \RuntimeException('LinkedIn account is not connected.');
        }

        $author = $this->authorUrn($account);
        $image = $this->uploadResolvedImage($post, $account, $author);
        $payload = $this->payload($post, $account, (string) ($image['asset'] ?? ''));
        $response = $this->client->createUgcPost((string) $account->access_token, $payload);

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->body() ?: 'LinkedIn publish failed.');
            throw new LinkedInPublishException($message, $response->status(), [
                'payload' => $payload,
                'linkedin_image' => $image,
            ], $response->json() ?? []);
        }

        $this->storeImageMetadata($post, $image);

        return [
            'payload' => $payload,
            'response' => $response,
            'provider_post_id' => $response->header('X-RestLi-Id'),
            'image' => $image,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(SocialPost $post, SocialAccount $account, ?string $imageUrn = null): array
    {
        $imageUrn = trim((string) $imageUrn);
        $mediaCategory = $imageUrn !== '' ? 'IMAGE' : ($post->type === 'article' || filled($post->url) ? 'ARTICLE' : 'NONE');
        $media = [];

        if ($mediaCategory === 'IMAGE') {
            $item = [
                'status' => 'READY',
                'media' => $imageUrn,
                'title' => ['text' => $post->title ?: $post->content?->title ?: 'Article'],
                'description' => ['text' => $post->description ?: ''],
            ];

            if (filled($post->url)) {
                $item['originalUrl'] = $post->url;
            }

            $media[] = $item;
        } elseif ($mediaCategory === 'ARTICLE') {
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

        return [
            'author' => $this->authorUrn($account),
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $content,
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => $post->visibility === 'connections' ? 'CONNECTIONS' : 'PUBLIC',
            ],
        ];
    }

    private function authorUrn(SocialAccount $account): string
    {
        $author = $account->provider_member_urn ?: $account->platform_account_id;
        if (! is_string($author) || trim($author) === '') {
            throw new \RuntimeException('LinkedIn account author URN is missing.');
        }

        return trim($author);
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadResolvedImage(SocialPost $post, SocialAccount $account, string $ownerUrn): array
    {
        $url = trim((string) data_get($post->metadata, 'linkedin.resolved_image_url', ''));
        $source = trim((string) data_get($post->metadata, 'linkedin.resolved_image_source', ''));

        if ($url === '') {
            return [
                'resolved_image_url' => null,
                'resolved_image_source' => $source !== '' ? $source : null,
                'uploaded' => false,
                'asset' => null,
                'skipped_reason' => 'no_image_resolved',
            ];
        }

        try {
            $uploaded = $this->client->uploadImage((string) $account->access_token, $ownerUrn, $url);

            return [
                'resolved_image_url' => $url,
                'resolved_image_source' => $source !== '' ? $source : null,
                'uploaded' => true,
                'asset' => $uploaded['asset'],
                'upload_status' => $uploaded['upload_status'],
                'image_status' => $uploaded['image_status'],
                'register_response' => $uploaded['register_response'],
            ];
        } catch (\Throwable $exception) {
            return [
                'resolved_image_url' => $url,
                'resolved_image_source' => $source !== '' ? $source : null,
                'uploaded' => false,
                'asset' => null,
                'error' => $exception->getMessage(),
                'skipped_reason' => 'image_upload_failed',
            ];
        }
    }

    /**
     * @param array<string,mixed> $image
     */
    private function storeImageMetadata(SocialPost $post, array $image): void
    {
        $metadata = (array) $post->metadata;
        $linkedin = (array) ($metadata['linkedin'] ?? []);
        $linkedin['image_upload'] = $image;
        $linkedin['image_urn'] = $image['asset'] ?? null;
        $metadata['linkedin'] = $linkedin;

        $post->forceFill(['metadata' => $metadata])->save();
    }
}
