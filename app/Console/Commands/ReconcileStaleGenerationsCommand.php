<?php

namespace App\Console\Commands;

use App\Models\ContentImage;
use App\Models\Draft;
use App\Services\CreditWalletService;
use App\Services\GenerationFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileStaleGenerationsCommand extends Command
{
    protected $signature = 'generations:reconcile-stale {--minutes=} {--limit=200}';

    protected $description = 'Mark stale generation locks as failed and release reserved credits for drafts and images.';

    public function handle(CreditWalletService $credits, GenerationFinalizer $finalizer): int
    {
        $minutesOverride = $this->option('minutes');
        $limit = max(1, (int) $this->option('limit'));

        $imageTimeoutMinutes = $minutesOverride !== null
            ? max(1, (int) $minutesOverride)
            : max(1, (int) config('publishlayer.ai.images.generation_lock_timeout_minutes', 5));

        $draftTimeoutMinutes = $minutesOverride !== null
            ? max(1, (int) $minutesOverride)
            : max(1, (int) config('publishlayer.ai.drafts.generation_lock_timeout_minutes', 15));

        $staleImageCutoff = now()->subMinutes($imageTimeoutMinutes);
        $staleDraftCutoff = now()->subMinutes($draftTimeoutMinutes);

        $images = ContentImage::query()
            ->whereIn('status', ['queued', 'generating'])
            ->where('updated_at', '<=', $staleImageCutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $releasedImageReservations = 0;
        foreach ($images as $image) {
            try {
                $updated = $finalizer->markContentImageFailedAndRefundIfNeeded(
                    $image,
                    'stale_lock_timeout',
                    'Marked failed after stale generation lock timeout.'
                );
                $release = $updated?->credit_ledger_entry_id
                    ? \App\Models\CreditLedgerEntry::query()->find($updated->credit_ledger_entry_id)
                    : null;
                if ($release) {
                    $releasedImageReservations++;
                }

                Log::warning('generation.reconcile.image.stale_released', [
                    'content_image_id' => (string) $image->id,
                    'content_id' => (string) $image->content_id,
                    'credit_status' => (string) ($image->credit_status ?? ''),
                    'credit_ledger_entry_id' => (string) ($image->credit_ledger_entry_id ?? ''),
                    'timeout_minutes' => $imageTimeoutMinutes,
                ]);
            } catch (Throwable $exception) {
                Log::error('generation.reconcile.image.failed', [
                    'content_image_id' => (string) $image->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $drafts = Draft::query()
            ->where('credit_status', 'reserved')
            ->whereIn('status', ['processing', 'generating'])
            ->where('updated_at', '<=', $staleDraftCutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $releasedDraftReservations = 0;
        foreach ($drafts as $draft) {
            try {
                $release = $credits->ensureReleasedForDraft($draft, null, 'stale_lock_timeout');
                if ($release) {
                    $releasedDraftReservations++;
                }

                Draft::query()
                    ->whereKey($draft->id)
                    ->whereIn('status', ['processing', 'generating'])
                    ->update([
                        'status' => 'failed',
                        'last_error' => 'Marked failed after stale generation lock timeout.',
                    ]);

                Log::warning('generation.reconcile.draft.stale_released', [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'credit_status' => (string) ($draft->credit_status ?? ''),
                    'credit_ledger_entry_id' => (string) ($draft->credit_ledger_entry_id ?? ''),
                    'timeout_minutes' => $draftTimeoutMinutes,
                ]);
            } catch (Throwable $exception) {
                Log::error('generation.reconcile.draft.failed', [
                    'draft_id' => (string) $draft->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->table(
            ['scope', 'stale_found', 'reservations_released'],
            [
                ['images', $images->count(), $releasedImageReservations],
                ['drafts', $drafts->count(), $releasedDraftReservations],
            ]
        );

        return self::SUCCESS;
    }
}
