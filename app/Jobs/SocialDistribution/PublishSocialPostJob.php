<?php

namespace App\Jobs\SocialDistribution;

use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\SocialPublication;
use App\Models\SocialRateLimitWindow;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use App\Services\SocialDistribution\SocialPublisherRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishSocialPostJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $publicationId,
    ) {
        $this->onQueue((string) config('agentic_marketing.queue', 'default'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'social-publication:'.$this->publicationId;
    }

    public function handle(SocialPublisherRegistry $publishers, SocialDistributionAuditLogger $audit): void
    {
        $publication = SocialPublication::query()
            ->with(['socialAccount', 'variant'])
            ->findOrFail($this->publicationId);

        if ($publication->remote_post_id) {
            return;
        }

        if (! in_array(($publication->status?->value ?? $publication->status), [
            SocialPublicationStatus::APPROVED->value,
            SocialPublicationStatus::SCHEDULED->value,
            SocialPublicationStatus::QUEUED->value,
        ], true)) {
            return;
        }

        if (! $publication->variant?->isApproved()) {
            $this->markFailed($publication, 'APPROVAL_REQUIRED', 'Social post variant must be approved before publishing.', $audit);

            return;
        }

        if ($reason = $publication->variant?->publishingBlockedReason()) {
            $this->markFailed($publication, 'PUBLICATION_NOT_READY', $reason, $audit);

            return;
        }

        if ($publication->scheduled_for && $publication->scheduled_for->isFuture()) {
            $publication->forceFill([
                'status' => SocialPublicationStatus::SCHEDULED->value,
                'next_retry_at' => $publication->scheduled_for,
            ])->save();

            return;
        }

        if ($publication->socialAccount?->isRateLimited()) {
            $this->markRateLimited($publication, $publication->socialAccount->rate_limited_until, $audit);

            return;
        }

        $platform = $publication->platform?->value ?? (string) $publication->platform;
        $publisher = $publishers->forPlatform($platform);

        if (! $publisher) {
            $this->markFailed($publication, 'PLATFORM_DRIVER_NOT_CONFIGURED', "No publisher configured for platform [{$platform}].", $audit);

            return;
        }

        $before = $publication->attributesToArray();
        $publication->forceFill([
            'status' => SocialPublicationStatus::PUBLISHING->value,
            'started_at' => now(),
            'last_attempt_at' => now(),
            'attempts' => ((int) $publication->attempts) + 1,
        ])->save();
        $audit->record($publication, 'publication.publish_started', $before, $publication->attributesToArray());

        $result = $publisher->publish($publication->fresh(['socialAccount', 'variant']) ?? $publication);

        if ($result->success) {
            $publication->forceFill([
                'status' => SocialPublicationStatus::PUBLISHED->value,
                'published_at' => now(),
                'remote_post_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'response_snapshot' => $result->response,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $publication->variant?->forceFill(['status' => SocialPostVariantStatus::PUBLISHED->value])->save();
            $audit->record($publication, 'publication.published', null, $publication->attributesToArray());

            return;
        }

        if ($result->rateLimitedUntil) {
            $this->markRateLimited($publication, $result->rateLimitedUntil, $audit);

            return;
        }

        $this->markFailed(
            $publication,
            $result->errorCode ?: 'PUBLISH_FAILED',
            $result->errorMessage ?: 'Social publication failed.',
            $audit,
            $result->response,
        );
    }

    public function failed(Throwable $exception): void
    {
        $publication = SocialPublication::query()->find($this->publicationId);
        if (! $publication) {
            return;
        }

        $publication->forceFill([
            'status' => SocialPublicationStatus::FAILED->value,
            'last_error_code' => 'JOB_FAILED',
            'last_error_message' => $exception->getMessage(),
            'next_retry_at' => now()->addMinutes(15),
        ])->save();
    }

    private function markRateLimited(SocialPublication $publication, \DateTimeInterface $until, SocialDistributionAuditLogger $audit): void
    {
        $publication->forceFill([
            'status' => SocialPublicationStatus::RATE_LIMITED->value,
            'rate_limited_until' => $until,
            'next_retry_at' => $until,
            'last_error_code' => 'RATE_LIMITED',
            'last_error_message' => 'Platform rate limit reached.',
        ])->save();

        $publication->socialAccount?->forceFill(['rate_limited_until' => $until])->save();

        SocialRateLimitWindow::query()->create([
            'workspace_id' => $publication->workspace_id,
            'social_account_id' => $publication->social_account_id,
            'platform' => $publication->platform?->value ?? $publication->platform,
            'bucket' => 'publish',
            'limited_until' => $until,
            'metadata' => ['source' => static::class],
        ]);

        $audit->record($publication, 'publication.rate_limited', null, $publication->attributesToArray());
    }

    /**
     * @param array<string,mixed> $response
     */
    private function markFailed(SocialPublication $publication, string $code, string $message, SocialDistributionAuditLogger $audit, array $response = []): void
    {
        $publication->forceFill([
            'status' => SocialPublicationStatus::FAILED->value,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'response_snapshot' => $response ?: $publication->response_snapshot,
            'next_retry_at' => now()->addMinutes(15),
        ])->save();

        $audit->record($publication, 'publication.failed', null, $publication->attributesToArray(), [
            'error_code' => $code,
        ]);
    }
}
