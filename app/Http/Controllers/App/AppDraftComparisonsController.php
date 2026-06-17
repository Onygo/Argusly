<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CreateHybridDraftComparisonRequest;
use App\Http\Requests\App\EstimateDraftComparisonRequest;
use App\Http\Requests\App\SelectComparisonWinnerRequest;
use App\Http\Requests\App\StartDraftComparisonRequest;
use App\Http\Requests\App\StoreDraftComparisonRequest;
use App\Models\Brief;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonVariant;
use App\Services\CreditWalletService;
use App\Services\Credits\GenerationPricing;
use App\Services\DraftComparison\DraftComparisonMetricResolver;
use App\Services\DraftComparison\DraftComparisonProgressService;
use App\Services\DraftComparison\DraftScoreExpectationResolver;
use App\Services\DraftComparison\DraftComparisonService;
use App\Services\DraftComparison\DraftComparisonWinnerService;
use App\Services\DraftComparison\HybridDraftEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class AppDraftComparisonsController extends Controller
{
    public function setup(
        Request $request,
        Brief $brief,
        DraftComparisonService $draftComparisonService,
        GenerationPricing $pricing,
        CreditWalletService $creditWalletService,
    ): View {
        $this->authorize('create', DraftComparison::class);
        $this->authorize('generateDraft', $brief);

        $brief->load([
            'clientSite',
            'creator',
            'draftComparisons' => fn ($query) => $query->latest('created_at')->limit(8),
        ]);

        $generationType = $this->generationTypeForBrief($brief);
        $outputTokenOptions = $pricing->outputTokenOptions($generationType);
        $draftCompareCapabilities = $draftComparisonService->compareCapabilitiesForBrief($brief);
        $modelOptions = $draftComparisonService->availableModelOptionsForBrief($brief);
        $defaultModelKeys = collect($modelOptions)
            ->pluck('key')
            ->take(max(1, min(2, (int) ($draftCompareCapabilities['max_models'] ?? 2))))
            ->values()
            ->all();

        $draftCompareModeLabels = [
            'compare_two' => 'Compare 2 models',
            'compare_multi' => 'Compare multiple models',
        ];

        return view('app.briefs.compare-setup', [
            'brief' => $brief,
            'draftCompareModelOptions' => $modelOptions,
            'draftCompareDefaultModelKeys' => $defaultModelKeys,
            'draftCompareModes' => collect((array) ($draftCompareCapabilities['allowed_modes'] ?? []))
                ->mapWithKeys(fn (string $mode): array => [$mode => (string) ($draftCompareModeLabels[$mode] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $mode)))])
                ->all(),
            'draftCompareCapabilities' => $draftCompareCapabilities,
            'outputTokenOptions' => $outputTokenOptions,
            'estimatedCredits' => [
                'standard' => $pricing->requiredCredits($generationType, $outputTokenOptions['standard']),
                'long' => $pricing->requiredCredits($generationType, $outputTokenOptions['long']),
                'max' => $pricing->requiredCredits($generationType, $outputTokenOptions['max']),
            ],
            'availableCredits' => $creditWalletService->getAvailableForClientSite((string) $brief->client_site_id),
        ]);
    }

    public function store(
        StoreDraftComparisonRequest $request,
        Brief $brief,
        DraftComparisonService $draftComparisonService,
    ): RedirectResponse {
        $this->authorize('create', DraftComparison::class);
        $this->authorize('generateDraft', $brief);

        $validated = $request->validated();

        try {
            $comparison = $draftComparisonService->createAndQueue(
                brief: $brief,
                user: $request->user(),
                mode: (string) $validated['mode'],
                selectedModelKeys: (array) ($validated['model_keys'] ?? []),
                requestedMaxOutputTokens: isset($validated['requested_max_output_tokens'])
                    ? (int) $validated['requested_max_output_tokens']
                    : null,
                compareScope: isset($validated['compare_scope'])
                    ? (string) $validated['compare_scope']
                    : null,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['draft_compare' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.content.workspace.compare.show', [$brief, $comparison])
            ->with('status', 'Draft compare generation queued.');
    }

    public function estimate(
        EstimateDraftComparisonRequest $request,
        Brief $brief,
        DraftComparisonService $draftComparisonService,
        CreditWalletService $creditWalletService,
    ): JsonResponse {
        $this->authorize('create', DraftComparison::class);
        $this->authorize('generateDraft', $brief);

        $validated = $request->validated();
        $estimate = $draftComparisonService->estimateForModels(
            brief: $brief,
            mode: (string) $validated['mode'],
            selectedModelKeys: (array) ($validated['model_keys'] ?? []),
            requestedMaxOutputTokens: isset($validated['requested_max_output_tokens'])
                ? (int) $validated['requested_max_output_tokens']
                : null,
            compareScope: isset($validated['compare_scope'])
                ? (string) $validated['compare_scope']
                : null,
        );

        return response()->json([
            'data' => $estimate,
            'available_credits' => $creditWalletService->getAvailableForClientSite((string) $brief->client_site_id),
        ]);
    }

    public function start(
        StartDraftComparisonRequest $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonService $draftComparisonService,
    ): RedirectResponse {
        $this->authorize('start', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);

        try {
            $draftComparisonService->startComparison($comparison, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['draft_compare' => $exception->getMessage()]);
        }

        return back()->with('status', 'Draft compare generation started.');
    }

    public function show(
        Request $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonProgressService $draftComparisonProgressService,
        DraftComparisonMetricResolver $metricResolver,
        DraftScoreExpectationResolver $scoreExpectationResolver,
        DraftComparisonService $draftComparisonService,
        HybridDraftEligibilityService $hybridEligibilityService,
        DraftComparisonWinnerService $winnerService,
    ): View {
        $this->authorize('view', $brief);
        $this->authorize('view', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);
        $this->refreshComparisonProgress($comparison, $draftComparisonProgressService);

        $comparison->load([
            'brief',
            'creator',
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'items.draft.content',
            'variants' => fn ($query) => $query->orderBy('sort_order'),
            'variants.scores',
            'variants.draft.content',
            'winnerDraft',
            'hybridDraft',
        ]);

        $variantRows = $this->buildVariantRows($comparison, $metricResolver);
        $successfulRows = collect($variantRows)
            ->filter(fn (array $row): bool => (bool) ($row['is_success'] ?? false))
            ->values()
            ->all();
        $scoreContextProfile = $scoreExpectationResolver->resolveContentProfile($brief);
        $successfulRows = $this->enrichVariantRowsWithContext(
            rows: $successfulRows,
            profile: $scoreContextProfile,
            resolver: $scoreExpectationResolver,
        );
        $failedRows = collect($variantRows)
            ->filter(fn (array $row): bool => (bool) ($row['is_failed'] ?? false))
            ->values()
            ->all();
        $scoreMatrixMetrics = $this->scoreMatrixMetricMap();
        $scoreMatrixRows = $this->buildScoreMatrixRows(
            successfulRows: $successfulRows,
            metricMap: $scoreMatrixMetrics,
            profile: $scoreContextProfile,
            resolver: $scoreExpectationResolver,
        );

        $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
        $scoringSummary = is_array(data_get($summary, 'scoring')) ? data_get($summary, 'scoring') : [];
        $recommendation = is_array(data_get($summary, 'recommendation')) ? data_get($summary, 'recommendation') : [];
        $trustSignals = is_array(data_get($summary, 'trust')) ? data_get($summary, 'trust') : [];
        $insights = is_array(data_get($scoringSummary, 'insights')) ? data_get($scoringSummary, 'insights') : [];

        $total = max(0, (int) $comparison->items_total);
        $done = max(0, (int) $comparison->items_done);
        $failed = max(0, (int) $comparison->items_failed);
        $progressPercent = $total > 0
            ? (int) round(min(100, (($done + $failed) / $total) * 100))
            : 0;
        $isTerminal = in_array((string) $comparison->status, DraftComparison::TERMINAL_STATUSES, true);
        if ($isTerminal && $successfulRows !== [] && ! is_array(data_get($recommendation, 'suggested_winner'))) {
            $recommendation = $winnerService->recommend($comparison);
        }

        $draftCompareCapabilities = $draftComparisonService->compareCapabilitiesForBrief($brief);
        $hybridFeatureEnabled = (bool) ($draftCompareCapabilities['hybrid_enabled'] ?? false);
        $hybridEligibility = $hybridEligibilityService->checkEligibility($comparison);

        return view('app.briefs.compare-show', [
            'brief' => $brief,
            'comparison' => $comparison,
            'items' => $comparison->items,
            'variantRows' => $variantRows,
            'successfulVariantRows' => $successfulRows,
            'failedVariantRows' => $failedRows,
            'comparisonSummary' => $summary,
            'comparisonScoringSummary' => $scoringSummary,
            'comparisonRecommendation' => $recommendation,
            'comparisonTrustSignals' => $trustSignals,
            'comparisonInsights' => $insights,
            'progressPercent' => $progressPercent,
            'isTerminal' => $isTerminal,
            'canGenerateHybrid' => (bool) ($hybridEligibility['eligible'] ?? false),
            'hybridFeatureEnabled' => $hybridFeatureEnabled,
            'hybridEligibility' => $hybridEligibility,
            'draftCompareCapabilities' => $draftCompareCapabilities,
            'scoreMatrixMetrics' => $scoreMatrixMetrics,
            'scoreMatrixRows' => $scoreMatrixRows,
            'scoreContextProfile' => $scoreContextProfile,
        ]);
    }

    public function status(
        Request $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonProgressService $draftComparisonProgressService,
    ): JsonResponse {
        $this->authorize('viewStatus', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);
        $this->refreshComparisonProgress($comparison, $draftComparisonProgressService);

        $comparison->load([
            'variants' => fn ($query) => $query->orderBy('sort_order'),
            'variants.draft',
            'winnerDraft',
            'hybridDraft',
        ]);

        return response()->json([
            'data' => [
                'id' => (string) $comparison->id,
                'brief_id' => (string) $comparison->brief_id,
                'status' => (string) $comparison->status,
                'mode' => (string) $comparison->mode,
                'compare_scope' => (string) data_get($comparison->meta, 'compare_scope', \App\Services\DraftComparison\DraftComparisonService::COMPARE_SCOPE_FULL_DRAFT),
                'items_total' => (int) $comparison->items_total,
                'items_done' => (int) $comparison->items_done,
                'items_failed' => (int) $comparison->items_failed,
                'requested_model_count' => (int) $comparison->requested_model_count,
                'estimated_credit_cost' => (int) ($comparison->estimated_credit_cost ?? $comparison->estimated_credits ?? 0),
                'reserved_credit_amount' => (int) ($comparison->reserved_credit_amount ?? 0),
                'final_credit_cost' => (int) ($comparison->final_credit_cost ?? 0),
                'credits_used' => (int) ($comparison->credits_used ?? 0),
                'winner_draft_id' => $comparison->winner_draft_id ? (string) $comparison->winner_draft_id : null,
                'hybrid_draft_id' => $comparison->hybrid_draft_id ? (string) $comparison->hybrid_draft_id : null,
                'hybrid_status' => (string) ($comparison->hybrid_status ?? 'idle'),
                'hybrid_last_error' => $comparison->hybrid_last_error,
                'started_at' => $comparison->started_at?->toIso8601String(),
                'completed_at' => $comparison->completed_at?->toIso8601String(),
                'failed_at' => $comparison->failed_at?->toIso8601String(),
                'trust' => is_array(data_get($comparison->comparison_summary_json, 'trust'))
                    ? [
                        'version' => (string) data_get($comparison->comparison_summary_json, 'trust.version', ''),
                        'recommendation_explanation' => (string) data_get($comparison->comparison_summary_json, 'trust.recommendation_explanation', ''),
                        'prompt_consistency' => data_get($comparison->comparison_summary_json, 'trust.prompt_consistency', []),
                        'usage_summary' => data_get($comparison->comparison_summary_json, 'trust.usage_summary', []),
                    ]
                    : null,
                'variants' => $comparison->variants->map(static function (DraftComparisonVariant $variant): array {
                    return [
                        'id' => (string) $variant->id,
                        'provider_key' => (string) $variant->provider_key,
                        'model_key' => (string) $variant->model_key,
                        'display_name' => $variant->display_name,
                        'status' => (string) $variant->status,
                        'draft_id' => $variant->draft_id ? (string) $variant->draft_id : null,
                        'input_tokens' => $variant->input_tokens,
                        'output_tokens' => $variant->output_tokens,
                        'credit_cost' => $variant->credit_cost,
                        'latency_ms' => $variant->latency_ms,
                        'error_message' => $variant->error_message,
                        'started_at' => $variant->started_at?->toIso8601String(),
                        'completed_at' => $variant->completed_at?->toIso8601String(),
                        'prompt_snapshot_summary' => [
                            'captured_at' => data_get($variant->prompt_snapshot_json, 'captured_at'),
                            'shared_inputs_hash' => data_get($variant->prompt_snapshot_json, 'shared_inputs_hash'),
                            'brief_title' => data_get($variant->prompt_snapshot_json, 'shared_inputs.brief.title'),
                            'language' => data_get($variant->prompt_snapshot_json, 'shared_inputs.brief.language'),
                            'primary_keyword' => data_get($variant->prompt_snapshot_json, 'shared_inputs.keywords.primary'),
                        ],
                    ];
                })->values()->all(),
            ],
        ]);
    }

    public function selectWinner(
        SelectComparisonWinnerRequest $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonService $draftComparisonService,
    ): RedirectResponse {
        $this->authorize('selectWinner', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);

        try {
            $draftComparisonService->selectWinner(
                comparison: $comparison,
                draftId: (string) $request->validated('draft_id'),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['draft_compare' => $exception->getMessage()]);
        }

        return back()->with('status', 'Winner selected.');
    }

    public function estimateHybrid(
        Request $request,
        Brief $brief,
        DraftComparison $comparison,
        \App\Services\DraftComparison\HybridDraftEligibilityService $eligibilityService,
    ): JsonResponse {
        $this->authorize('view', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);

        $eligibility = $eligibilityService->checkEligibility($comparison);

        return response()->json([
            'data' => [
                'comparison_id' => (string) $comparison->id,
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'],
                'reason_message' => $eligibility['reason_message'],
                'can_retry' => $eligibility['can_retry'],
                'successful_variant_count' => $eligibility['successful_variant_count'],
                'required_variant_count' => $eligibility['required_variant_count'],
                'estimated_credit_cost' => $eligibility['estimated_credit_cost'],
                'available_credits' => $eligibility['available_credits'],
                'hybrid_status' => (string) ($comparison->hybrid_status ?? 'idle'),
                'hybrid_draft_id' => $comparison->hybrid_draft_id ? (string) $comparison->hybrid_draft_id : null,
            ],
        ]);
    }

    public function queueHybrid(
        CreateHybridDraftComparisonRequest $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonService $draftComparisonService,
    ): RedirectResponse {
        $this->authorize('queueHybrid', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);

        try {
            $draftComparisonService->queueHybrid($comparison, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['draft_compare' => $exception->getMessage()]);
        }

        return back()->with('status', 'Hybrid draft queued.');
    }

    public function openVariantDraft(
        Request $request,
        Brief $brief,
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
    ): RedirectResponse {
        $this->authorize('openVariantDraft', $comparison);
        $this->abortIfComparisonDoesNotBelongToBrief($brief, $comparison);

        if ((string) $variant->draft_comparison_id !== (string) $comparison->id) {
            abort(404);
        }

        if (! $variant->draft_id) {
            return back()->withErrors(['draft_compare' => 'This comparison variant does not have a generated draft yet.']);
        }

        return redirect()->route('app.drafts.show', $variant->draft_id);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildVariantRows(DraftComparison $comparison, DraftComparisonMetricResolver $metricResolver): array
    {
        $itemByDraftId = $comparison->items
            ->filter(fn (DraftComparisonItem $item): bool => $item->draft_id !== null)
            ->keyBy(fn (DraftComparisonItem $item): string => (string) $item->draft_id);
        $itemByProviderModel = $comparison->items
            ->keyBy(fn (DraftComparisonItem $item): string => $metricResolver->providerModelKey((string) $item->provider, (string) $item->model));
        $legacyMetricsByProviderModel = $metricResolver->legacyMetricsByProviderModel($comparison);

        if ($comparison->variants->isNotEmpty()) {
            return $comparison->variants
                ->map(function (DraftComparisonVariant $variant) use ($itemByDraftId, $itemByProviderModel, $legacyMetricsByProviderModel, $metricResolver): array {
                    $draft = $variant->draft;
                    $matchKey = $metricResolver->providerModelKey((string) $variant->provider_key, (string) $variant->model_key);
                    $legacyItem = $draft
                        ? $itemByDraftId->get((string) $draft->id)
                        : $itemByProviderModel->get($matchKey);
                    $metrics = $metricResolver->metricsForVariant($variant, $legacyMetricsByProviderModel);
                    if ($metrics === [] && $legacyItem && is_array($legacyItem->metrics)) {
                        $metrics = $legacyItem->metrics;
                    }

                    $scoreDetails = $variant->scores
                        ->mapWithKeys(static function ($score): array {
                            return [
                                (string) $score->metric_key => array_filter([
                                    'label' => (string) $score->metric_label,
                                    'group' => $score->metric_group,
                                    'source_type' => $score->source_type,
                                    'numeric_score' => is_numeric($score->numeric_score) ? round((float) $score->numeric_score, 3) : null,
                                    'text_score' => $score->text_score,
                                    'explanation' => $score->explanation,
                                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                            ];
                        })
                        ->all();

                    $wordCount = $this->resolveWordCount($draft?->content_html, $metrics['word_count'] ?? null);
                    $readingTime = $this->resolveReadingTime($wordCount, $metrics);
                    $promptSnapshotSummary = $this->promptSnapshotSummary(is_array($variant->prompt_snapshot_json) ? $variant->prompt_snapshot_json : []);

                    return [
                        'id' => (string) $variant->id,
                        'is_variant_row' => true,
                        'provider' => (string) $variant->provider_key,
                        'model' => (string) $variant->model_key,
                        'display_name' => (string) ($variant->display_name ?: Str::headline((string) $variant->provider_key) . ' - ' . (string) $variant->model_key),
                        'status' => (string) $variant->status,
                        'status_label' => Str::headline(str_replace('_', ' ', (string) $variant->status)),
                        'is_success' => (string) $variant->status === DraftComparisonVariant::STATUS_COMPLETED && $draft !== null,
                        'is_failed' => in_array((string) $variant->status, [DraftComparisonVariant::STATUS_FAILED, DraftComparisonVariant::STATUS_CANCELLED], true),
                        'is_processing' => in_array((string) $variant->status, [DraftComparisonVariant::STATUS_PENDING, DraftComparisonVariant::STATUS_QUEUED, DraftComparisonVariant::STATUS_PROCESSING], true),
                        'error_message' => (string) ($variant->error_message ?: ($legacyItem?->error_message ?? '')),
                        'draft_id' => $draft ? (string) $draft->id : null,
                        'draft_title' => (string) ($draft?->title ?: 'Untitled draft'),
                        'draft_excerpt' => $this->draftExcerpt($draft?->content_html),
                        'draft_html' => (string) ($draft?->content_html ?? ''),
                        'word_count' => $wordCount,
                        'reading_time' => $readingTime,
                        'input_tokens' => $variant->input_tokens ?: ($legacyItem?->input_tokens ?? null),
                        'output_tokens' => $variant->output_tokens ?: ($legacyItem?->output_tokens ?? null),
                        'credit_cost' => $variant->credit_cost ?: ($legacyItem?->charged_credits ?: $legacyItem?->credit_cost),
                        'latency_ms' => $variant->latency_ms,
                        'metrics' => $metrics,
                        'score_chips' => $this->scoreChips($metrics),
                        'score_details' => $scoreDetails,
                        'score_source_summary' => $this->scoreSourceSummary($scoreDetails),
                        'prompt_snapshot_summary' => $promptSnapshotSummary,
                    ];
                })
                ->values()
                ->all();
        }

        return $comparison->items
            ->map(function (DraftComparisonItem $item): array {
                $draft = $item->draft;
                $status = $this->normalizeLegacyItemStatus((string) $item->status);
                $metrics = is_array($item->metrics) ? $item->metrics : [];
                $wordCount = $this->resolveWordCount($draft?->content_html, $metrics['word_count'] ?? null);
                $readingTime = $this->resolveReadingTime($wordCount, $metrics);

                return [
                    'id' => (string) $item->id,
                    'is_variant_row' => false,
                    'provider' => (string) $item->provider,
                    'model' => (string) $item->model,
                    'display_name' => Str::headline((string) $item->provider) . ' - ' . (string) $item->model,
                    'status' => $status,
                    'status_label' => Str::headline(str_replace('_', ' ', $status)),
                    'is_success' => $status === DraftComparisonVariant::STATUS_COMPLETED && $draft !== null,
                    'is_failed' => in_array($status, [DraftComparisonVariant::STATUS_FAILED, DraftComparisonVariant::STATUS_CANCELLED], true),
                    'is_processing' => in_array($status, [DraftComparisonVariant::STATUS_PENDING, DraftComparisonVariant::STATUS_QUEUED, DraftComparisonVariant::STATUS_PROCESSING], true),
                    'error_message' => (string) ($item->error_message ?? ''),
                    'draft_id' => $draft ? (string) $draft->id : null,
                    'draft_title' => (string) ($draft?->title ?: 'Untitled draft'),
                    'draft_excerpt' => $this->draftExcerpt($draft?->content_html),
                    'draft_html' => (string) ($draft?->content_html ?? ''),
                    'word_count' => $wordCount,
                    'reading_time' => $readingTime,
                    'input_tokens' => $item->input_tokens,
                    'output_tokens' => $item->output_tokens,
                    'credit_cost' => $item->charged_credits ?: $item->credit_cost,
                    'latency_ms' => null,
                    'metrics' => $metrics,
                    'score_chips' => $this->scoreChips($metrics),
                    'score_details' => [],
                    'score_source_summary' => [],
                    'prompt_snapshot_summary' => [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $metrics
     * @return array<int,array{key:string,label:string,value:string}>
     */
    private function scoreChips(array $metrics): array
    {
        $map = [
            'seo_score' => 'SEO',
            'ai_seo_score' => 'AI SEO',
            'brand_voice_match' => 'Brand',
            'readability_score' => 'Readability',
            'cta_strength' => 'CTA',
            'conversion_focus' => 'Conversion',
        ];

        return collect($map)
            ->map(function (string $label, string $key) use ($metrics): ?array {
                $value = $metrics[$key] ?? null;
                if (! is_numeric($value)) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $label,
                    'value' => number_format((float) $value, 1),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function scoreMatrixMetricMap(): array
    {
        return [
            'seo_score' => 'SEO Score',
            'ai_seo_score' => 'AI SEO Score',
            'brand_voice_match' => 'Brand Voice Match',
            'readability_score' => 'Readability',
            'structure_quality' => 'Structure Quality',
            'cta_strength' => 'CTA Strength',
            'conversion_focus' => 'Conversion Focus',
            'topical_coverage' => 'Topical Coverage',
            'entity_coverage' => 'Entity Coverage',
            'factual_confidence' => 'Factual Confidence',
            'word_count' => 'Word Count',
            'reading_time' => 'Reading Time (min)',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $profile
     * @return array<int,array<string,mixed>>
     */
    private function enrichVariantRowsWithContext(
        array $rows,
        array $profile,
        DraftScoreExpectationResolver $resolver,
    ): array {
        return collect($rows)
            ->map(function (array $row) use ($profile, $resolver): array {
                $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
                $row['strategy_fit'] = $resolver->strategyFit($metrics, $profile);
                $row['contextual_metric_interpretations'] = [
                    'cta_strength' => $resolver->interpretMetric('cta_strength', $metrics['cta_strength'] ?? null, $profile),
                    'readability_score' => $resolver->interpretMetric('readability_score', $metrics['readability_score'] ?? null, $profile),
                    'structure_quality' => $resolver->interpretMetric('structure_quality', $metrics['structure_quality'] ?? null, $profile),
                ];

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $successfulRows
     * @param array<string,string> $metricMap
     * @param array<string,mixed> $profile
     * @return array<int,array{
     *   key:string,
     *   label:string,
     *   is_contextual:bool,
     *   helper_text:string,
     *   cells:array<int,array{
     *      variant_id:string,
     *      provider:string,
     *      model:string,
     *      raw_value:mixed,
     *      is_numeric:bool,
     *      display_value:?string,
     *      interpretation:array<string,mixed>
     *   }>
     * }>
     */
    private function buildScoreMatrixRows(
        array $successfulRows,
        array $metricMap,
        array $profile,
        DraftScoreExpectationResolver $resolver,
    ): array {
        return collect($metricMap)
            ->map(function (string $label, string $metricKey) use ($successfulRows, $profile, $resolver): array {
                $isContextual = $resolver->supportsMetric($metricKey);
                $helperText = $resolver->helperTextForMetric($metricKey, $profile);
                $cells = collect($successfulRows)
                    ->map(function (array $row) use ($metricKey, $profile, $resolver, $isContextual): array {
                        $rawValue = data_get($row, 'metrics.' . $metricKey);
                        $interpretation = $isContextual
                            ? $resolver->interpretMetric($metricKey, $rawValue, $profile)
                            : [
                                'metric_key' => $metricKey,
                                'actual_score' => is_numeric($rawValue) ? round((float) $rawValue, 1) : null,
                                'expected_min' => null,
                                'expected_max' => null,
                                'expected_range_label' => 'Reference metric',
                                'status_level' => 'acceptable',
                                'status_label' => 'Reference signal',
                                'explanation' => 'Used as a directional comparison signal for strategy review.',
                                'alignment_points' => 60,
                                'is_contextual' => false,
                            ];

                        return [
                            'variant_id' => (string) ($row['id'] ?? ''),
                            'provider' => (string) ($row['provider'] ?? ''),
                            'model' => (string) ($row['model'] ?? ''),
                            'raw_value' => $rawValue,
                            'is_numeric' => is_numeric($rawValue),
                            'display_value' => $this->formatScoreMatrixValue($metricKey, $rawValue),
                            'interpretation' => $interpretation,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $metricKey,
                    'label' => $label,
                    'is_contextual' => $isContextual,
                    'helper_text' => $helperText,
                    'cells' => $cells,
                ];
            })
            ->values()
            ->all();
    }

    private function formatScoreMatrixValue(string $metricKey, mixed $rawValue): ?string
    {
        if (! is_numeric($rawValue)) {
            return null;
        }

        $numericValue = (float) $rawValue;

        if ($metricKey === 'word_count') {
            return number_format((int) round($numericValue));
        }

        return number_format($numericValue, 1);
    }

    private function resolveWordCount(?string $html, mixed $metricWordCount): int
    {
        if (is_numeric($metricWordCount)) {
            return max(0, (int) round((float) $metricWordCount));
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $html)));
        if ($plain === '') {
            return 0;
        }

        return max(0, str_word_count($plain));
    }

    /**
     * @param array<string,mixed> $metrics
     */
    private function resolveReadingTime(int $wordCount, array $metrics): ?int
    {
        $readingMetric = $metrics['reading_time'] ?? $metrics['reading_time_minutes'] ?? null;
        if (is_numeric($readingMetric)) {
            return max(1, (int) round((float) $readingMetric));
        }

        if ($wordCount <= 0) {
            return null;
        }

        return max(1, (int) ceil($wordCount / 220));
    }

    private function draftExcerpt(?string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $html)));
        if ($text === '') {
            return '';
        }

        return Str::limit($text, 260);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function promptSnapshotSummary(array $snapshot): array
    {
        if ($snapshot === []) {
            return [];
        }

        return array_filter([
            'captured_at' => data_get($snapshot, 'captured_at'),
            'shared_inputs_hash' => data_get($snapshot, 'shared_inputs_hash'),
            'brief_title' => data_get($snapshot, 'shared_inputs.brief.title'),
            'language' => data_get($snapshot, 'shared_inputs.brief.language'),
            'primary_keyword' => data_get($snapshot, 'shared_inputs.keywords.primary'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,array<string,mixed>> $scoreDetails
     * @return array<string,int>
     */
    private function scoreSourceSummary(array $scoreDetails): array
    {
        return collect($scoreDetails)
            ->map(fn (array $details): string => trim((string) ($details['source_type'] ?? '')))
            ->filter()
            ->countBy()
            ->map(fn (int $count): int => (int) $count)
            ->all();
    }

    private function normalizeLegacyItemStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'generated', 'completed' => DraftComparisonVariant::STATUS_COMPLETED,
            'generating', 'processing' => DraftComparisonVariant::STATUS_PROCESSING,
            'queued' => DraftComparisonVariant::STATUS_QUEUED,
            'pending' => DraftComparisonVariant::STATUS_PENDING,
            'failed' => DraftComparisonVariant::STATUS_FAILED,
            'cancelled', 'canceled' => DraftComparisonVariant::STATUS_CANCELLED,
            default => DraftComparisonVariant::STATUS_PENDING,
        };
    }

    private function abortIfComparisonDoesNotBelongToBrief(Brief $brief, DraftComparison $comparison): void
    {
        if ((string) $comparison->brief_id !== (string) $brief->id) {
            abort(404);
        }
    }

    private function refreshComparisonProgress(
        DraftComparison $comparison,
        DraftComparisonProgressService $draftComparisonProgressService,
    ): void {
        if ($comparison->variants()->exists()) {
            $comparison->recalculateAggregateStatus();

            return;
        }

        $draftComparisonProgressService->syncComparison((string) $comparison->id);
    }

    private function generationTypeForBrief(Brief $brief): string
    {
        return match ((string) ($brief->output_type ?? 'kb_article')) {
            'kb_article', 'article' => GenerationPricing::TYPE_ARTICLE,
            default => GenerationPricing::TYPE_ARTICLE,
        };
    }
}
