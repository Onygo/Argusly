<?php

namespace App\Services\DraftComparison;

use App\Jobs\DraftComparison\StartDraftComparisonJob;
use App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonVariant;
use App\Models\User;
use App\Services\Briefs\BriefPromptBuilder;
use App\Services\Credits\GenerationPricing;
use App\Services\CreditWalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DraftComparisonService
{
    public const COMPARE_SCOPE_FULL_DRAFT = 'full_draft';

    public const COMPARE_SCOPE_INTRO_ONLY = 'intro_only';

    public const COMPARE_SCOPE_HEADLINE_ONLY = 'headline_only';

    public const COMPARE_SCOPE_SECTION_COMPARE = 'section_compare';

    /**
     * Reserved for future UI/API expansion. Current UI defaults to full_draft.
     *
     * @var array<int,string>
     */
    public const COMPARE_SCOPES = [
        self::COMPARE_SCOPE_FULL_DRAFT,
        self::COMPARE_SCOPE_INTRO_ONLY,
        self::COMPARE_SCOPE_HEADLINE_ONLY,
        self::COMPARE_SCOPE_SECTION_COMPARE,
    ];

    private const ACTIVE_STATUSES = [
        DraftComparison::STATUS_PENDING,
        DraftComparison::STATUS_QUEUED,
        DraftComparison::STATUS_PROCESSING,
        'running',
    ];

    public function __construct(
        private readonly DraftComparisonModelCatalog $modelCatalog,
        private readonly DraftComparisonFeatureGate $comparisonFeatureGate,
        private readonly BriefPromptBuilder $promptBuilder,
        private readonly GenerationPricing $pricing,
        private readonly CreditWalletService $creditWalletService,
        private readonly DraftComparisonCreditEstimator $creditEstimator,
        private readonly DraftComparisonCreditManager $creditManager,
    ) {}

    /**
     * @return array<int, array{key:string,provider:string,provider_label:string,model:string,label:string}>
     */
    public function availableModelOptions(): array
    {
        return $this->modelCatalog->options();
    }

    /**
     * @return array<int, array{key:string,provider:string,provider_label:string,model:string,label:string,is_premium:bool}>
     */
    public function availableModelOptionsForBrief(Brief $brief): array
    {
        $capabilities = $this->comparisonFeatureGate->capabilitiesForBrief($brief);
        $options = $this->modelCatalog->options();

        return $this->comparisonFeatureGate->filterModelOptionsForCapabilities($options, $capabilities);
    }

    /**
     * @return array{
     *   enabled:bool,
     *   max_models:int,
     *   hybrid_enabled:bool,
     *   scoring_enabled:bool,
     *   premium_models_enabled:bool,
     *   allowed_modes:array<int,string>,
     *   compare_mode_enabled:bool,
     *   blocked_reason:?string
     * }
     */
    public function compareCapabilitiesForBrief(Brief $brief): array
    {
        return $this->comparisonFeatureGate->capabilitiesForBrief($brief);
    }

    public function estimateCreditsForSelection(Brief $brief, int $selectedCount, ?int $requestedMaxOutputTokens): array
    {
        $fallbackProvider = (string) config('llm.default_provider', 'openai');
        $fallbackModel = (string) config('llm.providers.' . $fallbackProvider . '.default_model', 'default');
        $selections = collect(range(1, max(0, $selectedCount)))
            ->map(fn (): array => ['provider' => $fallbackProvider, 'model' => $fallbackModel])
            ->all();
        $estimate = $this->creditEstimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $requestedMaxOutputTokens,
            includeScoring: false,
            includeHybrid: false,
        );

        return [
            'generation_type' => (string) $estimate['generation_type'],
            'requested_max_output_tokens' => (int) $estimate['requested_max_output_tokens'],
            'per_draft_credits' => (int) $estimate['per_model_baseline_credits'],
            'total_credits' => (int) $estimate['estimated_credit_cost'],
            'estimated_credit_cost' => (int) $estimate['estimated_credit_cost'],
            'estimated_input_tokens' => (int) $estimate['estimated_input_tokens'],
            'estimated_output_tokens' => (int) $estimate['estimated_output_tokens'],
            'requested_model_count' => (int) $estimate['requested_model_count'],
            'variants' => (array) $estimate['variants'],
        ];
    }

    /**
     * @param array<int, mixed> $selectedModelKeys
     * @return array<string, mixed>
     */
    public function estimateForModels(
        Brief $brief,
        string $mode,
        array $selectedModelKeys,
        ?int $requestedMaxOutputTokens = null,
        ?string $compareScope = null,
    ): array {
        $normalizedMode = $this->normalizeMode($mode);
        $normalizedScope = $this->normalizeCompareScope($compareScope);
        $capabilities = $this->comparisonFeatureGate->capabilitiesForBrief($brief);
        $this->assertCompareCapabilityForMode($capabilities, $normalizedMode);

        $allowedOptions = $this->comparisonFeatureGate->filterModelOptionsForCapabilities(
            $this->modelCatalog->options(),
            $capabilities,
        );
        $allowedSelections = collect($allowedOptions)->keyBy('key');
        $selections = collect($selectedModelKeys)
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique()
            ->map(fn (string $key): ?array => $allowedSelections->get($key))
            ->filter()
            ->values()
            ->all();

        $this->guardSelectionCount($normalizedMode, count($selections), (int) $capabilities['max_models']);

        $estimate = $this->creditEstimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $requestedMaxOutputTokens,
            includeScoring: (bool) $capabilities['scoring_enabled'],
            includeHybrid: false,
        );

        return [
            'mode' => $normalizedMode,
            'compare_scope' => $normalizedScope,
            'generation_type' => (string) $estimate['generation_type'],
            'requested_max_output_tokens' => (int) $estimate['requested_max_output_tokens'],
            'per_draft_credits' => (int) $estimate['per_model_baseline_credits'],
            'total_credits' => (int) $estimate['estimated_credit_cost'],
            'estimated_credit_cost' => (int) $estimate['estimated_credit_cost'],
            'estimated_input_tokens' => (int) $estimate['estimated_input_tokens'],
            'estimated_output_tokens' => (int) $estimate['estimated_output_tokens'],
            'requested_model_count' => (int) $estimate['requested_model_count'],
            'selected_model_keys' => collect($selections)
                ->map(fn (array $selection): string => (string) ($selection['key'] ?? ''))
                ->filter()
                ->values()
                ->all(),
            'variants' => (array) ($estimate['variants'] ?? []),
        ];
    }

    /**
     * @param array<int, mixed> $selectedModelKeys
     */
    public function createAndQueue(
        Brief $brief,
        User $user,
        string $mode,
        array $selectedModelKeys,
        ?int $requestedMaxOutputTokens = null,
        ?string $compareScope = null,
    ): DraftComparison {
        $normalizedMode = $this->normalizeMode($mode);
        $normalizedScope = $this->normalizeCompareScope($compareScope);
        $capabilities = $this->comparisonFeatureGate->capabilitiesForBrief($brief);
        $this->assertCompareCapabilityForMode($capabilities, $normalizedMode);

        $allowedOptions = $this->comparisonFeatureGate->filterModelOptionsForCapabilities(
            $this->modelCatalog->options(),
            $capabilities,
        );
        $allowedSelections = collect($allowedOptions)->keyBy('key');
        $selections = collect($selectedModelKeys)
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique()
            ->map(fn (string $key): ?array => $allowedSelections->get($key))
            ->filter()
            ->values()
            ->all();

        $this->guardSelectionCount($normalizedMode, count($selections), (int) $capabilities['max_models']);

        $estimate = $this->creditEstimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $requestedMaxOutputTokens,
            includeScoring: (bool) $capabilities['scoring_enabled'],
            includeHybrid: false,
        );

        return DB::transaction(function () use (
            $brief,
            $user,
            $normalizedMode,
            $normalizedScope,
            $selections,
            $estimate,
            $capabilities
        ): DraftComparison {
            $lockedBrief = Brief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();

            if ((string) $lockedBrief->status === 'archived') {
                throw new RuntimeException('Archived briefs cannot generate drafts.');
            }

            $content = $this->ensureContentForBrief($lockedBrief, (int) $user->id);

            $availableCredits = $this->creditWalletService->getAvailableForClientSite((string) $lockedBrief->client_site_id);
            if ($availableCredits < (int) $estimate['estimated_credit_cost']) {
                throw new RuntimeException(sprintf(
                    'Insufficient credits. Required: %d, available: %d.',
                    (int) $estimate['estimated_credit_cost'],
                    $availableCredits
                ));
            }

            $fingerprint = $this->fingerprint($lockedBrief, $normalizedMode, $selections, (int) $estimate['requested_max_output_tokens']);
            $existing = $this->findActiveDuplicate($lockedBrief, $fingerprint);
            if ($existing) {
                return $existing->load(['items.draft', 'winnerDraft', 'hybridDraft']);
            }

            $comparison = DraftComparison::query()->create([
                'id' => (string) Str::uuid(),
                'brief_id' => (string) $lockedBrief->id,
                'content_id' => (string) $content->id,
                'client_site_id' => (string) $lockedBrief->client_site_id,
                'created_by_user_id' => (int) $user->id,
                'mode' => $normalizedMode,
                'status' => DraftComparison::STATUS_PENDING,
                'requested_models_json' => array_map(
                    static fn (array $selection): array => [
                        'key' => (string) ($selection['key'] ?? ''),
                        'provider' => (string) ($selection['provider'] ?? ''),
                        'model' => (string) ($selection['model'] ?? ''),
                        'label' => (string) ($selection['label'] ?? ''),
                    ],
                    $selections
                ),
                'requested_model_count' => (int) $estimate['requested_model_count'],
                'estimated_input_tokens' => (int) $estimate['estimated_input_tokens'],
                'estimated_output_tokens' => (int) $estimate['estimated_output_tokens'],
                'estimated_credit_cost' => (int) $estimate['estimated_credit_cost'],
                'requested_max_output_tokens' => (int) $estimate['requested_max_output_tokens'],
                'estimated_credits' => (int) $estimate['estimated_credit_cost'],
                'credits_used' => 0,
                'items_total' => count($selections),
                'items_done' => 0,
                'items_failed' => 0,
                'meta' => [
                    'fingerprint' => $fingerprint,
                    'compare_scope' => $normalizedScope,
                    'generation_type' => (string) $estimate['generation_type'],
                    'per_draft_credits' => (int) $estimate['per_model_baseline_credits'],
                    'selected_models' => $selections,
                    'billing_scope' => 'comparison',
                    'scoring_enabled' => (bool) $capabilities['scoring_enabled'],
                    'hybrid_enabled' => (bool) $capabilities['hybrid_enabled'],
                    'premium_models_enabled' => (bool) $capabilities['premium_models_enabled'],
                ],
            ]);

            $this->creditManager->reserveForComparison(
                comparison: $comparison,
                amount: (int) $estimate['estimated_credit_cost'],
                userId: (int) $user->id,
                metadata: [
                    'requested_model_count' => (int) $estimate['requested_model_count'],
                    'requested_max_output_tokens' => (int) $estimate['requested_max_output_tokens'],
                ],
            );

            $variantEstimateByKey = collect((array) ($estimate['variants'] ?? []))
                ->keyBy(fn (array $variant): string => (string) $variant['provider'] . ':' . (string) $variant['model']);

            foreach (array_values($selections) as $index => $selection) {
                $variantKey = (string) $selection['provider'] . ':' . (string) $selection['model'];
                $variantEstimate = $variantEstimateByKey->get($variantKey, []);
                $variantCreditCost = max(1, (int) ($variantEstimate['estimated_credit_cost'] ?? $estimate['per_model_baseline_credits']));

                $item = DraftComparisonItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'draft_comparison_id' => (string) $comparison->id,
                    'sort_order' => $index + 1,
                    'provider' => (string) $selection['provider'],
                    'model' => (string) $selection['model'],
                    'status' => 'queued',
                    'credit_cost' => $variantCreditCost,
                ]);

                $draft = $this->createDraftForSelection(
                    brief: $lockedBrief,
                    content: $content,
                    comparison: $comparison,
                    comparisonItem: $item,
                    selection: $selection,
                    estimate: $estimate,
                    variantCreditCost: $variantCreditCost,
                );

                $item->update(['draft_id' => (string) $draft->id]);
            }

            $lockedBrief->status = 'done';
            $lockedBrief->progress = 1.0;
            $lockedBrief->save();

            StartDraftComparisonJob::dispatch((string) $comparison->id)
                ->onQueue('generation')
                ->afterCommit();

            return $comparison->fresh(['items.draft', 'winnerDraft', 'hybridDraft']);
        });
    }

    public function selectWinner(DraftComparison $comparison, string $draftId): DraftComparison
    {
        $item = $comparison->items()
            ->where('draft_id', $draftId)
            ->where('status', 'generated')
            ->first();

        if (! $item) {
            throw new RuntimeException('Selected draft is not available as a generated comparison item.');
        }

        $comparison->winner_draft_id = $draftId;
        $comparison->save();

        return $comparison->fresh(['items.draft', 'winnerDraft', 'hybridDraft']);
    }

    public function startComparison(DraftComparison $comparison, User $user): DraftComparison
    {
        if ($comparison->isTerminal()) {
            throw new RuntimeException('This draft comparison run is already finalized.');
        }

        $brief = $comparison->brief ?: $comparison->brief()->first();
        if (! $brief) {
            throw new RuntimeException('Draft comparison brief context is missing.');
        }

        if ((string) $brief->status === 'archived') {
            throw new RuntimeException('Archived briefs cannot generate drafts.');
        }

        $reserveAmount = max(
            0,
            (int) ($comparison->estimated_credit_cost ?? 0),
            (int) ($comparison->estimated_credits ?? 0),
        );

        $hasReservation = (int) ($comparison->reserved_credit_amount ?? 0) > 0
            || in_array((string) data_get($comparison->comparison_summary_json, 'billing.state', ''), ['reserved', 'captured'], true);

        if (! $hasReservation && $reserveAmount > 0) {
            $availableCredits = $this->creditWalletService->getAvailableForClientSite((string) $comparison->client_site_id);
            if ($availableCredits < $reserveAmount) {
                throw new RuntimeException(sprintf(
                    'Insufficient credits. Required: %d, available: %d.',
                    $reserveAmount,
                    $availableCredits
                ));
            }
        }

        $startedComparison = DB::transaction(function () use ($comparison, $user): DraftComparison {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isTerminal()) {
                throw new RuntimeException('This draft comparison run is already finalized.');
            }

            if ((string) $locked->status === DraftComparison::STATUS_PENDING) {
                $locked->markQueued();
            }

            if (! $locked->created_by_user_id) {
                $locked->created_by_user_id = (int) $user->id;
                $locked->save();
            }

            return $locked;
        });

        StartDraftComparisonJob::dispatch((string) $startedComparison->id)
            ->onQueue('generation')
            ->afterCommit();

        return $startedComparison->fresh(['items.draft', 'variants.draft', 'winnerDraft', 'hybridDraft']);
    }

    public function queueHybrid(DraftComparison $comparison, User $user): DraftComparison
    {
        if (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true)) {
            return $comparison->fresh(['items.draft', 'winnerDraft', 'hybridDraft']);
        }

        if (! $comparison->isTerminal()) {
            throw new RuntimeException('Wait for the comparison to complete before generating a hybrid draft.');
        }

        $capabilities = $this->comparisonFeatureGate->capabilitiesForComparison($comparison);
        if (! $capabilities['enabled']) {
            throw new RuntimeException($this->comparisonFeatureGate->disabledMessage());
        }
        if (! $capabilities['hybrid_enabled']) {
            throw new RuntimeException('Hybrid draft generation is not available on your current plan. Upgrade to unlock hybrid synthesis.');
        }

        $comparison->loadMissing([
            'brief',
            'content',
            'hybridDraft',
            'items.draft',
            'variants.draft',
            'variants.scores',
        ]);

        if ($comparison->hybridDraft && (string) $comparison->hybridDraft->status === 'generated') {
            throw new RuntimeException('A hybrid draft has already been generated for this comparison.');
        }

        $candidates = $this->resolveHybridCandidates($comparison);
        if ($candidates->count() < 2) {
            throw new RuntimeException('Generate at least two successful drafts before creating a hybrid version.');
        }

        $hybridCredits = $this->estimateHybridCredits($comparison, $candidates);
        $availableCredits = $this->creditWalletService->getAvailableForClientSite((string) $comparison->client_site_id);

        if ($availableCredits < $hybridCredits) {
            throw new RuntimeException(sprintf(
                'Insufficient credits. Required: %d, available: %d.',
                $hybridCredits,
                $availableCredits
            ));
        }

        return DB::transaction(function () use ($comparison, $user, $candidates, $hybridCredits): DraftComparison {
            $locked = DraftComparison::query()->whereKey($comparison->id)->lockForUpdate()->firstOrFail();
            $locked->hybrid_status = 'queued';
            $locked->hybrid_last_error = null;
            $locked->hybrid_started_at = null;
            $locked->hybrid_completed_at = null;

            $summary = is_array($locked->comparison_summary_json) ? $locked->comparison_summary_json : [];
            $hybridSummary = is_array($summary['hybrid'] ?? null) ? $summary['hybrid'] : [];
            $hybridSummary['queued_at'] = now()->toIso8601String();
            $hybridSummary['requested_by_user_id'] = (int) $user->id;
            $hybridSummary['estimated_credit_cost'] = $hybridCredits;
            $hybridSummary['source_variant_count'] = $candidates->count();
            $hybridSummary['source_variant_ids'] = $candidates
                ->pluck('variant_id')
                ->filter()
                ->values()
                ->all();
            $hybridSummary['source_draft_ids'] = $candidates
                ->pluck('draft.id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all();
            $hybridSummary['generation_job_dispatched_at'] = null;
            $summary['hybrid'] = $hybridSummary;
            $locked->comparison_summary_json = $summary;
            $locked->save();

            GenerateHybridDraftFromComparisonJob::dispatch((string) $locked->id)
                ->onQueue('generation')
                ->afterCommit();

            return $locked->fresh(['items.draft', 'winnerDraft', 'hybridDraft']);
        });
    }

    public function generateHybridDraftForComparison(string $comparisonId): ?Draft
    {
        $hybridDraftId = null;
        $shouldDispatch = false;

        DB::transaction(function () use ($comparisonId, &$hybridDraftId, &$shouldDispatch): void {
            $comparison = DraftComparison::query()
                ->whereKey($comparisonId)
                ->lockForUpdate()
                ->first();

            if (! $comparison) {
                return;
            }

            $comparison->loadMissing([
                'brief',
                'content',
                'items.draft',
                'variants.draft',
                'variants.scores',
            ]);

            $capabilities = $this->comparisonFeatureGate->capabilitiesForComparison($comparison);
            if (! $capabilities['enabled']) {
                $comparison->hybrid_status = 'failed';
                $comparison->hybrid_last_error = $this->comparisonFeatureGate->disabledMessage();
                $comparison->hybrid_completed_at = now();
                $comparison->save();

                return;
            }

            if (! $capabilities['hybrid_enabled']) {
                $comparison->hybrid_status = 'failed';
                $comparison->hybrid_last_error = 'Hybrid draft generation is not available on your current plan.';
                $comparison->hybrid_completed_at = now();
                $comparison->save();

                return;
            }

            if (! $comparison->isTerminal()) {
                $comparison->hybrid_status = 'failed';
                $comparison->hybrid_last_error = 'Wait for the comparison to complete before generating a hybrid draft.';
                $comparison->hybrid_completed_at = now();
                $comparison->save();

                return;
            }

            $candidates = $this->resolveHybridCandidates($comparison);
            if ($candidates->count() < 2) {
                $comparison->hybrid_status = 'failed';
                $comparison->hybrid_last_error = 'Generate at least two successful drafts before creating a hybrid version.';
                $comparison->hybrid_completed_at = now();
                $comparison->save();

                return;
            }

            $brief = $comparison->brief;
            $content = $comparison->content;
            if (! $brief || ! $content) {
                $comparison->hybrid_status = 'failed';
                $comparison->hybrid_last_error = 'Hybrid generation is missing brief or content context.';
                $comparison->hybrid_completed_at = now();
                $comparison->save();

                return;
            }

            $estimatedCredits = $this->estimateHybridCredits($comparison, $candidates);
            $synthesisModel = $this->resolveHybridSynthesisModel($comparison);

            $hybridDraft = null;
            if ($comparison->hybrid_draft_id) {
                $hybridDraft = Draft::query()->find($comparison->hybrid_draft_id);
            }

            if (! $hybridDraft) {
                $hybridDraft = $this->createHybridDraft(
                    brief: $brief,
                    content: $content,
                    comparison: $comparison,
                    candidates: $candidates->all(),
                    actorUserId: (int) ($comparison->created_by_user_id ?: 0),
                    creditCost: $estimatedCredits,
                    synthesisModel: $synthesisModel,
                );
            } else {
                $this->applyHybridDraftConfiguration(
                    draft: $hybridDraft,
                    brief: $brief,
                    comparison: $comparison,
                    candidates: $candidates->all(),
                    actorUserId: (int) ($comparison->created_by_user_id ?: 0),
                    creditCost: $estimatedCredits,
                    synthesisModel: $synthesisModel,
                );
            }

            $comparison->hybrid_draft_id = (string) $hybridDraft->id;
            $comparison->hybrid_status = (string) $hybridDraft->status === 'generated' ? 'generated' : 'queued';
            $comparison->hybrid_last_error = null;
            $comparison->hybrid_completed_at = (string) $hybridDraft->status === 'generated' ? now() : null;

            $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
            $hybridSummary = is_array($summary['hybrid'] ?? null) ? $summary['hybrid'] : [];
            $hybridSummary['prepared_at'] = now()->toIso8601String();
            $hybridSummary['estimated_credit_cost'] = $estimatedCredits;
            $hybridSummary['source_variant_count'] = $candidates->count();
            $hybridSummary['source_variant_ids'] = $candidates->pluck('variant_id')->filter()->values()->all();
            $hybridSummary['source_draft_ids'] = $candidates->pluck('draft.id')->map(fn ($id): string => (string) $id)->values()->all();
            $hybridSummary['synthesis_model'] = $synthesisModel;
            $summary['hybrid'] = $hybridSummary;
            $comparison->comparison_summary_json = $summary;
            $comparison->save();

            if (! in_array((string) $hybridDraft->status, ['generated', 'generating'], true)) {
                $hybridDraft->status = 'queued';
                $hybridDraft->save();

                if (trim((string) ($hybridSummary['generation_job_dispatched_at'] ?? '')) === '') {
                    $hybridSummary['generation_job_dispatched_at'] = now()->toIso8601String();
                    $summary['hybrid'] = $hybridSummary;
                    $comparison->comparison_summary_json = $summary;
                    $comparison->save();
                    $shouldDispatch = true;
                }
            }

            $hybridDraftId = (string) $hybridDraft->id;
        });

        if ($shouldDispatch && $hybridDraftId) {
            GenerateDraftJob::dispatch($hybridDraftId)
                ->onQueue('generation')
                ->afterCommit();
        }

        return $hybridDraftId ? Draft::query()->find($hybridDraftId) : null;
    }

    /**
     * @param array{key:string,provider:string,provider_label:string,model:string,label:string} $selection
     * @param array{generation_type:string,requested_max_output_tokens:int,per_draft_credits:int,total_credits:int} $estimate
     */
    private function createDraftForSelection(
        Brief $brief,
        Content $content,
        DraftComparison $comparison,
        DraftComparisonItem $comparisonItem,
        array $selection,
        array $estimate,
        int $variantCreditCost,
    ): Draft {
        $meta = $this->buildDraftMeta($brief);
        $meta['required_credits'] = $variantCreditCost;
        $meta['generation_type'] = (string) $estimate['generation_type'];
        $meta['generation_provider_override'] = (string) $selection['provider'];
        $meta['generation_model_override'] = (string) $selection['model'];
        $meta['draft_compare'] = [
            'comparison_id' => (string) $comparison->id,
            'item_id' => null,
            'legacy_item_id' => (string) $comparisonItem->id,
            'provider' => (string) $selection['provider'],
            'model' => (string) $selection['model'],
            'is_hybrid' => false,
            'comparison_credit_managed' => true,
        ];

        $draft = new Draft();
        $draft->id = (string) Str::uuid();
        $draft->brief_id = (string) $brief->id;
        $draft->content_id = (string) $content->id;
        $draft->draft_comparison_id = (string) $comparison->id;
        $draft->draft_comparison_variant_id = null;
        $draft->client_site_id = (string) $brief->client_site_id;
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
        $draft->content_html = null;
        $draft->meta = $meta;
        $draft->links = null;
        $draft->credit_cost = $variantCreditCost;
        $draft->save();

        return $draft;
    }

    /**
     * @param array<int, array{variant_id:?string,provider:string,model:string,draft:Draft,metrics:array<string,mixed>}> $candidates
     * @param array{provider:string,model:string,source:string} $synthesisModel
     */
    private function createHybridDraft(
        Brief $brief,
        Content $content,
        DraftComparison $comparison,
        array $candidates,
        int $actorUserId,
        int $creditCost,
        array $synthesisModel,
    ): Draft {
        $draft = new Draft();
        $draft->id = (string) Str::uuid();
        $draft->brief_id = (string) $brief->id;
        $draft->content_id = (string) $content->id;
        $draft->client_site_id = (string) $brief->client_site_id;
        $draft->status = 'queued';
        $draft->attempts = 0;
        $draft->title = (string) $brief->title . ' (Hybrid)';
        $draft->seo_title = $draft->title;
        $draft->seo_h1 = $draft->title;
        $draft->output_type = (string) ($brief->output_type ?? 'kb_article');
        $draft->content_html = null;
        $draft->links = null;
        $draft->credit_cost = $creditCost;

        $this->applyHybridDraftConfiguration(
            draft: $draft,
            brief: $brief,
            comparison: $comparison,
            candidates: $candidates,
            actorUserId: $actorUserId,
            creditCost: $creditCost,
            synthesisModel: $synthesisModel,
        );

        return $draft;
    }

    /**
     * @param array<int, array{variant_id:?string,provider:string,model:string,draft:Draft,metrics:array<string,mixed>}> $candidates
     * @param array{provider:string,model:string,source:string} $synthesisModel
     */
    private function applyHybridDraftConfiguration(
        Draft $draft,
        Brief $brief,
        DraftComparison $comparison,
        array $candidates,
        int $actorUserId,
        int $creditCost,
        array $synthesisModel,
    ): void {
        $meta = $this->buildDraftMeta($brief);
        $meta['requested_max_output_tokens'] = (int) ($comparison->requested_max_output_tokens ?: data_get($comparison->meta, 'requested_max_output_tokens', 8000));
        $meta['required_credits'] = $creditCost;
        $meta['generation_type'] = (string) data_get($comparison->meta, 'generation_type', $this->generationTypeForBrief($brief));
        $meta['generation_provider_override'] = (string) ($synthesisModel['provider'] ?? '');
        $meta['generation_model_override'] = (string) ($synthesisModel['model'] ?? '');
        $meta['generation_custom_system_prompt'] = $this->hybridSystemPrompt();
        $meta['generation_custom_user_prompt'] = $this->hybridUserPrompt($brief, $candidates, $synthesisModel);
        $meta['draft_compare'] = [
            'comparison_id' => (string) $comparison->id,
            'item_id' => null,
            'provider' => (string) ($synthesisModel['provider'] ?? ''),
            'model' => (string) ($synthesisModel['model'] ?? ''),
            'is_hybrid' => true,
            'generated_by_user_id' => $actorUserId,
            'source_variant_ids' => collect($candidates)->pluck('variant_id')->filter()->values()->all(),
            'source_draft_ids' => collect($candidates)->map(fn (array $candidate): string => (string) $candidate['draft']->id)->values()->all(),
            'synthesis' => $synthesisModel,
            // Hybrid runs are charged as a normal draft generation; variant runs stay comparison-managed.
            'comparison_credit_managed' => false,
        ];

        $draft->meta = $meta;
        $draft->draft_comparison_id = (string) $comparison->id;
        $draft->draft_comparison_variant_id = null;
        $draft->credit_cost = $creditCost;
        $draft->save();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{variant_id:?string,provider:string,model:string,draft:Draft,metrics:array<string,mixed>}>
     */
    private function resolveHybridCandidates(DraftComparison $comparison): \Illuminate\Support\Collection
    {
        $comparison->loadMissing([
            'variants.draft',
            'variants.scores',
            'items.draft',
        ]);

        $itemMetricsByDraftId = $comparison->items
            ->filter(fn (DraftComparisonItem $item): bool => $item->draft_id !== null)
            ->mapWithKeys(fn (DraftComparisonItem $item): array => [
                (string) $item->draft_id => (array) ($item->metrics ?? []),
            ]);

        $variantCandidates = $comparison->variants
            ->filter(function (DraftComparisonVariant $variant): bool {
                if ((string) $variant->status !== DraftComparisonVariant::STATUS_COMPLETED) {
                    return false;
                }

                $draft = $variant->draft;

                return $draft instanceof Draft
                    && (string) $draft->status === 'generated'
                    && trim((string) $draft->content_html) !== '';
            })
            ->map(function (DraftComparisonVariant $variant) use ($itemMetricsByDraftId): array {
                $metrics = $variant->scores
                    ->filter(fn ($score): bool => is_numeric($score->numeric_score))
                    ->mapWithKeys(fn ($score): array => [(string) $score->metric_key => (float) $score->numeric_score])
                    ->all();

                if ($metrics === [] && $variant->draft_id && $itemMetricsByDraftId->has((string) $variant->draft_id)) {
                    $metrics = (array) $itemMetricsByDraftId->get((string) $variant->draft_id, []);
                }

                return [
                    'variant_id' => (string) $variant->id,
                    'provider' => (string) $variant->provider_key,
                    'model' => (string) $variant->model_key,
                    'draft' => $variant->draft,
                    'metrics' => $metrics,
                ];
            })
            ->values();

        if ($variantCandidates->isNotEmpty()) {
            return $variantCandidates;
        }

        // Legacy fallback for compare runs created before explicit variant rows.
        return $comparison->items
            ->filter(function (DraftComparisonItem $item): bool {
                $draft = $item->draft;

                return (string) $item->status === 'generated'
                    && $draft instanceof Draft
                    && (string) $draft->status === 'generated'
                    && trim((string) $draft->content_html) !== '';
            })
            ->map(fn (DraftComparisonItem $item): array => [
                'variant_id' => null,
                'provider' => (string) $item->provider,
                'model' => (string) $item->model,
                'draft' => $item->draft,
                'metrics' => (array) ($item->metrics ?? []),
            ])
            ->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, array{variant_id:?string,provider:string,model:string,draft:Draft,metrics:array<string,mixed>}> $candidates
     */
    private function estimateHybridCredits(DraftComparison $comparison, \Illuminate\Support\Collection $candidates): int
    {
        $brief = $comparison->brief ?: $comparison->brief()->first();
        if (! $brief) {
            return max(1, (int) data_get($comparison->meta, 'per_draft_credits', 1));
        }

        $selections = $candidates
            ->map(fn (array $candidate): array => [
                'provider' => (string) ($candidate['provider'] ?? ''),
                'model' => (string) ($candidate['model'] ?? ''),
            ])
            ->unique(fn (array $selection): string => $selection['provider'] . ':' . $selection['model'])
            ->values()
            ->all();

        $estimate = $this->creditEstimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $comparison->requested_max_output_tokens,
            includeScoring: false,
            includeHybrid: true,
        );

        $hybridCredits = max(
            0,
            (int) ($estimate['hybrid_credit_cost'] ?? 0),
            (int) data_get($comparison->meta, 'per_draft_credits', 0)
        );

        return max(1, $hybridCredits);
    }

    /**
     * @return array{provider:string,model:string,source:string}
     */
    private function resolveHybridSynthesisModel(DraftComparison $comparison): array
    {
        $provider = trim((string) data_get($comparison->meta, 'hybrid_synthesis.provider', ''));
        $model = trim((string) data_get($comparison->meta, 'hybrid_synthesis.model', ''));
        $source = 'comparison_meta';

        if ($provider === '' || $model === '') {
            $recommendedProvider = trim((string) data_get($comparison->comparison_summary_json, 'recommendation.suggested_winner.provider', ''));
            $recommendedModel = trim((string) data_get($comparison->comparison_summary_json, 'recommendation.suggested_winner.model', ''));

            if ($recommendedProvider !== '' && $recommendedModel !== '') {
                $provider = $recommendedProvider;
                $model = $recommendedModel;
                $source = 'winner_recommendation';
            }
        }

        if ($provider === '') {
            $provider = (string) config('llm.default_provider', 'openai');
            $source = 'llm_default';
        }

        if ($model === '') {
            $model = (string) config('llm.providers.' . $provider . '.default_model', 'default');
            if ($source === 'comparison_meta') {
                $source = 'provider_default';
            }
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'source' => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDraftMeta(Brief $brief): array
    {
        $promptMeta = $this->promptBuilder->buildDraftMeta($brief);

        $meta = [
            'language' => $brief->language,
            'intent' => $brief->intent,
            'intent_keys' => (array) data_get($brief->client_refs, 'taxonomy.intent_keys', []),
            'primary_keyword' => $brief->primary_keyword,
            'audience' => $brief->audience,
            'audience_tags' => (array) data_get($brief->client_refs, 'taxonomy.audience_keys', []),
            'brand_voice_id' => data_get($brief->client_refs, 'brand_voice_id'),
            'team_member_id' => data_get($brief->client_refs, 'team_member_id'),
            'preferred_length' => data_get($brief->client_refs, 'preferred_length', 'medium'),
            'notes' => $brief->notes,
            'secondary_keywords' => $brief->secondary_keywords,
            'robots_index' => data_get($brief->client_refs, 'robots_index'),
            'robots_follow' => data_get($brief->client_refs, 'robots_follow'),
            'schema_type' => (string) data_get($brief->client_refs, 'schema_type', '') ?: null,
            'tone' => $brief->tone_of_voice,
            'funnel_stage' => $brief->funnel_stage,
            'search_intent' => $brief->search_intent,
            'unique_angle' => $brief->unique_angle,
            'key_points' => $brief->key_points,
            'call_to_action' => $brief->call_to_action,
            'client_refs' => $brief->client_refs ?? [],
            'source' => (string) ($brief->source ?: 'wp_plugin'),
            'brief_prompt' => $this->promptBuilder->buildPrompt($brief),
        ];

        return array_replace_recursive($meta, $promptMeta);
    }

    private function ensureContentForBrief(Brief $brief, int $userId): Content
    {
        if ($brief->content_id) {
            $content = Content::query()->find($brief->content_id);
            if ($content) {
                return $content;
            }
        }

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $brief->clientSite?->workspace_id,
            'client_site_id' => $brief->client_site_id,
            'title' => (string) $brief->title,
            'primary_keyword' => (string) ($brief->primary_keyword ?? ''),
            'type' => $this->mapBriefContentTypeToContentType((string) ($brief->content_type ?? '')),
            'status' => 'brief',
            'source' => 'manual',
            'external_key' => (string) Str::uuid(),
            'generation_mode' => 'balanced',
            'preferred_length' => $this->preferredLengthFromBounds(
                (int) ($brief->desired_length_min ?? 0),
                (int) ($brief->desired_length_max ?? 0)
            ),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $brief->content_id = (string) $content->id;
        $brief->save();

        return $content;
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            'compare_two', 'compare_multi' => $normalized,
            'compare-2', 'two', 'dual' => 'compare_two',
            'compare-multi', 'multi', 'multiple' => 'compare_multi',
            default => 'compare_two',
        };
    }

    private function normalizeCompareScope(?string $scope): string
    {
        $normalized = strtolower(trim((string) $scope));

        $normalized = match ($normalized) {
            'intro', 'intro_compare', 'introduction' => self::COMPARE_SCOPE_INTRO_ONLY,
            'headline', 'title', 'headline_compare' => self::COMPARE_SCOPE_HEADLINE_ONLY,
            'section', 'sections', 'by_section' => self::COMPARE_SCOPE_SECTION_COMPARE,
            default => $normalized,
        };

        return in_array($normalized, self::COMPARE_SCOPES, true)
            ? $normalized
            : self::COMPARE_SCOPE_FULL_DRAFT;
    }

    /**
     * @param array{enabled:bool,max_models:int,hybrid_enabled:bool,scoring_enabled:bool,premium_models_enabled:bool,allowed_modes:array<int,string>,compare_mode_enabled:bool,blocked_reason:?string} $capabilities
     */
    private function assertCompareCapabilityForMode(array $capabilities, string $mode): void
    {
        if (! $capabilities['enabled']) {
            throw new RuntimeException((string) ($capabilities['blocked_reason'] ?: $this->comparisonFeatureGate->disabledMessage()));
        }

        if (! in_array($mode, (array) ($capabilities['allowed_modes'] ?? []), true)) {
            throw new RuntimeException(sprintf(
                'Your current plan does not support %s mode. Maximum models allowed: %d.',
                str_replace('_', ' ', $mode),
                (int) ($capabilities['max_models'] ?? 1),
            ));
        }
    }

    private function guardSelectionCount(string $mode, int $count, int $maxModels = 6): void
    {
        if ($count < 2) {
            throw new RuntimeException('Select at least two models.');
        }

        if ($mode === 'compare_two' && $count !== 2) {
            throw new RuntimeException('Compare 2 models mode requires exactly two models.');
        }

        if ($mode === 'compare_multi' && $count < 2) {
            throw new RuntimeException('Compare multiple mode requires at least two models.');
        }

        $maxModels = max(1, $maxModels);
        if ($count > $maxModels) {
            throw new RuntimeException(sprintf('Select up to %d model%s per comparison run.', $maxModels, $maxModels === 1 ? '' : 's'));
        }
    }

    private function generationTypeForBrief(Brief $brief): string
    {
        return match ((string) ($brief->output_type ?? 'kb_article')) {
            'kb_article', 'article' => GenerationPricing::TYPE_ARTICLE,
            default => GenerationPricing::TYPE_ARTICLE,
        };
    }

    private function mapBriefContentTypeToContentType(string $contentType): string
    {
        return match (strtolower(trim($contentType))) {
            'landing' => 'seo_page',
            default => 'article',
        };
    }

    private function preferredLengthFromBounds(int $min, int $max): string
    {
        if ($min >= 2000 || $max >= 2200) {
            return 'pillar';
        }

        if ($min >= 1300 || $max >= 1400) {
            return 'long';
        }

        if ($max > 0 && $max <= 850) {
            return 'short';
        }

        return 'medium';
    }

    /**
     * @param array<int, array{key:string,provider:string,provider_label:string,model:string,label:string}> $selections
     */
    private function fingerprint(Brief $brief, string $mode, array $selections, int $tokens): string
    {
        $modelKeys = collect($selections)
            ->map(fn (array $selection): string => (string) $selection['key'])
            ->sort()
            ->values()
            ->implode('|');

        return hash('sha256', implode('|', [
            (string) $brief->id,
            $mode,
            (string) $tokens,
            $modelKeys,
        ]));
    }

    private function findActiveDuplicate(Brief $brief, string $fingerprint): ?DraftComparison
    {
        return DraftComparison::query()
            ->where('brief_id', $brief->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('created_at')
            ->get()
            ->first(function (DraftComparison $comparison) use ($fingerprint): bool {
                return hash_equals((string) data_get($comparison->meta, 'fingerprint', ''), $fingerprint);
            });
    }

    private function hybridSystemPrompt(): string
    {
        return implode("\n", [
            'You are a senior B2B editor combining multiple draft candidates into one best final draft.',
            'Keep factual accuracy, clear structure, and concise language.',
            'Do not copy low quality or repetitive sections.',
            'Return valid JSON only using the exact schema requested in the user message.',
            'Do not include markdown fences or commentary.',
        ]);
    }

    /**
     * @param array<int, array{variant_id:?string,provider:string,model:string,draft:Draft,metrics:array<string,mixed>}> $candidates
     * @param array{provider:string,model:string,source:string} $synthesisModel
     */
    private function hybridUserPrompt(Brief $brief, array $candidates, array $synthesisModel): string
    {
        $candidateLines = collect($candidates)
            ->map(function (array $candidate, int $index): string {
                /** @var Draft $draft */
                $draft = $candidate['draft'];
                $provider = (string) ($candidate['provider'] ?: data_get($draft->meta, 'generation.provider', 'default'));
                $model = (string) ($candidate['model'] ?: data_get($draft->meta, 'generation.model_used', data_get($draft->meta, 'generation.model', '')));
                $metrics = (array) ($candidate['metrics'] ?? []);
                $strengths = $this->topMetricLabels($metrics, descending: true, limit: 3);
                $weaknesses = $this->topMetricLabels($metrics, descending: false, limit: 2);

                $content = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $draft->content_html)));
                $content = mb_substr($content, 0, 5000);

                return implode("\n", [
                    'Candidate ' . ($index + 1) . ':',
                    'Variant ID: ' . (string) ($candidate['variant_id'] ?: 'legacy'),
                    'Draft ID: ' . (string) $draft->id,
                    'Provider: ' . ($provider !== '' ? $provider : 'default'),
                    'Model: ' . ($model !== '' ? $model : 'default'),
                    'Title: ' . (string) ($draft->title ?? 'Untitled'),
                    'Strengths: ' . ($strengths !== [] ? implode(', ', $strengths) : 'n/a'),
                    'Weaknesses: ' . ($weaknesses !== [] ? implode(', ', $weaknesses) : 'n/a'),
                    'Excerpt: ' . $content,
                ]);
            })
            ->implode("\n\n");

        return implode("\n", [
            'Task: Create one best hybrid draft based on all candidate drafts below.',
            'Brief title: ' . (string) ($brief->title ?? 'Untitled'),
            'Primary keyword: ' . (string) ($brief->primary_keyword ?? ''),
            'Synthesis model (selected): ' . (string) ($synthesisModel['provider'] ?? 'default') . ' / ' . (string) ($synthesisModel['model'] ?? 'default'),
            'Synthesis model source: ' . (string) ($synthesisModel['source'] ?? 'unknown'),
            'Keep strong insights, remove repetition, and improve clarity and CTA quality.',
            'Use candidate strengths as building blocks and explicitly correct candidate weaknesses.',
            'Return JSON exactly in this schema:',
            '{',
            '  "title": "string",',
            '  "meta": {',
            '    "description": "string (max 155 chars)",',
            '    "keywords": ["string", "..."]',
            '  },',
            '  "sections": [',
            '    { "heading": "string", "html": "string (valid HTML fragment)" }',
            '  ],',
            '  "links": [',
            '    { "href": "string", "anchor": "string", "rel": "string|null" }',
            '  ]',
            '}',
            '',
            'Candidate drafts:',
            $candidateLines,
        ]);
    }

    /**
     * @param array<string,mixed> $metrics
     * @return array<int,string>
     */
    private function topMetricLabels(array $metrics, bool $descending, int $limit): array
    {
        return collect($metrics)
            ->filter(fn ($value): bool => is_numeric($value))
            ->sortBy(fn ($value): float => (float) $value, options: SORT_REGULAR, descending: $descending)
            ->take(max(1, $limit))
            ->map(fn ($value, $key): string => sprintf(
                '%s %.1f',
                $this->metricLabel((string) $key),
                (float) $value
            ))
            ->values()
            ->all();
    }

    private function metricLabel(string $metricKey): string
    {
        return match ($metricKey) {
            'seo_score' => 'SEO',
            'ai_seo_score' => 'AI SEO',
            'readability_score' => 'Readability',
            'brand_voice_match' => 'Brand voice',
            'cta_strength' => 'CTA',
            'structure_quality' => 'Structure',
            'topical_coverage' => 'Topical coverage',
            'entity_coverage' => 'Entity coverage',
            'factual_confidence' => 'Factual confidence',
            'conversion_focus' => 'Conversion focus',
            'word_count' => 'Word count',
            default => Str::headline($metricKey),
        };
    }
}
