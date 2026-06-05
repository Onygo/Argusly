<?php

namespace App\Services\DraftComparison;

use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use Illuminate\Support\Facades\DB;

class DraftComparisonProgressService
{
    public function __construct(
        private readonly DraftComparisonScoringService $scoringService,
        private readonly DraftComparisonCreditManager $creditManager,
    ) {}

    public function markDraftGenerating(Draft $draft): void
    {
        $context = $this->comparisonContext($draft);
        if ($context === null) {
            return;
        }

        if ($context['is_hybrid']) {
            $comparison = DraftComparison::query()->find($context['comparison_id']);
            if ($comparison) {
                $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
                $hybridSummary = is_array($summary['hybrid'] ?? null) ? $summary['hybrid'] : [];
                $hybridSummary['status'] = 'generating';
                $hybridSummary['started_at'] = now()->toIso8601String();
                $summary['hybrid'] = $hybridSummary;
                $comparison->comparison_summary_json = $summary;
                $comparison->hybrid_status = 'generating';
                $comparison->hybrid_last_error = null;
                $comparison->hybrid_started_at = now();
                $comparison->save();
            }

            return;
        }

        if (! $context['item_id']) {
            return;
        }

        DraftComparisonItem::query()
            ->whereKey($context['item_id'])
            ->update([
                'status' => 'generating',
                'error_message' => null,
                'generation_started_at' => now(),
            ]);

        $this->syncComparison((string) $context['comparison_id']);
    }

    public function markDraftGenerated(Draft $draft): void
    {
        $context = $this->comparisonContext($draft);
        if ($context === null) {
            return;
        }

        if ($context['is_hybrid']) {
            $generation = (array) data_get($draft->meta, 'generation', []);
            $chargedCredits = max(0, (int) data_get($generation, 'charged_credits', (int) ($draft->credit_cost ?? 0)));

            $comparison = DraftComparison::query()->find($context['comparison_id']);
            if ($comparison) {
                $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
                $hybridSummary = is_array($summary['hybrid'] ?? null) ? $summary['hybrid'] : [];
                $hybridSummary['generated_at'] = now()->toIso8601String();
                $hybridSummary['draft_id'] = (string) $draft->id;
                $hybridSummary['charged_credits'] = $chargedCredits;
                $hybridSummary['input_tokens'] = max(0, (int) data_get($generation, 'input_tokens', 0));
                $hybridSummary['output_tokens'] = max(0, (int) data_get($generation, 'output_tokens', 0));
                $hybridSummary['total_tokens'] = max(0, (int) data_get($generation, 'tokens', data_get($generation, 'total_tokens', 0)));
                $hybridSummary['provider'] = (string) data_get($generation, 'provider', '');
                $hybridSummary['model'] = (string) data_get($generation, 'model_used', data_get($generation, 'model', ''));
                $hybridSummary['status'] = 'generated';
                $summary['hybrid'] = $hybridSummary;
                $comparison->comparison_summary_json = $summary;
                $comparison->hybrid_status = 'generated';
                $comparison->hybrid_last_error = null;
                $comparison->hybrid_completed_at = now();
                $comparison->hybrid_draft_id = (string) $draft->id;
                $comparison->save();

                $this->creditManager->recordVariantUsage(
                    comparison: $comparison,
                    variantKey: 'hybrid:' . (string) $draft->id,
                    credits: $chargedCredits,
                    usage: [
                        'type' => 'hybrid',
                        'draft_id' => (string) $draft->id,
                        'provider' => (string) data_get($generation, 'provider', ''),
                        'model' => (string) data_get($generation, 'model_used', data_get($generation, 'model', '')),
                        'input_tokens' => max(0, (int) data_get($generation, 'input_tokens', 0)),
                        'output_tokens' => max(0, (int) data_get($generation, 'output_tokens', 0)),
                        'total_tokens' => max(0, (int) data_get($generation, 'tokens', data_get($generation, 'total_tokens', 0))),
                    ],
                );
            }

            return;
        }

        if (! $context['item_id']) {
            return;
        }

        $generation = (array) data_get($draft->meta, 'generation', []);
        $chargedCredits = (int) data_get($generation, 'charged_credits', (int) ($draft->credit_cost ?? 0));

        DraftComparisonItem::query()
            ->whereKey($context['item_id'])
            ->update([
                'status' => 'generated',
                'error_message' => null,
                'generation_completed_at' => now(),
                'charged_credits' => max(0, $chargedCredits),
                'input_tokens' => max(0, (int) data_get($generation, 'input_tokens', 0)),
                'output_tokens' => max(0, (int) data_get($generation, 'output_tokens', 0)),
                'total_tokens' => max(0, (int) data_get($generation, 'tokens', data_get($generation, 'total_tokens', 0))),
                'metrics' => $this->scoringService->scoreDraft($draft),
                'meta' => array_filter([
                    'model_used' => (string) data_get($generation, 'model_used', data_get($generation, 'model', '')),
                    'request_id' => (string) data_get($generation, 'request_id', ''),
                ], fn (mixed $value): bool => trim((string) $value) !== ''),
            ]);

        $comparison = DraftComparison::query()->find($context['comparison_id']);
        if ($comparison) {
            $this->creditManager->recordVariantUsage(
                comparison: $comparison,
                variantKey: (string) $context['item_id'],
                credits: max(0, $chargedCredits),
                usage: [
                    'provider' => (string) data_get($draft->meta, 'draft_compare.provider', ''),
                    'model' => (string) data_get($draft->meta, 'draft_compare.model', ''),
                    'input_tokens' => max(0, (int) data_get($generation, 'input_tokens', 0)),
                    'output_tokens' => max(0, (int) data_get($generation, 'output_tokens', 0)),
                    'total_tokens' => max(0, (int) data_get($generation, 'tokens', data_get($generation, 'total_tokens', 0))),
                ],
            );
        }

        $this->syncComparison((string) $context['comparison_id']);
    }

