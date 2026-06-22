<?php

namespace App\Services\SocialDistribution\Publishers;

use App\Enums\SocialPlatform;
use App\Models\SocialPublication;
use App\Services\Social\Instagram\InstagramClient;
use App\Services\SocialDistribution\InstagramPostTextRenderer;
use App\Services\SocialDistribution\SocialPlatformPublisher;
use App\Services\SocialDistribution\SocialPublishResult;
use Illuminate\Support\Str;

class InstagramPublisher implements SocialPlatformPublisher
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly InstagramPostTextRenderer $renderer,
    ) {}

    public function platform(): string
    {
        return SocialPlatform::INSTAGRAM->value;
    }

    public function publish(SocialPublication $publication): SocialPublishResult
    {
        $account = $publication->socialAccount;
        $variant = $publication->variant;

        if (! config('services.meta.enabled')) {
            return SocialPublishResult::failure('PUBLISHING_DISABLED', 'Instagram publishing is disabled.');
        }

        if (! $account?->isConnected()) {
            return SocialPublishResult::failure('ACCOUNT_NOT_CONNECTED', 'Instagram account is not connected.');
        }

        if (($account->platform?->value ?? (string) $account->platform) !== SocialPlatform::INSTAGRAM->value) {
            return SocialPublishResult::failure('ACCOUNT_PLATFORM_MISMATCH', 'Choose an Instagram account before publishing.');
        }

        if (! in_array((string) $account->account_type, ['business', 'creator'], true)) {
            return SocialPublishResult::failure('ACCOUNT_TYPE_UNSUPPORTED', 'Instagram publishing is only available for Business and Creator accounts.');
        }

        if ($account->isRateLimited()) {
            return SocialPublishResult::rateLimited($account->rate_limited_until);
        }

        if (! $variant) {
            return SocialPublishResult::failure('VARIANT_MISSING', 'Instagram post variant is missing.');
        }

        $caption = $this->renderer->renderVariant($variant);
        if ($caption === '') {
            return SocialPublishResult::failure('CAPTION_REQUIRED', 'Instagram caption is required before publishing.');
        }

        if (Str::length($caption) > 2200) {
            return SocialPublishResult::failure('CAPTION_TOO_LONG', 'Instagram caption must be 2,200 characters or fewer.');
        }

        $imageUrl = $this->imageUrl($variant->media_refs);
        if ($imageUrl === null) {
            return SocialPublishResult::failure('MEDIA_REQUIRED', 'Instagram posts require an image before publishing.');
        }

        $container = $this->client->createMediaContainer((string) $account->access_token, (string) $account->platform_account_id, $imageUrl, $caption);
        if ($container->failed()) {
            return $this->failureFromResponse('MEDIA_CONTAINER_FAILED', 'Instagram media container creation failed.', $container->status(), $container->json() ?? []);
        }

        $creationId = (string) ($container->json('id') ?? '');
        if ($creationId === '') {
            return SocialPublishResult::failure('MEDIA_CONTAINER_MISSING_ID', 'Instagram media container response did not include an id.', [
                'container_response' => $container->json() ?? [],
            ]);
        }

        $published = $this->client->publishMedia((string) $account->access_token, (string) $account->platform_account_id, $creationId);
        if ($published->failed()) {
            return $this->failureFromResponse('MEDIA_PUBLISH_FAILED', 'Instagram publication failed.', $published->status(), $published->json() ?? []);
        }

        $remoteId = (string) ($published->json('id') ?? $creationId);

        return SocialPublishResult::success($remoteId, response: [
            'media_container' => $container->json() ?? [],
            'publish_response' => $published->json() ?? [],
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]);
    }

    /**
     * @param array<int,mixed>|null $mediaRefs
     */
    private function imageUrl(?array $mediaRefs): ?string
    {
        foreach ((array) $mediaRefs as $media) {
            if (is_string($media)) {
                $url = trim($media);
                if ($url !== '') {
                    return $url;
                }

                continue;
            }

            $type = strtolower((string) data_get($media, 'type', data_get($media, 'media_type', 'image')));
            $url = trim((string) data_get($media, 'url', data_get($media, 'image_url', data_get($media, 'path', ''))));

            if ($url !== '' && in_array($type, ['image', 'photo'], true)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function failureFromResponse(string $fallbackCode, string $fallbackMessage, int $status, array $payload): SocialPublishResult
    {
        return SocialPublishResult::failure(
            (string) data_get($payload, 'error.code', $fallbackCode),
            (string) data_get($payload, 'error.message', $fallbackMessage),
            [
                'status' => $status,
                'response' => $payload,
            ],
        );
    }
}
