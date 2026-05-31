<?php

namespace App\Services\Integrations\LinkedIn;

use App\Models\IntegrationConnection;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class LinkedInPublishingService
{
    public function __construct(
        private readonly LinkedInProvider $provider,
        private readonly LinkedInTokenService $tokens,
        private readonly LinkedInOrganizationService $organizations,
        private readonly LinkedInMediaService $media,
    ) {}

    /**
     * @return array{external_id: string|null, external_url: string|null, payload: array<string, mixed>}
     */
    public function publish(SocialPost $post): array
    {
        $post->loadMissing(['socialProfile.integrationConnection.integration', 'account', 'brand']);

        $profile = $post->socialProfile;
        $connection = $profile?->integrationConnection;

        if (! $profile instanceof SocialProfile || $profile->provider !== $this->provider->key()) {
            throw new InvalidArgumentException('LinkedIn publishing requires a LinkedIn social profile.');
        }

        if (! $connection instanceof IntegrationConnection) {
            throw new InvalidArgumentException('LinkedIn publishing requires an integration connection.');
        }

        if ($profile->account_id !== null && $profile->account_id !== $post->account_id) {
            throw new InvalidArgumentException('LinkedIn profile does not belong to the social post account.');
        }

        if ($profile->brand_id !== null && $profile->brand_id !== $post->brand_id) {
            throw new InvalidArgumentException('LinkedIn profile does not belong to the social post brand.');
        }

        $connection = $this->tokens->refreshIfPossible($connection);
        $profile = $profile->refresh();

        if ($connection->status !== 'active' || $profile->status !== 'connected') {
            throw new InvalidArgumentException('Reconnect LinkedIn profile before publishing.');
        }

        if ($profile->type === 'person' && ! in_array('w_member_social', $connection->scopes ?? [], true)) {
            throw new InvalidArgumentException('LinkedIn profile is missing the w_member_social scope.');
        }

        if (in_array($profile->type, ['organization', 'page'], true) && ! $this->organizations->canPublishOrganization($connection, $profile)) {
            throw new InvalidArgumentException('LinkedIn organization publishing requires approved w_organization_social scope and page publishing role.');
        }

        if (! in_array($profile->type, ['person', 'organization', 'page'], true)) {
            throw new InvalidArgumentException('LinkedIn publishing requires a supported LinkedIn profile type.');
        }

        if (blank($profile->provider_profile_id)) {
            throw new InvalidArgumentException('LinkedIn profile is missing its provider member id.');
        }

        if ($this->media->hasMediaForPost($post)) {
            throw new RuntimeException($this->media->uploadNotImplementedMessage());
        }

        $payload = $this->ugcPayload($post, $profile);

        try {
            $response = Http::withToken((string) $connection->access_token)
                ->acceptJson()
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->post($this->provider->ugcPostsUrl(), $payload)
                ->throw();
        } catch (RequestException $exception) {
            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error_description')
                ?? 'LinkedIn publishing failed.';

            throw new RuntimeException((string) $message, previous: $exception);
        }

        $body = $response->json();
        $externalId = is_array($body) ? ($body['id'] ?? null) : null;
        $externalId ??= $response->header('x-restli-id') ?? $response->header('X-RestLi-Id');

        return [
            'external_id' => $externalId ? (string) $externalId : null,
            'external_url' => $this->externalUrl($externalId),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ugcPayload(SocialPost $post, SocialProfile $profile): array
    {
        $url = $post->metadata['url'] ?? null;
        $shareContent = [
            'shareCommentary' => ['text' => $post->post_text],
            'shareMediaCategory' => filled($url) ? 'ARTICLE' : 'NONE',
        ];

        if (filled($url)) {
            $shareContent['media'] = [[
                'status' => 'READY',
                'originalUrl' => (string) $url,
            ]];
        }

        return [
            'author' => $this->authorUrn($profile),
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $shareContent,
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];
    }

    private function authorUrn(SocialProfile $profile): string
    {
        if (in_array($profile->type, ['organization', 'page'], true)) {
            return $profile->metadata['organization_urn'] ?? $this->organizations->organizationUrn((string) $profile->provider_profile_id);
        }

        return "urn:li:person:{$profile->provider_profile_id}";
    }

    private function externalUrl(?string $externalId): ?string
    {
        if (! $externalId || ! str_starts_with($externalId, 'urn:li:share:')) {
            return null;
        }

        return 'https://www.linkedin.com/feed/update/'.$externalId;
    }
}
