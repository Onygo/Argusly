<?php

namespace App\Jobs;

use App\Models\ContentImage;
use App\Services\Ai\ImageGenerationService;
use App\Services\CreditWalletService;
use App\Services\GenerationFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateContentFeaturedImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $contentImageId)
    {
        $this->onQueue('generation');
    }

    public function handle(ImageGenerationService $images, CreditWalletService $wallets, GenerationFinalizer $finalizer): void
    {
        /** @var ContentImage|null $image */
        $image = ContentImage::query()
            ->with(['content.clientSite.workspace'])
            ->find($this->contentImageId);

        if (! $image) {
            return;
        }

        $image->status = 'generating';
        $image->error_message = null;
        $image->save();

        try {
            $images->processFeaturedImage($image, $wallets);
            $image->refresh();
            $content = $image->content;

            Log::info('content_image.generated', [
                'content_id' => (string) $content->id,
                'workspace_id' => (string) ($content->workspace_id ?? ''),
                'content_image_id' => (string) $image->id,
                'credit_cost' => (int) $image->credit_cost,
                'provider' => (string) $image->provider,
            ]);
        } catch (Throwable $exception) {
            try {
                $finalizer->markContentImageFailedAndRefundIfNeeded(
                    $image,
                    'provider_error',
                    $exception->getMessage()
                );
            } catch (Throwable) {
                // Best-effort release. Keep original error semantics.
            }

            Log::warning('content_image.failed', [
                'content_image_id' => (string) $image->id,
                'content_id' => (string) $image->content_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $image = ContentImage::query()->find($this->contentImageId);
        if (! $image) {
            return;
        }

        try {
            app(GenerationFinalizer::class)->markContentImageFailedAndRefundIfNeeded(
                $image,
                'job_failed',
                $exception->getMessage()
            );
        } catch (Throwable) {
            // Best-effort release.
        }
    }
}
