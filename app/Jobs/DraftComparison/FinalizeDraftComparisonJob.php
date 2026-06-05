<?php

namespace App\Jobs\DraftComparison;

use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Services\DraftComparison\DraftComparisonCreditManager;
use App\Services\DraftComparison\DraftComparisonSummaryBuilder;
use App\Services\DraftComparison\DraftComparisonTrustSignalsBuilder;
use App\Services\DraftComparison\DraftComparisonWinnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class FinalizeDraftComparisonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 90, 300];
    }

    public function __construct(
        public readonly string $comparisonId,
    ) {}

    public function uniqueId(): string
    {
        return 'draft_compare:finalize:' . $this->comparisonId;
    }

    public function handle(
        DraftComparisonCreditManager $creditManager,
        DraftComparisonSummaryBuilder $summaryBuilder,
        DraftComparisonTrustSignalsBuilder $trustSignalsBuilder,
        DraftComparisonWinnerService $winnerService,
    ): void
    {
        $comparison = DraftComparison::query()->find($this->comparisonId);
        if (! $comparison) {
            return;
        }

        $shouldSettle = false;

        DB::transaction(function () use (&$comparison, &$shouldSettle, $summaryBuilder, $trustSignalsBuilder, $winnerService): void {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $counts = $locked->variants()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count", [DraftComparisonVariant::STATUS_PENDING])
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as queued_count", [DraftComparisonVariant::STATUS_QUEUED])
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as processing_count", [DraftComparisonVariant::STATUS_PROCESSING])
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_count", [DraftComparisonVariant::STATUS_COMPLETED])
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count", [DraftComparisonVariant::STATUS_FAILED])
                ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_count", [DraftComparisonVariant::STATUS_CANCELLED])
                ->first();

            $total = max(0, (int) ($counts->total ?? 0));
            $pending = max(0, (int) ($counts->pending_count ?? 0));
            $queued = max(0, (int) ($counts->queued_count ?? 0));
            $processing = max(0, (int) ($counts->processing_count ?? 0));
            $completed = max(0, (int) ($counts->completed_count ?? 0));
            $failed = max(0, (int) ($counts->failed_count ?? 0));
            $cancelled = max(0, (int) ($counts->cancelled_count ?? 0));
            $failedLike = $failed + $cancelled;

            if ($total === 0) {
                $total = max(0, (int) $locked->items()->count());
                $completed = max(0, (int) $locked->items()->where('status', 'generated')->count());
                $failedLike = max(0, (int) $locked->items()->where('status', 'failed')->count());
                $pending = max(0, $total - $completed - $failedLike);
                $queued = $pending;
                $processing = 0;
            }

            $locked->items_total = $total;
            $locked->items_done = $completed;
            $locked->items_failed = $failedLike;

            $terminal = ($pending + $queued + $processing) === 0;
            if (! $terminal) {
                $locked->markProcessing();
                $comparison = $locked;

                return;
            }

            $locked->recalculateAggregateStatus();

            $creditsUsed = $locked->variants()->where('status', DraftComparisonVariant::STATUS_COMPLETED)->sum('credit_cost');
            if ((int) $creditsUsed <= 0) {
                $creditsUsed = $locked->items()
                    ->where('status', 'generated')
                    ->selectRaw('SUM(CASE WHEN charged_credits > 0 THEN charged_credits ELSE credit_cost END) as billed_total')
                    ->value('billed_total');
            }

            $summary = is_array($locked->comparison_summary_json) ? $locked->comparison_summary_json : [];
            $summary['run'] = [
                'variant_total' => $total,
                'variant_completed' => $completed,
                'variant_failed' => $failedLike,
                'pending' => $pending,
                'queued' => $queued,
                'processing' => $processing,
                'status' => (string) $locked->status,
                'finalized_at' => now()->toIso8601String(),
            ];

            $summary['variants'] = $locked->variants()
                ->orderBy('sort_order')
                ->get([
                    'id',
                    'provider_key',
                    'model_key',
                    'status',
                    'draft_id',
                    'input_tokens',
                    'output_tokens',
                    'credit_cost',
                    'latency_ms',
                    'error_message',
                    'started_at',
                    'completed_at',
                ])
                ->map(static fn (DraftComparisonVariant $variant): array => [
                    'id' => (string) $variant->id,
                    'provider' => (string) $variant->provider_key,
                    'model' => (string) $variant->model_key,
                    'status' => (string) $variant->status,
                    'draft_id' => $variant->draft_id ? (string) $variant->draft_id : null,
                    'input_tokens' => $variant->input_tokens,
                    'output_tokens' => $variant->output_tokens,
                    'credit_cost' => $variant->credit_cost,
                    'latency_ms' => $variant->latency_ms,
                    'error_message' => $variant->error_message,
                    'started_at' => $variant->started_at?->toIso8601String(),
                    'completed_at' => $variant->completed_at?->toIso8601String(),
                ])
                ->values()
                ->all();
            $summary['scoring'] = $summaryBuilder->build($locked);
            $summary['recommendation'] = $winnerService->recommend($locked);
            $summary['trust'] = $trustSignalsBuilder->build($locked, $summary);

            $locked->comparison_summary_json = $summary;
            $locked->credits_used = max(0, (int) $creditsUsed);
            $locked->last_error = (string) ($locked->variants()
                ->whereNotNull('error_message')
                ->latest('updated_at')
                ->value('error_message') ?: '');
            if ($locked->last_error === '') {
                $locked->last_error = null;
            }

            $locked->save();
            $comparison = $locked;
            $shouldSettle = true;
        });

        if ($shouldSettle) {
            $creditManager->settleComparison(
                comparison: $comparison->fresh(),
                userId: $comparison->created_by_user_id ? (int) $comparison->created_by_user_id : null,
            );
        }
    }
}
