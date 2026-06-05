<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\DraftDelivery\PushContentFeaturedImageToWordPress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PushFeaturedImageToWordPressJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $contentId
    ) {}

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [30, 90, 180, 300, 600];
    }

    public function uniqueId(): string
    {
        return 'wp_featured_image_push:' . $this->contentId;
    }

    public function handle(PushContentFeaturedImageToWordPress $pushService): void
    {
        $content = Content::query()->find($this->contentId);
        if (! $content) {
            return;
        }

        $result = $pushService->push($content, ensureWpPostId: true, allowReschedule: true);
        if (($result['ok'] ?? false) === true) {
            return;
        }

        if (($result['should_retry'] ?? false) === true) {
            $delay = $this->resolveRetryDelaySeconds();
            Log::warning('wp_featured_image_push_rescheduled', [
                'content_id' => (string) $content->id,
                'site_id' => (string) ($content->client_site_id ?? ''),
                'attempt' => $this->attempts(),
                'delay_seconds' => $delay,
                'error' => (string) ($result['error'] ?? 'wp_post_id missing'),
            ]);

            $this->release($delay);

            return;
        }

        $error = (string) ($result['error'] ?? 'Unknown featured image push error.');
        throw new RuntimeException($error);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('wp_featured_image_push_failed', [
            'content_id' => $this->contentId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveRetryDelaySeconds(): int
    {
        $schedule = $this->backoff();
        $index = max(0, min($this->attempts() - 1, count($schedule) - 1));

        return (int) $schedule[$index];
    }
}

