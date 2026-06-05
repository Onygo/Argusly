<?php

namespace App\Jobs\DraftComparison;

use App\Jobs\GenerateDraftJob;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonVariant;
use App\Services\DraftComparison\DraftComparisonCreditManager;
use App\Services\DraftComparison\DraftComparisonFeatureGate;
use App\Services\DraftComparison\DraftComparisonPromptSnapshotBuilder;
use App\Services\DraftComparison\DraftComparisonScoringService;
use App\Services\Drafts\DraftIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GenerateDraftComparisonVariantJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 420;

    public int $uniqueFor = 1800;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public readonly string $variantId,
    ) {}

    public function uniqueId(): string
    {
        return 'draft_compare:variant:' . $this->variantId;
    }

    public function handle(
        DraftComparisonCreditManager $creditManager,
        DraftComparisonFeatureGate $comparisonFeatureGate,
        DraftComparisonPromptSnapshotBuilder $promptSnapshotBuilder,
        DraftComparisonScoringService $scoringService,
        ?DraftIntelligenceService $draftIntelligenceService = null,
    ): void {
        $draftIntelligenceService ??= app(DraftIntelligenceService::class);

        $variant = DraftComparisonVariant::query()
            ->with(['draftComparison.brief', 'draftComparison.content', 'draft'])
            ->find($this->variantId);

        if (! $variant) {
            return;
        }

        if ($variant->isTerminal()) {
            FinalizeDraftComparisonJob::dispatch((string) $variant->draft_comparison_id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        $comparison = $variant->draftComparison;
        if (! $comparison) {
            $variant->markFailed('Draft comparison not found.');
            return;
        }

        if ((string) $comparison->status === DraftComparison::STATUS_CANCELLED) {
            $variant->markCancelled();
            $this->syncLegacyItemStatus($comparison, $variant, 'failed', 'Comparison was cancelled before generation started.');
            FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        [$claimed, $draftId] = DB::transaction(function () use ($variant, $comparison): array {
            $lockedVariant = DraftComparisonVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedVariant || $lockedVariant->isTerminal()) {
                return [false, null];
            }

            $lockedVariant->markProcessing(persist: true, recalculateParent: false);
            if (! $lockedVariant->generation_job_uuid && $this->job && method_exists($this->job, 'uuid')) {
                $lockedVariant->generation_job_uuid = (string) $this->job->uuid();
                $lockedVariant->save();
            }

            $draftId = $this->ensureDraftForVariant($comparison, $lockedVariant);
            $this->syncLegacyItemStatus($comparison, $lockedVariant, 'generating');

            return [true, $draftId];
        });

        if (! $claimed || ! $draftId) {
            FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        $draft = Draft::query()->find($draftId);
        if (! $draft) {
            $this->markVariantFailed($comparison, $variant->fresh() ?: $variant, 'Draft not found for comparison variant.', false);
            FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        try {
            $promptSnapshot = $promptSnapshotBuilder->buildForVariant($comparison, $variant, $draft);
            $this->persistPromptSnapshotWithSharedHashGuard($comparison, $variant, $promptSnapshot);
        } catch (Throwable $exception) {
            $this->markVariantFailed(
                $comparison,
                $variant->fresh() ?: $variant,
                'Prompt snapshot drift detected: ' . $exception->getMessage(),
                false,
            );

            FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        $latencyStart = microtime(true);

        if ((string) $draft->status !== 'generated' || trim((string) $draft->content_html) === '') {
            try {
                GenerateDraftJob::dispatchSync((string) $draft->id);
            } catch (Throwable $exception) {
                $retryable = $this->isRetryable($exception);
                $this->markVariantFailed($comparison, $variant->fresh() ?: $variant, $exception->getMessage(), $retryable);

                if ($retryable) {
                    throw $exception;
                }

                FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                    ->onQueue('generation')
                    ->afterCommit();

                return;
            }
        }

        $draft->refresh();

        try {
            $draftIntelligenceService->analyzeAndStore($draft);
            $draft->unsetRelation('analysis');
            $draft->load('analysis');
        } catch (Throwable $exception) {
            report($exception);
        }

        if ((string) $draft->status !== 'generated') {
            $this->markVariantFailed(
                $comparison,
                $variant->fresh() ?: $variant,
                (string) ($draft->last_error ?: 'Draft generation failed for comparison variant.'),
                false,
            );

            FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return;
        }

        $generation = (array) data_get($draft->meta, 'generation', []);
        $chargedCredits = max(0, (int) data_get($generation, 'charged_credits', (int) ($draft->credit_cost ?? 0)));
        $inputTokens = max(0, (int) data_get($generation, 'input_tokens', 0));
        $outputTokens = max(0, (int) data_get($generation, 'output_tokens', 0));
        $totalTokens = max(0, (int) data_get($generation, 'tokens', data_get($generation, 'total_tokens', 0)));
        $latencyMs = max(0, (int) round((microtime(true) - $latencyStart) * 1000));
        $scoringEnabled = $comparisonFeatureGate->scoringEnabledForComparison($comparison);
        $scoring = $scoringEnabled
            ? $scoringService->evaluateDraft($draft)
            : $this->disabledScoringPayload($draft);
        $metrics = (array) ($scoring['metrics'] ?? []);
        $scoreRows = (array) ($scoring['score_rows'] ?? []);

        DB::transaction(function () use (
            $comparison,
            $variant,
            $draft,
            $chargedCredits,
            $inputTokens,
            $outputTokens,
            $latencyMs,
            $metrics,
            $totalTokens,
            $scoreRows,
            $scoringService,
        ): void {
            $lockedVariant = DraftComparisonVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedVariant || $lockedVariant->isTerminal()) {
                return;
            }

            $scoringService->replaceVariantScores($lockedVariant, $scoreRows);

            $lockedVariant->fill([
                'draft_id' => (string) $draft->id,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'credit_cost' => $chargedCredits > 0 ? $chargedCredits : $lockedVariant->credit_cost,
                'latency_ms' => $latencyMs,
                'error_message' => null,
            ]);
            $lockedVariant->markCompleted(persist: true, recalculateParent: false);

            $item = DraftComparisonItem::query()
                ->where('draft_comparison_id', $comparison->id)
                ->where(function ($query) use ($lockedVariant, $draft): void {
                    $query->where('draft_id', $draft->id)
                        ->orWhere(function ($nested) use ($lockedVariant): void {
                            $nested->where('provider', $lockedVariant->provider_key)
                                ->where('model', $lockedVariant->model_key);
                        });
                })
                ->first();

            if ($item) {
                $item->update([
                    'draft_id' => (string) $draft->id,
                    'status' => 'generated',
                    'charged_credits' => $chargedCredits,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $totalTokens,
                    'metrics' => $metrics,
                    'error_message' => null,
                    'generation_completed_at' => now(),
                ]);
            }
        });

        $creditManager->recordVariantUsage(
            comparison: $comparison,
            variantKey: (string) $variant->id,
            credits: $chargedCredits,
            usage: [
                'provider' => (string) $variant->provider_key,
                'model' => (string) $variant->model_key,
                'draft_id' => (string) $draft->id,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'latency_ms' => $latencyMs,
            ],
        );

        FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
            ->onQueue('generation')
            ->afterCommit();
    }

    public function failed(Throwable $exception): void
    {
        $variant = DraftComparisonVariant::query()
            ->with('draftComparison')
            ->find($this->variantId);

        if (! $variant || $variant->isTerminal()) {
            return;
        }

        $comparison = $variant->draftComparison;
        if (! $comparison) {
            return;
        }

        $this->markVariantFailed(
            $comparison,
            $variant,
            'Generation retries exhausted: ' . $exception->getMessage(),
            false,
        );

        FinalizeDraftComparisonJob::dispatch((string) $comparison->id)
            ->onQueue('generation');
    }

    /**
     * @return array{metrics:array<string,mixed>,score_rows:array<int,array<string,mixed>>}
     */
    private function disabledScoringPayload(Draft $draft): array
    {
        $plainText = trim((string) preg_replace('/\\s+/u', ' ', strip_tags((string) ($draft->content_html ?? ''))));
        $wordCount = $plainText === '' ? 0 : str_word_count($plainText);
        $readingTime = $wordCount > 0 ? max(1, (int) ceil($wordCount / 220)) : null;

        return [
            'metrics' => [
                'word_count' => $wordCount,
                'reading_time' => $readingTime,
                'reading_time_minutes' => $readingTime,
                'scoring_status' => 'disabled_by_plan',
                'scored_at' => now()->toIso8601String(),
                'scoring_version' => 'draft_compare_disabled',
            ],
            'score_rows' => [],
        ];
    }

    private function ensureDraftForVariant(DraftComparison $comparison, DraftComparisonVariant $variant): ?string
    {
        if ($variant->draft_id) {
            $existingDraft = Draft::query()->find($variant->draft_id);
            if ($existingDraft) {
                $this->syncDraftCompareMeta($existingDraft, $comparison, $variant);

                return (string) $variant->draft_id;
            }
        }

        $item = DraftComparisonItem::query()
            ->where('draft_comparison_id', $comparison->id)
            ->where('provider', $variant->provider_key)
            ->where('model', $variant->model_key)
            ->first();

        if ($item?->draft_id && Draft::query()->whereKey($item->draft_id)->exists()) {
            $variant->draft_id = (string) $item->draft_id;
            $variant->save();
            $linkedDraft = Draft::query()->find($item->draft_id);
            if ($linkedDraft) {
                $this->syncDraftCompareMeta($linkedDraft, $comparison, $variant, $item);
            }

            return (string) $item->draft_id;
        }

        $brief = $comparison->brief;
        if (! $brief) {
            return null;
        }

        $requiredCredits = max(
            0,
            (int) ($variant->credit_cost ?? 0),
            (int) data_get($comparison->meta, 'per_draft_credits', 0)
        );

        $draft = new Draft();
        $draft->id = (string) Str::uuid();
        $draft->brief_id = (string) $brief->id;
        $draft->content_id = (string) ($comparison->content_id ?: ($brief->content_id ?: '')) ?: null;
        $draft->draft_comparison_id = (string) $comparison->id;
        $draft->draft_comparison_variant_id = (string) $variant->id;
        $draft->client_site_id = (string) $comparison->client_site_id;
        $draft->status = 'queued';
        $draft->attempts = 0;
        $draft->title = (string) ($brief->title ?: 'Untitled draft');
        $draft->seo_title = (string) ($brief->title ?: 'Untitled draft');
        $draft->seo_h1 = (string) ($brief->title ?: 'Untitled draft');
        $draft->seo_canonical = (string) data_get($brief->client_refs, 'canonical_url', '') ?: null;
        $draft->robots_index = data_get($brief->client_refs, 'robots_index');
        $draft->robots_follow = data_get($brief->client_refs, 'robots_follow');
        $draft->schema_type = (string) data_get($brief->client_refs, 'schema_type', '') ?: null;
        $draft->output_type = (string) ($brief->output_type ?? 'kb_article');
        $draft->meta = [
            'language' => $brief->language,
            'intent' => $brief->intent,
            'primary_keyword' => $brief->primary_keyword,
            'audience' => $brief->audience,
            'notes' => $brief->notes,
            'secondary_keywords' => $brief->secondary_keywords,
            'client_refs' => $brief->client_refs ?? [],
            'requested_max_output_tokens' => (int) ($comparison->requested_max_output_tokens ?: 8000),
            'required_credits' => $requiredCredits,
            'generation_type' => (string) data_get($comparison->meta, 'generation_type', 'article'),
            'generation_provider_override' => (string) $variant->provider_key,
            'generation_model_override' => (string) $variant->model_key,
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'variant_id' => (string) $variant->id,
                'item_id' => null,
                'legacy_item_id' => (string) ($item?->id ?: ''),
                'provider' => (string) $variant->provider_key,
                'model' => (string) $variant->model_key,
                'is_hybrid' => false,
                'comparison_credit_managed' => true,
            ],
        ];
        $draft->credit_cost = $requiredCredits;
        $draft->save();

        if (! $item) {
            $item = DraftComparisonItem::query()->create([
                'id' => (string) Str::uuid(),
                'draft_comparison_id' => (string) $comparison->id,
                'draft_id' => (string) $draft->id,
                'sort_order' => max(1, (int) $variant->sort_order),
                'provider' => (string) $variant->provider_key,
                'model' => (string) $variant->model_key,
                'status' => 'queued',
                'credit_cost' => $requiredCredits,
            ]);
        } else {
            $item->draft_id = (string) $draft->id;
            if ((int) $item->credit_cost <= 0 && $requiredCredits > 0) {
                $item->credit_cost = $requiredCredits;
            }
            $item->save();
        }

        $variant->draft_id = (string) $draft->id;
        $variant->save();
        $this->syncDraftCompareMeta($draft, $comparison, $variant, $item);

        return (string) $draft->id;
    }

    private function markVariantFailed(
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
        string $error,
        bool $retryable,
    ): void {
        $message = mb_substr($error, 0, 5000);

        DB::transaction(function () use ($comparison, $variant, $message, $retryable): void {
            $locked = DraftComparisonVariant::query()->whereKey($variant->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            if ($retryable) {
                $locked->status = DraftComparisonVariant::STATUS_QUEUED;
                $locked->error_message = $message;
                $locked->save();
                $this->syncLegacyItemStatus($comparison, $locked, 'queued', $message);

                return;
            }

            $locked->markFailed($message, persist: true, recalculateParent: false);
            $this->syncLegacyItemStatus($comparison, $locked, 'failed', $message);
        });
    }

    private function syncLegacyItemStatus(
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
        string $status,
        ?string $errorMessage = null,
    ): void {
        $item = DraftComparisonItem::query()
            ->where('draft_comparison_id', $comparison->id)
            ->where(function ($query) use ($variant): void {
                if ($variant->draft_id) {
                    $query->where('draft_id', $variant->draft_id)
                        ->orWhere(function ($nested) use ($variant): void {
                            $nested->where('provider', $variant->provider_key)
                                ->where('model', $variant->model_key);
                        });

                    return;
                }

                $query->where('provider', $variant->provider_key)
                    ->where('model', $variant->model_key);
            })
            ->first();

        if (! $item) {
            return;
        }

        $item->status = $status;
        if ($errorMessage !== null && trim($errorMessage) !== '') {
            $item->error_message = mb_substr($errorMessage, 0, 5000);
        } elseif ($status !== 'failed') {
            $item->error_message = null;
        }

        if ($status === 'generating') {
            $item->generation_started_at = now();
        }

        if (in_array($status, ['generated', 'failed'], true)) {
            $item->generation_completed_at = now();
        }

        $item->save();
    }

    private function isRetryable(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $retryable = [
            'timeout',
            'timed out',
            'connection',
            'rate limit',
            '429',
            '500',
            '502',
            '503',
            '504',
            'temporarily unavailable',
        ];

        $nonRetryable = [
            '401',
            '403',
            'unauthorized',
            'forbidden',
            'invalid api key',
            'invalid_request',
            'policy',
            'refused',
            'insufficient credits',
        ];

        foreach ($nonRetryable as $needle) {
            if (str_contains($message, $needle)) {
                return false;
            }
        }

        foreach ($retryable as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function syncDraftCompareMeta(
        Draft $draft,
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
        ?DraftComparisonItem $item = null,
    ): void {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $draftCompare = is_array(data_get($meta, 'draft_compare')) ? data_get($meta, 'draft_compare') : [];

        $draftCompare['comparison_id'] = (string) $comparison->id;
        $draftCompare['variant_id'] = (string) $variant->id;
        $draftCompare['item_id'] = null;
        $draftCompare['legacy_item_id'] = (string) ($item?->id ?: data_get($draftCompare, 'legacy_item_id', ''));
        $draftCompare['provider'] = (string) $variant->provider_key;
        $draftCompare['model'] = (string) $variant->model_key;
        $draftCompare['is_hybrid'] = false;
        $draftCompare['comparison_credit_managed'] = true;
        $meta['draft_compare'] = $draftCompare;
        $meta['generation_provider_override'] = (string) $variant->provider_key;
        $meta['generation_model_override'] = (string) $variant->model_key;

        $draft->meta = $meta;
        $draft->draft_comparison_id = (string) $comparison->id;
        $draft->draft_comparison_variant_id = (string) $variant->id;
        $draft->save();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function persistPromptSnapshotWithSharedHashGuard(
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
        array $snapshot,
    ): void {
        DB::transaction(function () use ($comparison, $variant, $snapshot): void {
            $lockedComparison = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->first();

            $lockedVariant = DraftComparisonVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedComparison || ! $lockedVariant) {
                throw new \RuntimeException('Comparison or variant not found while persisting prompt snapshot.');
            }

            $sharedHash = trim((string) ($snapshot['shared_inputs_hash'] ?? ''));
            if ($sharedHash === '') {
                throw new \RuntimeException('Prompt snapshot is missing shared_inputs_hash.');
            }

            $summary = is_array($lockedComparison->comparison_summary_json) ? $lockedComparison->comparison_summary_json : [];
            $promptAudit = is_array($summary['prompt_audit'] ?? null) ? $summary['prompt_audit'] : [];
            $baselineHash = trim((string) ($promptAudit['shared_inputs_hash'] ?? ''));

            if ($baselineHash !== '' && ! hash_equals($baselineHash, $sharedHash)) {
                throw new \RuntimeException(sprintf(
                    'Variant prompt shared hash %s does not match comparison baseline %s.',
                    $sharedHash,
                    $baselineHash
                ));
            }

            if ($baselineHash === '') {
                $promptAudit['shared_inputs_hash'] = $sharedHash;
                $promptAudit['shared_inputs'] = $snapshot['shared_inputs'] ?? [];
                $promptAudit['captured_at'] = now()->toIso8601String();
                $summary['prompt_audit'] = $promptAudit;
                $lockedComparison->comparison_summary_json = $summary;
                $lockedComparison->save();
            }

            $lockedVariant->prompt_snapshot_json = $snapshot;
            $lockedVariant->save();
        });
    }
}