    public function markDraftFailed(Draft $draft, string $error, bool $retryable): void
    {
        $context = $this->comparisonContext($draft);
        if ($context === null) {
            return;
        }

        if ($context['is_hybrid']) {
            $comparison = DraftComparison::query()->find($context['comparison_id']);
            if ($comparison) {
                $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
                $hybridSummary = is_array($summary['hybrid'] ?? null) ? $summary['hybrid'] : [];
                $hybridSummary['status'] = $retryable ? 'queued' : 'failed';
                $hybridSummary['last_error'] = mb_substr($error, 0, 5000);
                $hybridSummary['failed_at'] = $retryable ? null : now()->toIso8601String();
                $summary['hybrid'] = $hybridSummary;
                $comparison->comparison_summary_json = $summary;
                $comparison->hybrid_status = $retryable ? 'queued' : 'failed';
                $comparison->hybrid_last_error = mb_substr($error, 0, 5000);
                $comparison->hybrid_completed_at = $retryable ? null : now();
                $comparison->save();
            }

            return;
        }

        if (! $context['item_id']) {
            return;
        }

        DraftComparisonItem::query()
            ->whereKey($context['item_id'])
            ->update([
                'status' => $retryable ? 'queued' : 'failed',
                'error_message' => mb_substr($error, 0, 5000),
                'generation_completed_at' => $retryable ? null : now(),
            ]);

        $this->syncComparison((string) $context['comparison_id']);
    }

    public function syncComparison(string $comparisonId): void
    {
        $shouldSettle = false;
        $comparisonForSettlement = null;

        DB::transaction(function () use ($comparisonId, &$shouldSettle, &$comparisonForSettlement): void {
            $comparison = DraftComparison::query()
                ->whereKey($comparisonId)
                ->lockForUpdate()
                ->first();

            if (! $comparison) {
                return;
            }

            $counts = DraftComparisonItem::query()
                ->where('draft_comparison_id', $comparison->id)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END) as done_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
                ->selectRaw("SUM(CASE WHEN status IN ('queued','generating') THEN 1 ELSE 0 END) as open_count")
                ->selectRaw('SUM(charged_credits) as charged_credits_sum')
                ->first();

            $total = (int) ($counts->total ?? 0);
            $done = (int) ($counts->done_count ?? 0);
            $failed = (int) ($counts->failed_count ?? 0);
            $open = (int) ($counts->open_count ?? 0);
            $creditsUsed = max(0, (int) ($counts->charged_credits_sum ?? 0));

            $status = (string) $comparison->status;
            if ($total === 0) {
                $status = 'queued';
            } elseif ($open > 0) {
                $status = 'running';
            } elseif ($done === $total) {
                $status = 'completed';
            } elseif ($done > 0 && $failed > 0 && ($done + $failed) === $total) {
                $status = 'partially_completed';
            } elseif ($failed === $total) {
                $status = 'failed';
            }

            $lastError = DraftComparisonItem::query()
                ->where('draft_comparison_id', $comparison->id)
                ->whereNotNull('error_message')
                ->latest('updated_at')
                ->value('error_message');

            $comparison->fill([
                'status' => $status,
                'items_total' => $total,
                'items_done' => $done,
                'items_failed' => $failed,
                'credits_used' => $creditsUsed,
                'started_at' => $comparison->started_at ?: ($total > 0 ? now() : null),
                'completed_at' => in_array($status, ['completed', 'partially_completed'], true) ? now() : null,
                'failed_at' => $status === 'failed' ? now() : null,
                'last_error' => $lastError ? mb_substr((string) $lastError, 0, 5000) : null,
            ]);

            $comparison->save();

            if (in_array($status, ['completed', 'partially_completed', 'failed', 'cancelled'], true)) {
                $shouldSettle = true;
                $comparisonForSettlement = $comparison->fresh();
            }
        });

        if ($shouldSettle && $comparisonForSettlement instanceof DraftComparison) {
            $this->creditManager->settleComparison($comparisonForSettlement);
        }
    }

    /**
     * @return array{comparison_id:string,item_id:?string,is_hybrid:bool}|null
     */
    private function comparisonContext(Draft $draft): ?array
    {
        $comparisonId = trim((string) data_get($draft->meta, 'draft_compare.comparison_id', ''));
        if ($comparisonId === '') {
            return null;
        }

        $itemId = trim((string) data_get($draft->meta, 'draft_compare.item_id', ''));

        return [
            'comparison_id' => $comparisonId,
            'item_id' => $itemId !== '' ? $itemId : null,
            'is_hybrid' => (bool) data_get($draft->meta, 'draft_compare.is_hybrid', false),
        ];
    }
}
