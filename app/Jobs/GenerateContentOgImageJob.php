<?php

namespace App\Jobs;

use App\Models\ContentImage;
use App\Services\Ai\ImageGenerationService;
use App\Services\CreditWalletService;
use App\Services\Images\OGImageRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateContentOgImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly string $contentImageId)
    {
        $this->onQueue('generation');
    }

    public function handle(
        ImageGenerationService $images,
        OGImageRenderer $renderer,
        CreditWalletService $wallets
    ): void {
        /** @var ContentImage|null $ogImage */
        $ogImage = ContentImage::query()
            ->with(['content.workspace', 'content.featuredImage'])
            ->find($this->contentImageId);

        if (! $ogImage) {
            return;
        }

        $ogImage->status = 'generating';
        $ogImage->error_message = null;
        $ogImage->save();

        try {
            $content = $ogImage->content;
            if (! $content) {
                throw new \RuntimeException('Content not found for OG generation.');
            }

            $bgImage = $content->featuredImage;
            if (! $bgImage || $bgImage->status !== 'ready' || blank($bgImage->image_path)) {
                $bgImage = $images->createAndProcessFeaturedImage($content, $wallets);
            }

            $rendered = $renderer->render($content, $bgImage);

            ContentImage::query()
                ->where('content_id', $content->id)
                ->where('type', ImageGenerationService::OG_TYPE)
                ->where('id', '!=', $ogImage->id)
                ->update(['is_active' => false]);

            $ogImage->update([
                'status' => 'ready',
                'provider' => 'pl-renderer',
                'model' => 'pl-renderer',
                'image_path' => $rendered->path,
                'image_url' => $rendered->url,
                'original_path' => $rendered->path,
                'credit_cost' => 0,
                'is_active' => true,
                'error_message' => null,
                'metadata' => [
                    'mime' => 'image/png',
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);

            $images->generateDerivativesForStoredImage($ogImage, $content);

            Log::info('content_image.og_generated', [
                'content_id' => (string) $content->id,
                'workspace_id' => (string) ($content->workspace_id ?? ''),
                'content_image_id' => (string) $ogImage->id,
                'provider' => 'pl-renderer',
                'background_image_id' => (string) ($bgImage->id ?? ''),
            ]);
        } catch (Throwable $exception) {
            ContentImage::query()
                ->whereKey($ogImage->id)
                ->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                ]);

            Log::warning('content_image.og_failed', [
                'content_image_id' => (string) $ogImage->id,
                'content_id' => (string) $ogImage->content_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
