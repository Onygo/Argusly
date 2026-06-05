<?php

namespace App\Jobs;

use App\Models\ContentBatchItem;
use App\Services\BatchGenerationService;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\PlanQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateBatchItemDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

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

    public function handle(
        BatchGenerationService $batchGenerationService,
        DraftGenerationService $draftGenerationService,
        CreditWalletService $creditWalletService,
        ContentLifecycleService $contentLifecycleService,
        PlanQuotaService $planQuotaService
    ): void {
        $item = ContentBatchItem::query()->with(['batch.clientSite.workspace', 'draft'])->findOrFail($this->itemId);
        $batch = $item->batch;
        if (! $batch || $batch->status === 'canceled') {
            return;
        }

        $draft = $item->draft;
        if (! $draft) {
            $batchGenerationService->markItemFailed($item, 'No draft found for batch item.');
            return;
        }

        $item->update([
            'status' => 'drafting',
            'error_message' => null,
        ]);

        try {
            $creditCost = $batchGenerationService->resolveCreditCostForDraft($draft);
            if ($creditCost <= 0) {
                throw new \RuntimeException('Draft has no credit cost configured.');
            }

            $draft->status = 'processing';
            $draft->last_error = null;
            $draft->save();

            $creditWalletService->reserveForDraft($draft, (string) $batch->user_id);
            $result = $draftGenerationService->generateWithRepair($draft, 2);

            $existingMeta = is_array($draft->meta) ? $draft->meta : [];
            $resultMeta = (array) ($result['meta'] ?? []);
            $mergedMeta = array_replace_recursive($existingMeta, $resultMeta);
            $mergedMeta['generation'] = array_filter([
                'provider' => (string) data_get($result, 'provider', config('llm.default_provider', 'openai')),
                'model' => (string) data_get($result, 'model', ''),
                'tokens' => (int) data_get($result, 'usage.total_tokens', 0),
                'input_tokens' => (int) data_get($result, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($result, 'usage.output_tokens', 0),
                'request_id' => (string) data_get($result, 'request_id', ''),
                'credits' => $creditCost,
                'generated_at' => now()->toIso8601String(),
                'trigger' => 'content_batch',
                'batch_id' => (string) $batch->id,
                'batch_item_id' => (string) $item->id,
            ], fn ($value) => $value !== null);

            $draft->meta = $mergedMeta;
            $draft->save();

            $creditWalletService->commitUsageForDraft($draft, (string) $batch->user_id);

            if ($batch->clientSite?->workspace) {
                $planQuotaService->incrementUsage(
                    workspace: $batch->clientSite->workspace,
                    site: $batch->clientSite,
                    metric: PlanQuotaService::METRIC_ARTICLES_GENERATED,
                    amount: 1
                );
            }

            $draft->status = 'generated';
            $draft->title = $result['title'] ?? $draft->title;
            $draft->content_html = $result['content_html'] ?? $draft->content_html;
            $draft->meta = $mergedMeta;
            $draft->links = $result['links'] ?? $draft->links;
            $draft->delivery_status = 'pending';
            $draft->delivery_last_error = null;
            $draft->last_error = null;
            $draft->delivered_at = now();
            $draft->save();

            try {
                if ($draft->content_id) {
                    $contentLifecycleService->ensureRevisionFromDraft($draft, (int) $batch->user_id);
                }

                $item->update([
                    'status' => 'done',
                    'error_message' => null,
                ]);

                $batchGenerationService->incrementBatchCreditsUsed($batch, $creditCost);
                $batchGenerationService->syncBatchProgress($batch->fresh());
            } catch (Throwable $postProcessException) {
                \Log::warning('GenerateBatchItemDraftJob post-process failed after successful generation.', [
                    'batch_id' => (string) $batch->id,
                    'batch_item_id' => (string) $item->id,
                    'draft_id' => (string) $draft->id,
                    'error' => $postProcessException->getMessage(),
                ]);

                $draft->last_error = 'Post-generation warning: '.mb_substr($postProcessException->getMessage(), 0, 4500);
                $draft->save();

                $item->update([
                    'status' => 'done',
                    'error_message' => 'Post-generation warning: '.mb_substr($postProcessException->getMessage(), 0, 900),
                ]);
            }
        } catch (Throwable $exception) {
            $draft->refresh();
            if ($draft->credit_status === 'reserved') {
                try {
                    $creditWalletService->releaseReservationForDraft($draft, (string) $batch->user_id);
                } catch (Throwable) {
                    // Best effort release.
                }
            }

            $draft->status = 'failed';
            $draft->last_error = mb_substr($exception->getMessage(), 0, 5000);
            $draft->save();

            $batchGenerationService->markItemFailed($item, $exception->getMessage());
            throw $exception;
        }
    }
}
