<?php

namespace App\Jobs;

use App\Models\ClientSite;
use App\Models\Draft;
use App\Services\Publication\ContentPublicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Delivers a draft to its destination via ContentPublicationService.
 *
 * This job routes all publishing through ContentPublicationService, which
 * is the single source of truth for publication state.
 *
 * For WordPress sites: Uses ContentPublicationService → WordPressPublicationConnector
 * For Laravel sites: Uses separate LaravelConnectorSyncJob
 */
class DeliverDraftJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        public string $draftId,
        public bool $forceDelivery = false
    ) {}

    public function uniqueId(): string
    {
        return 'deliver_draft:' . $this->draftId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        ContentPublicationService $publicationService,
    ): void {
        $draft = Draft::query()
            ->whereKey($this->draftId)
            ->with(['clientSite:id,type', 'content'])
            ->first();

        if (! $draft) {
            Log::warning('draft_delivery.missing_draft', [
                'draft_id' => $this->draftId,
            ]);

            return;
        }

        $siteType = strtolower(trim((string) $draft->clientSite?->type));

        // Validate site type
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            $message = 'Delivery is not enabled for this site type.';
            Log::warning('draft_delivery.unsupported_site_type', [
                'draft_id' => (string) $draft->id,
                'client_site_id' => (string) ($draft->client_site_id ?? ''),
                'site_type' => $siteType,
            ]);

            // Mark draft as failed (Content update via service if WordPress)
            if ($draft->content && $siteType === ClientSite::TYPE_WORDPRESS) {
                $publicationService->markFailed($draft->content, 'SITE_TYPE_UNSUPPORTED', $message);
            }

            $draft->forceFill([
                'delivery_status' => 'failed',
                'delivery_last_error' => $message,
            ])->save();

            return;
        }

        // Laravel connector uses a different job (LaravelConnectorSyncJob)
        if ($siteType === ClientSite::TYPE_LARAVEL) {
            Log::info('draft_delivery.skipped_laravel', [
                'draft_id' => (string) $draft->id,
                'message' => 'Laravel connector uses LaravelConnectorSyncJob for delivery.',
            ]);

            return;
        }

        // WordPress delivery goes through ContentPublicationService
        $this->handleDirectPublicationViaService($draft, $publicationService);
    }

    private function handleDirectPublicationViaService(
        Draft $draft,
        ContentPublicationService $publicationService,
    ): void {
        $content = $draft->content;

        if (! $content) {
            $message = 'Draft content not found for delivery.';

            Log::error('draft_delivery.content_missing', [
                'draft_id' => (string) $draft->id,
            ]);

            $draft->forceFill([
                'delivery_status' => 'failed',
                'delivery_last_error' => $message,
            ])->save();

            throw new RuntimeException($message);
        }

        $destination = $publicationService->resolveDestinationForContent($content, $draft);

        // Mark as publishing before delivery
        $publicationService->markPublishing($content);

        // Publish via service (handles all status updates)
        $result = $publicationService->publish($content, $destination, $draft, [
            'force_delivery' => $this->forceDelivery,
        ]);

        if ($result->isSuccess() || $result->isSkipped()) {
            return;
        }

        throw new RuntimeException($result->errorMessage ?? $result->errorDetails() ?? 'Unknown delivery error');
    }

    public function failed(Throwable $e): void
    {
        $draft = Draft::query()
            ->whereKey($this->draftId)
            ->with('content')
            ->first();

        $message = $this->resolveFailureMessage($e);

        Log::error('draft_delivery.job_failed', [
            'draft_id' => $this->draftId,
            'content_id' => (string) ($draft?->content_id ?? ''),
            'client_site_id' => (string) ($draft?->client_site_id ?? ''),
            'exception' => $e::class,
            'message' => $message,
        ]);

        if (! $draft) {
            return;
        }

        // Update draft status directly (job failed)
        if ((string) $draft->delivery_status !== 'failed' || trim((string) $draft->delivery_last_error) === '') {
            $draft->forceFill([
                'delivery_status' => 'failed',
                'delivery_last_error' => $message,
            ])->save();
        }

        // Update content status via service if available
        if ($draft->content) {
            $draft->content->forceFill([
                'publish_status' => 'failed',
                'publish_error' => $message,
            ])->save();
        }
    }

    private function resolveFailureMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Unexpected delivery failure.';
    }
}
