<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\WordPress\WordPressLanguageSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncTranslatedDraftToWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public string $translatedDraftId,
        public bool $linkToSource = true,
    ) {
        $this->queue = 'wordpress-sync';
    }

    public function handle(
        DeliverDraftToWordPress $deliveryService,
        WordPressLanguageSyncService $languageSyncService,
    ): void {
        $draft = Draft::query()
            ->with(['content', 'clientSite', 'sourceDraft.content'])
            ->findOrFail($this->translatedDraftId);

        if (! $draft->isTranslation()) {
            Log::warning('SyncTranslatedDraftToWordPressJob: Draft is not a translation', [
                'draft_id' => $this->translatedDraftId,
                'draft_type' => $draft->draft_type?->value,
            ]);
            return;
        }

        if (! $draft->content || ! $draft->clientSite) {
            throw new RuntimeException('Draft is missing content or client site');
        }

        Log::info('SyncTranslatedDraftToWordPressJob starting', [
            'draft_id' => $this->translatedDraftId,
            'language' => $draft->language->value,
            'source_draft_id' => $draft->source_draft_id,
        ]);

        $publishTarget = $languageSyncService->getOrCreatePublishTarget(
            $draft->content,
            $draft->clientSite,
            $draft->language
        );

        $draft->status = 'ready_to_deliver';
        $draft->save();

        $result = $deliveryService->deliver($draft);

        $languageSyncService->updatePublishTargetAfterSync($publishTarget, $result);

        if (! $result['ok']) {
            Log::error('SyncTranslatedDraftToWordPressJob: Delivery failed', [
                'draft_id' => $this->translatedDraftId,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            throw new RuntimeException($result['error'] ?? 'WordPress sync failed');
        }

        if ($this->linkToSource && $draft->sourceDraft) {
            $this->attemptLanguageLinking($draft, $languageSyncService, $publishTarget);
        }

        $draft->delivery_status = 'delivered';
        $draft->delivered_at = now();
        $draft->save();

        Log::info('SyncTranslatedDraftToWordPressJob completed', [
            'draft_id' => $this->translatedDraftId,
            'wp_post_id' => $publishTarget->wp_post_id,
            'language' => $draft->language->value,
        ]);
    }

    private function attemptLanguageLinking(
        Draft $translatedDraft,
        WordPressLanguageSyncService $languageSyncService,
        $publishTarget
    ): void {
        $sourceDraft = $translatedDraft->sourceDraft;
        if (! $sourceDraft) {
            return;
        }

        $linkingPayload = $languageSyncService->prepareLanguageLinkingPayload(
            $translatedDraft,
            $sourceDraft,
            $translatedDraft->clientSite
        );

        if (! $linkingPayload) {
            Log::info('Language linking not available or not possible', [
                'translated_draft_id' => $translatedDraft->id,
                'source_draft_id' => $sourceDraft->id,
            ]);
            return;
        }

        Log::info('Language linking prepared but not yet implemented', [
            'translated_draft_id' => $translatedDraft->id,
            'source_draft_id' => $sourceDraft->id,
            'plugin' => $linkingPayload['plugin'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncTranslatedDraftToWordPressJob permanently failed', [
            'draft_id' => $this->translatedDraftId,
            'error' => $exception->getMessage(),
        ]);
    }
}
