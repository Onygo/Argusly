<?php

namespace App\Services\SocialDistribution\Publishers;

use App\Enums\SocialPlatform;
use App\Models\SocialPublication;
use App\Models\SocialPost;
use App\Services\Social\SocialPostService;
use App\Services\SocialDistribution\LinkedInPostTextRenderer;
use App\Services\SocialDistribution\SocialPlatformPublisher;
use App\Services\SocialDistribution\SocialPublishResult;

class LinkedInPublisher implements SocialPlatformPublisher
{
    public function __construct(
        private readonly SocialPostService $posts,
        private readonly LinkedInPostTextRenderer $renderer,
    ) {}

    public function platform(): string
    {
        return SocialPlatform::LINKEDIN->value;
    }

    public function publish(SocialPublication $publication): SocialPublishResult
    {
        $account = $publication->socialAccount;

        if (! config('services.linkedin.enabled') || ! config('services.linkedin.publishing_enabled')) {
            return SocialPublishResult::failure('PUBLISHING_DISABLED', 'LinkedIn publishing is disabled.');
        }

        if (! $account?->isConnected()) {
            return SocialPublishResult::failure('ACCOUNT_NOT_CONNECTED', 'LinkedIn account is not connected.');
        }

        if ($account->isRateLimited()) {
            return SocialPublishResult::rateLimited($account->rate_limited_until);
        }

        $post = $this->socialPostFor($publication);

        if (! $this->posts->publish($post)) {
            $post->refresh();

            return SocialPublishResult::failure('PUBLISH_FAILED', $post->error_message ?: 'LinkedIn publication failed.', [
                'social_post_id' => (string) $post->id,
            ]);
        }

        $post->refresh();

        return SocialPublishResult::success((string) $post->provider_post_id, response: [
            'social_post_id' => (string) $post->id,
        ]);
    }

    private function socialPostFor(SocialPublication $publication): SocialPost
    {
        $variant = $publication->variant;

        if ($variant?->social_post_id) {
            $post = SocialPost::query()->with(['account', 'content'])->findOrFail($variant->social_post_id);
            $body = $this->renderer->renderVariant($variant);

            if ($body !== '' && trim((string) $post->body) !== $body) {
                $post->forceFill(['body' => $body])->save();
            }

            return $post->fresh(['account', 'content']);
        }

        $body = $variant ? $this->renderer->renderVariant($variant) : '';

        $post = SocialPost::query()->create([
            'organization_id' => $publication->organization_id,
            'workspace_id' => $publication->workspace_id,
            'campaign_id' => $publication->campaign_id,
            'content_id' => $variant?->content_id,
            'social_account_id' => $publication->social_account_id,
            'provider' => SocialPlatform::LINKEDIN->value,
            'type' => 'text',
            'body' => $body,
            'visibility' => 'public',
            'status' => 'approved',
            'scheduled_at' => $publication->scheduled_for,
            'metadata' => [
                'approval_required' => true,
                'source_publication_id' => (string) $publication->id,
                'source_variant_id' => $variant?->id ? (string) $variant->id : null,
            ],
        ]);

        $variant?->forceFill(['social_post_id' => $post->id])->save();

        return $post->fresh(['account', 'content']);
    }
}
