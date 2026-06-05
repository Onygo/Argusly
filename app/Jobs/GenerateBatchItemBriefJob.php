<?php

namespace App\Jobs;

use App\Models\ContentBatchItem;
use App\Services\BatchGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateBatchItemBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public string $itemId
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('batch-content-item:' . $this->itemId))
                ->expireAfter(600)
                ->releaseAfter(60),
        ];
    }

    public function handle(BatchGenerationService $batchGenerationService): void
    {
        $item = ContentBatchItem::query()->with('batch')->findOrFail($this->itemId);
        $batch = $item->batch;
        if (! $batch || $batch->status === 'canceled') {
            return;
        }

        $item->update([
            'status' => 'briefing',
            'error_message' => null,
        ]);

        try {
            $batchGenerationService->ensureBriefAndDraftForItem($item);

            $item->refresh();
            $item->update([
                'status' => 'drafting',
                'error_message' => null,
            ]);

            GenerateBatchItemDraftJob::dispatch((string) $item->id)->onQueue('generation');
        } catch (Throwable $exception) {
            $batchGenerationService->markItemFailed($item, $exception->getMessage());
            throw $exception;
        }
    }
}
