<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPublishAttempt;
use App\Services\Social\LinkedIn\LinkedInPublishException;
use App\Services\Social\LinkedIn\LinkedInPublisher;
use Illuminate\Support\Facades\RateLimiter;

class SocialPostService
{
    public function __construct(private readonly LinkedInPublisher $linkedInPublisher) {}

    public function publish(SocialPost $post): bool
    {
        $post->refresh();

        if ($post->provider_post_id) {
            return true;
        }

        if (! in_array($post->status, ['approved', 'scheduled', 'publishing'], true)) {
            $post->forceFill([
                'status' => 'failed',
                'error_message' => 'Human approval is required before publishing.',
            ])->save();

            return false;
        }

        if ($post->scheduled_at && $post->scheduled_at->isFuture()) {
            return false;
        }

        if (! config('services.linkedin.enabled') || ! config('services.linkedin.publishing_enabled')) {
            $post->forceFill([
                'status' => 'failed',
                'error_message' => 'LinkedIn publishing is disabled.',
            ])->save();

            $this->attempt($post, 'failed', errorMessage: 'LinkedIn publishing is disabled.');

            return false;
        }

        $key = 'linkedin-publish:member:'.$post->social_account_id.':'.now()->toDateString();
        if (RateLimiter::tooManyAttempts($key, (int) config('services.linkedin.member_daily_limit', 150))) {
            $post->forceFill([
                'status' => 'failed',
                'error_message' => 'LinkedIn member daily rate limit reached.',
            ])->save();

            $this->attempt($post, 'failed', errorMessage: 'LinkedIn member daily rate limit reached.');

            return false;
        }

        $post->forceFill(['status' => 'publishing', 'error_message' => null])->save();

        try {
            $result = $this->linkedInPublisher->publish($post->fresh(['account', 'content']));
            RateLimiter::hit($key, 86400);

            $post->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'provider_post_id' => $result['provider_post_id'],
                'error_message' => null,
            ])->save();

            $this->attempt($post, 'published', [
                'payload' => $result['payload'],
                'linkedin_image' => $result['image'] ?? [],
            ], [
                'linkedin_response' => $result['response']->json() ?? [],
                'linkedin_post_id' => $result['provider_post_id'],
                'linkedin_image' => $result['image'] ?? [],
            ], $result['response']->status());

            return true;
        } catch (LinkedInPublishException $exception) {
            $post->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            $this->attempt($post, 'failed', $exception->requestPayload, $exception->responsePayload, $exception->responseStatus, $exception->getMessage());

            return false;
        } catch (\Throwable $exception) {
            $post->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            $this->attempt($post, 'failed', errorMessage: $exception->getMessage());

            return false;
        }
    }

    /**
     * @param array<string,mixed>|null $requestPayload
     * @param array<string,mixed>|null $responsePayload
     */
    private function attempt(
        SocialPost $post,
        string $status,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        ?int $responseStatus = null,
        ?string $errorMessage = null,
    ): SocialPublishAttempt {
        return SocialPublishAttempt::query()->create([
            'social_post_id' => $post->id,
            'status' => $status,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'response_status' => $responseStatus,
            'error_message' => $errorMessage,
            'attempted_at' => now(),
        ]);
    }
}
