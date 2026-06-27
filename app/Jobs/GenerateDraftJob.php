<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Services\Brief\NormalizeContentBrief;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonProgressService;
use App\Services\DraftGenerationService;
use App\Services\Editorial\EditorialPlanningService;
use App\Services\HumanContent\HumanContentGate;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;
use App\Services\PlanQuotaService;
use App\Support\SeoMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const STAGE_STARTED = 'draft_job_started';
    private const STAGE_DRAFT_LOADED = 'draft_loaded';
    private const STAGE_NORMALIZED = 'draft_normalized';
    private const STAGE_VALIDATED = 'draft_validated';
    private const STAGE_CREDITS_RESERVED = 'credits_reserved';
    private const STAGE_GENERATION_STARTED = 'generation_started';
    private const STAGE_GENERATION_COMPLETED = 'generation_completed';
    private const STAGE_DRAFT_PERSISTED = 'draft_persisted';
    private const STAGE_JOB_COMPLETED = 'draft_job_completed';
    private const STAGE_JOB_FAILED = 'draft_job_failed';

    public function backoff(): array
    {
        // 1m, 5m, 15m, 1h, 3h
        return [60, 300, 900, 3600, 10800];
    }

    public function __construct(
        public string $draftId
    ) {}

    public function handle(
        DraftGenerationService $service,
        ContentLifecycleService $contentLifecycleService,
        CreditWalletService $creditWalletService,
        PlanQuotaService $planQuotaService,
        NormalizeContentBrief $normalizer,
        ?AsyncOperationService $operationService = null,
        ?ApiWebhookPublisher $webhookPublisher = null,
        ?DraftComparisonProgressService $draftComparisonProgressService = null,
        ?HumanContentScoreService $humanContentScoreService = null,
        ?HumanizationService $humanizationService = null,
        ?EditorialPlanningService $editorialPlanningService = null,
        ?HumanContentGate $humanContentGate = null
    ): void {
        $operationService ??= app(AsyncOperationService::class);
        $webhookPublisher ??= app(ApiWebhookPublisher::class);
        $humanContentScoreService ??= app(HumanContentScoreService::class);
        $humanizationService ??= app(HumanizationService::class);
        $editorialPlanningService ??= app(EditorialPlanningService::class);
        $humanContentGate ??= app(HumanContentGate::class);

        $currentStage = self::STAGE_STARTED;

        try {
            // Stage: Load draft
            $this->logStage(self::STAGE_STARTED, ['draft_id' => $this->draftId]);

            $draft = Draft::query()->find($this->draftId);
            if (! $draft) {
                throw new RuntimeException("Draft not found: {$this->draftId}");
            }

            $draft->loadMissing('clientSite.workspace.organization', 'content', 'brief');
            $operationId = trim((string) data_get($draft->meta, 'async_operation_id', ''));
            $currentStage = self::STAGE_DRAFT_LOADED;

            // Log diagnostic context
            $diagnostics = $normalizer->getDiagnosticContext($draft);
            $this->logStage(self::STAGE_DRAFT_LOADED, $diagnostics);

            // Stage: Normalize draft meta
            $normalizationResult = $this->normalizeDraft($draft, $normalizer);
            $currentStage = self::STAGE_NORMALIZED;
            $this->logStage(self::STAGE_NORMALIZED, [
                'draft_id' => $this->draftId,
                'normalized' => $normalizationResult['normalized'],
                'fields_added' => $normalizationResult['fields_added'],
            ]);

            // Stage: Validate draft
            $validation = $normalizer->validateDraftForGeneration($draft);
            $currentStage = self::STAGE_VALIDATED;

            if (! $validation['valid']) {
                $errorMessage = 'Draft validation failed: ' . implode('; ', $validation['errors']);
                $this->logStage(self::STAGE_JOB_FAILED, [
                    'draft_id' => $this->draftId,
                    'stage' => $currentStage,
                    'validation_errors' => $validation['errors'],
                    'missing_fields' => $validation['missing'],
                ]);

                $draft->update([
                    'status' => 'failed',
                    'last_error' => $errorMessage,
                ]);

                throw new RuntimeException($errorMessage);
            }

            $this->logStage(self::STAGE_VALIDATED, [
                'draft_id' => $this->draftId,
                'credit_cost' => $draft->credit_cost,
            ]);

            // Check if already generated
            if ($draft->status === 'generated') {
                if ($operationId !== '') {
                    $operationService->markCompleted($operationId, [
                        'draft_id' => (string) $draft->id,
                        'status' => (string) $draft->status,
                    ]);
                }
                $draftComparisonProgressService?->markDraftGenerated($draft);
                $this->logStage(self::STAGE_JOB_COMPLETED, [
                    'draft_id' => $this->draftId,
                    'reason' => 'already_generated',
                ]);

                return;
            }

            $draft->update(['status' => 'generating']);
            if ($operationId !== '') {
                $operationService->markProcessing($operationId);
            }
            $draftComparisonProgressService?->markDraftGenerating($draft);
            $comparisonManagedCredits = (bool) data_get($draft->meta, 'draft_compare.comparison_credit_managed', false);

            // Stage: Prepare draft context and ensure the Editorial Plan exists before generation.
            $this->prepareDraftContext($draft, $editorialPlanningService);

            // Stage: Reserve credits
            if (! $comparisonManagedCredits) {
                $creditWalletService->reserveForDraft($draft, null);
            }
            $currentStage = self::STAGE_CREDITS_RESERVED;
            $this->logStage(self::STAGE_CREDITS_RESERVED, [
                'draft_id' => $this->draftId,
                'credit_cost' => $draft->credit_cost,
                'comparison_managed' => $comparisonManagedCredits,
            ]);

            // Stage: Generate content
            $currentStage = self::STAGE_GENERATION_STARTED;
            $this->logStage(self::STAGE_GENERATION_STARTED, ['draft_id' => $this->draftId]);

            $result = $service->generateWithRepair($draft, 2);

            $currentStage = self::STAGE_GENERATION_COMPLETED;
            $this->logStage(self::STAGE_GENERATION_COMPLETED, [
                'draft_id' => $this->draftId,
                'provider' => data_get($result, 'provider'),
                'model' => data_get($result, 'model_used'),
            ]);

            // Commit credits
            $result = $this->humanizeGeneratedResult(
                draft: $draft,
                result: $result,
                humanContentScoreService: $humanContentScoreService,
                humanizationService: $humanizationService,
                humanContentGate: $humanContentGate,
            );

            if (! $comparisonManagedCredits) {
                $creditWalletService->commitUsageForDraft($draft, null);
            }

            // Stage: Persist result
            $currentStage = self::STAGE_DRAFT_PERSISTED;
            $this->persistGenerationResult($draft, $result);
            $draftComparisonProgressService?->markDraftGenerated($draft->fresh());

            if ($operationId !== '') {
                $operationService->markCompleted($operationId, [
                    'draft_id' => (string) $draft->id,
                    'brief_id' => (string) $draft->brief_id,
                    'status' => (string) $draft->status,
                ]);
            }

            if ($draft->clientSite?->workspace) {
                $webhookPublisher->publish(
                    workspace: $draft->clientSite->workspace,
                    eventType: 'draft.generation.completed',
                    payload: [
                        'draft_id' => (string) $draft->id,
                        'brief_id' => (string) $draft->brief_id,
                        'operation_id' => $operationId !== '' ? $operationId : null,
                    ],
                    contentDestinationId: $draft->content_destination_id,
                    eventId: $operationId !== '' ? $operationId : (string) $draft->id,
                );
            }

            // Post-processing
            try {
                if ($draft->clientSite?->workspace) {
                    $planQuotaService->incrementUsage(
                        workspace: $draft->clientSite->workspace,
                        site: $draft->clientSite,
                        metric: PlanQuotaService::METRIC_ARTICLES_GENERATED,
                        amount: 1,
                    );
                }

                if ($draft->content_id) {
                    $contentLifecycleService->ensureRevisionFromDraft($draft);
                    GenerateInternalLinksJob::dispatch((string) $draft->content_id)
                        ->onQueue('generation')
                        ->afterCommit();
                }

                AnalyzeDraftJob::dispatch((string) $draft->id, false, null, (string) Str::uuid())
                    ->afterCommit();
            } catch (Throwable $postProcessException) {
                Log::warning('GenerateDraftJob post-process failed after successful generation.', [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'error' => $postProcessException->getMessage(),
                ]);

                $draft->update([
                    'last_error' => 'Post-generation warning: ' . mb_substr($postProcessException->getMessage(), 0, 4500),
                ]);
            }

            $this->logStage(self::STAGE_JOB_COMPLETED, ['draft_id' => $this->draftId]);

        } catch (Throwable $e) {
            $this->handleFailure($e, $currentStage, $creditWalletService, $operationService, $webhookPublisher, $draftComparisonProgressService);
        }
    }

    private function prepareDraftContext(Draft $draft, EditorialPlanningService $editorialPlanningService): void
    {
        $draft->loadMissing([
            'brief.clientSite.workspace',
            'brief.researchProjects.findings',
            'content.brandVoice',
            'content.writerProfile',
            'clientSite.workspace',
        ]);

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $plan = data_get($meta, 'editorial_plan');

        if (! is_array($plan) || trim((string) data_get($plan, 'central_thesis', '')) === '') {
            $meta['editorial_plan'] = $editorialPlanningService->createForDraft($draft);
            unset($meta['structure']);
            $draft->forceFill(['meta' => $meta])->save();
        }
    }

    private function normalizeDraft(Draft $draft, NormalizeContentBrief $normalizer): array
    {
        // Apply normalization
        $result = $normalizer->normalizeDraftMeta($draft);

        if ($result['normalized']) {
            $draft->meta = $result['meta'];
            $draft->save();
        }

        // Ensure credit_cost is set (fix race condition)
        if ((int) ($draft->credit_cost ?? 0) <= 0) {
            $requiredCredits = (int) data_get($draft->meta, 'required_credits', 0);
            if ($requiredCredits <= 0) {
                $requiredCredits = max(1, (int) config('argusly.ai.drafts.credit_cost', 4));
            }
            $draft->credit_cost = $requiredCredits;
            $draft->save();
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function humanizeGeneratedResult(
        Draft $draft,
        array $result,
        HumanContentScoreService $humanContentScoreService,
        HumanizationService $humanizationService,
        HumanContentGate $humanContentGate,
    ): array {
        $html = (string) data_get($result, 'content_html', '');
        if (trim(strip_tags($html)) === '') {
            return $result;
        }

        $draft->loadMissing('brief', 'content.brandVoice', 'content.writerProfile');

        $title = (string) data_get($result, 'title', $draft->title);
        $beforeScore = $humanContentScoreService->scoreForDraftHtml($draft, $html, $title);
        $meta = array_replace_recursive(
            is_array($draft->meta) ? $draft->meta : [],
            (array) data_get($result, 'meta', [])
        );
        $this->storeGateScoreMetadata($meta, 'before', $beforeScore);

        if (! $humanizationService->shouldHumanize($beforeScore)) {
            $meta = $this->finalizeGateMetadata($meta, $beforeScore, $beforeScore, [
                'version' => HumanizationService::VERSION,
                'status' => 'skipped',
                'change_summary' => 'Humanization skipped because the generated draft passed the human content threshold.',
                'before_after_notes' => [],
                'preserved_validation' => ['passed' => true],
            ], $humanContentGate);
            $result['meta'] = $meta;

            return $result;
        }

        try {
            $humanized = $humanizationService->humanize(
                html: $html,
                humanFindings: (array) data_get($beforeScore, 'findings', []),
                aiFingerprintFindings: (array) data_get($beforeScore, 'ai_fingerprint.findings', []),
                editorialPlan: (array) data_get($draft->meta, 'editorial_plan', []),
                brief: $draft->brief,
                brandVoice: $this->brandVoicePayload($draft),
                writerProfile: $this->writerProfilePayload($draft),
                corpusDiversityFindings: (array) data_get($beforeScore, 'corpus_diversity.findings', []),
            );
        } catch (Throwable $exception) {
            Log::warning('GenerateDraftJob humanization failed; preserving original generated draft.', [
                'draft_id' => (string) $draft->id,
                'error' => $exception->getMessage(),
            ]);

            $humanized = [
                'version' => HumanizationService::VERSION,
                'changed' => false,
                'improved_html' => $html,
                'change_summary' => 'Humanization failed; original generated draft was preserved.',
                'before_after_notes' => [$exception->getMessage()],
                'preserved_validation' => ['passed' => true, 'original_preserved' => true],
                'status' => 'failed',
            ];
        }

        if ((bool) data_get($humanized, 'changed', false)) {
            $result['content_html'] = (string) data_get($humanized, 'improved_html', $html);
        }

        $afterScore = $humanContentScoreService->scoreForDraftHtml($draft, (string) data_get($result, 'content_html', $html), $title);
        $meta = $this->finalizeGateMetadata($meta, $beforeScore, $afterScore, $humanized, $humanContentGate);
        $result['meta'] = $meta;

        return $result;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $beforeScore
     * @param array<string,mixed> $afterScore
     * @param array<string,mixed> $humanized
     * @return array<string,mixed>
     */
    private function finalizeGateMetadata(
        array $meta,
        array $beforeScore,
        array $afterScore,
        array $humanized,
        HumanContentGate $humanContentGate,
    ): array
    {
        $this->storeGateScoreMetadata($meta, 'after', $afterScore);
        $humanizationStatus = (string) data_get($humanized, 'status', '');
        if ($humanizationStatus === '') {
            $humanizationStatus = (bool) data_get($humanized, 'changed', false) ? 'applied' : 'not_changed';
        }

        $meta['fingerprint_findings'] = (array) data_get($afterScore, 'ai_fingerprint.findings', data_get($beforeScore, 'ai_fingerprint.findings', []));
        $meta['corpus_diversity_findings'] = (array) data_get($afterScore, 'corpus_diversity.findings', data_get($beforeScore, 'corpus_diversity.findings', []));
        $meta['humanization_changes'] = [
            'version' => HumanizationService::VERSION,
            'change_summary' => (string) data_get($humanized, 'change_summary', ''),
            'before_after_notes' => (array) data_get($humanized, 'before_after_notes', []),
            'preserved_validation' => (array) data_get($humanized, 'preserved_validation', []),
            'score_delta' => [
                'human_content_score' => (int) data_get($afterScore, 'human_content_score', 0) - (int) data_get($beforeScore, 'human_content_score', 0),
                'ai_fingerprint_score' => (int) data_get($afterScore, 'ai_fingerprint_score', 0) - (int) data_get($beforeScore, 'ai_fingerprint_score', 0),
            ],
        ];
        $meta['humanization_status'] = $humanizationStatus;

        data_set($meta, 'human_content.before', $this->scoreSummary($beforeScore));
        data_set($meta, 'human_content.after', $this->scoreSummary($afterScore));
        $gate = $humanContentGate->evaluateMetadata($meta);
        if ((string) data_get($humanized, 'status', '') === 'failed') {
            $gate['passed'] = false;
            $gate['status'] = HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW;
            $gate['reasons'] = array_values(array_unique(array_merge(
                (array) data_get($gate, 'reasons', []),
                ['Humanization failed; editorial review is required before auto-publication.']
            )));
        }

        $meta['publish_gate_status'] = (string) data_get($gate, 'status', HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW);
        $meta['human_content_gate'] = $gate;
        data_set($meta, 'humanization', array_merge($meta['humanization_changes'], [
            'status' => $humanizationStatus,
            'publish_gate_status' => $meta['publish_gate_status'],
        ]));

        return $meta;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $score
     */
    private function storeGateScoreMetadata(array &$meta, string $phase, array $score): void
    {
        $meta["human_content_score_{$phase}"] = (int) data_get($score, 'human_content_score', 0);
        $meta["ai_fingerprint_score_{$phase}"] = (int) data_get($score, 'ai_fingerprint_score', 0);
    }

    /**
     * @param array<string,mixed> $score
     * @return array<string,mixed>
     */
    private function scoreSummary(array $score): array
    {
        return [
            'status' => (string) data_get($score, 'status', ''),
            'passed' => (bool) data_get($score, 'passed', false),
            'human_content_score' => (int) data_get($score, 'human_content_score', 0),
            'editorial_quality_score' => (int) data_get($score, 'editorial_quality_score', 0),
            'originality_score' => (int) data_get($score, 'originality_score', 0),
            'uniqueness_score' => (int) data_get($score, 'uniqueness_score', 0),
            'ai_fingerprint_score' => (int) data_get($score, 'ai_fingerprint_score', 0),
            'ai_fingerprint_severity' => (string) data_get($score, 'ai_fingerprint.severity', ''),
            'corpus_diversity_score' => (int) data_get($score, 'corpus_diversity.score', 100),
            'corpus_diversity_risk_score' => (int) data_get($score, 'corpus_diversity.risk_score', 0),
            'corpus_diversity_status' => (string) data_get($score, 'corpus_diversity.status', ''),
            'corpus_diversity_findings' => (array) data_get($score, 'corpus_diversity.findings', []),
            'finding_count' => count((array) data_get($score, 'findings', [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function brandVoicePayload(Draft $draft): array
    {
        $brandVoice = $draft->content?->brandVoice;

        return [
            'tone' => (string) ($brandVoice?->tone_of_voice ?? $brandVoice?->default_tone ?? ''),
            'style' => (string) ($brandVoice?->writing_style ?? $brandVoice?->style_guide ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writerProfilePayload(Draft $draft): array
    {
        $writerProfile = $draft->content?->writerProfile;

        return [
            'summary' => (string) ($writerProfile?->tone_summary ?? $writerProfile?->writing_style_summary ?? ''),
            'structure' => (string) ($writerProfile?->structure_summary ?? ''),
        ];
    }

    private function persistGenerationResult(Draft $draft, array $result): void
    {
        $existingMeta = is_array($draft->meta) ? $draft->meta : [];
        $resultMeta = (array) ($result['meta'] ?? []);
        $mergedMeta = array_replace_recursive($existingMeta, $resultMeta);

        $seoFields = SeoMetadata::merge(
            [
                'seo_title' => $result['title'] ?? $draft->title,
                'seo_meta_description' => data_get($result, 'meta.description'),
                'robots_index' => data_get($result, 'meta.robots_index'),
                'robots_follow' => data_get($result, 'meta.robots_follow'),
                'schema_type' => data_get($result, 'meta.schema_type'),
            ],
            $mergedMeta,
            [
                'seo_title' => $draft->seo_title,
                'seo_meta_description' => $draft->seo_meta_description,
                'seo_h1' => $draft->seo_h1,
                'seo_canonical' => $draft->seo_canonical,
                'seo_og_title' => $draft->seo_og_title,
                'seo_og_description' => $draft->seo_og_description,
                'seo_og_image' => $draft->seo_og_image,
                'seo_twitter_title' => $draft->seo_twitter_title,
                'seo_twitter_description' => $draft->seo_twitter_description,
                'robots_index' => $draft->robots_index,
                'robots_follow' => $draft->robots_follow,
                'schema_type' => $draft->schema_type,
            ],
        );

        if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
            $seoFields['seo_h1'] = $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title);
        }

        $mergedMeta = array_replace_recursive($mergedMeta, array_filter([
            'meta_description' => $seoFields['seo_meta_description'],
            'canonical_url' => $seoFields['seo_canonical'],
            'og_title' => $seoFields['seo_og_title'],
            'og_description' => $seoFields['seo_og_description'],
            'og_image' => $seoFields['seo_og_image'],
            'twitter_title' => $seoFields['seo_twitter_title'],
            'twitter_description' => $seoFields['seo_twitter_description'],
            'robots_index' => $seoFields['robots_index'],
            'robots_follow' => $seoFields['robots_follow'],
            'schema_type' => $seoFields['schema_type'],
        ], static fn ($value) => is_bool($value) || trim((string) $value) !== ''));

        $mergedMeta['generation'] = array_filter([
            'provider' => (string) data_get($result, 'provider', config('llm.default_provider', 'openai')),
            'model' => (string) data_get($result, 'model', ''),
            'model_used' => (string) data_get($result, 'model_used', (string) data_get($result, 'model', '')),
            'tokens' => (int) data_get($result, 'usage.total_tokens', 0),
            'input_tokens' => (int) data_get($result, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($result, 'usage.output_tokens', 0),
            'request_id' => (string) data_get($result, 'request_id', ''),
            'requested_max_output_tokens' => (int) data_get($result, 'requested_max_output_tokens', (int) data_get($existingMeta, 'requested_max_output_tokens', 0)),
            'required_credits' => (int) data_get($result, 'required_credits', (int) data_get($existingMeta, 'required_credits', (int) ($draft->credit_cost ?? 0))),
            'charged_credits' => (int) data_get($result, 'charged_credits', (int) ($draft->credit_cost ?? 0)),
            'credits' => $draft->credit_cost,
            'generated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        $draft->update([
            'status' => 'generated',
            'title' => $result['title'] ?? $draft->title,
            'seo_title' => $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title),
            'seo_meta_description' => $seoFields['seo_meta_description'] ?: $draft->seo_meta_description,
            'seo_h1' => $seoFields['seo_h1'] ?: $draft->seo_h1,
            'seo_canonical' => $seoFields['seo_canonical'] ?: $draft->seo_canonical,
            'seo_og_title' => $seoFields['seo_og_title'] ?: $draft->seo_og_title,
            'seo_og_description' => $seoFields['seo_og_description'] ?: $draft->seo_og_description,
            'seo_og_image' => $seoFields['seo_og_image'] ?: $draft->seo_og_image,
            'seo_twitter_title' => $seoFields['seo_twitter_title'] ?: $draft->seo_twitter_title,
            'seo_twitter_description' => $seoFields['seo_twitter_description'] ?: $draft->seo_twitter_description,
            'robots_index' => $seoFields['robots_index'] ?? $draft->robots_index,
            'robots_follow' => $seoFields['robots_follow'] ?? $draft->robots_follow,
            'schema_type' => $seoFields['schema_type'] ?: $draft->schema_type,
            'content_html' => $result['content_html'] ?? null,
            'meta' => $mergedMeta,
            'links' => $result['links'] ?? $draft->links,
            'last_error' => null,
            'delivered_at' => now(),
        ]);
    }

    private function handleFailure(
        Throwable $e,
        string $stage,
        CreditWalletService $creditWalletService,
        AsyncOperationService $operationService,
        ApiWebhookPublisher $webhookPublisher,
        ?DraftComparisonProgressService $draftComparisonProgressService
    ): void {
        $draft = Draft::query()->find($this->draftId);
        if (! $draft) {
            Log::error('GenerateDraftJob failed: draft not found for error handling', [
                'draft_id' => $this->draftId,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $draft->loadMissing('clientSite.workspace');
        $operationId = trim((string) data_get($draft->meta, 'async_operation_id', ''));
        $comparisonManagedCredits = (bool) data_get($draft->meta, 'draft_compare.comparison_credit_managed', false);

        // Release credits if reserved
        if (! $comparisonManagedCredits && $draft->credit_status === 'reserved') {
            try {
                $creditWalletService->releaseReservationForDraft($draft, null);
            } catch (Throwable) {
                // Best-effort release
            }
        }

        $draft->increment('attempts');

        $retryable = $this->isRetryable($e);
        $errorMessage = $this->buildErrorMessage($e, $stage);

        $this->logStage(self::STAGE_JOB_FAILED, [
            'draft_id' => $this->draftId,
            'stage' => $stage,
            'error_class' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'retryable' => $retryable,
            'attempts' => $draft->attempts,
        ]);

        $draft->update([
            'last_error' => $errorMessage,
            'status' => $retryable ? 'ready' : 'failed',
        ]);

        if ($operationId !== '') {
            $errorCode = $e instanceof \App\Exceptions\InsufficientCreditsException
                ? 'INSUFFICIENT_CREDITS'
                : ($retryable ? 'RETRYABLE_GENERATION_ERROR' : 'GENERATION_FAILED');

            $operationService->markFailed(
                operationId: $operationId,
                errorMessage: $e->getMessage(),
                errorCode: $errorCode,
            );
        }

        if ($draft->clientSite?->workspace) {
            $webhookPublisher->publish(
                workspace: $draft->clientSite->workspace,
                eventType: 'draft.generation.failed',
                payload: [
                    'draft_id' => (string) $draft->id,
                    'brief_id' => (string) $draft->brief_id,
                    'operation_id' => $operationId !== '' ? $operationId : null,
                    'error' => $e->getMessage(),
                    'stage' => $stage,
                ],
                contentDestinationId: $draft->content_destination_id,
                eventId: $operationId !== '' ? $operationId : (string) $draft->id,
            );
        }

        $draftComparisonProgressService?->markDraftFailed($draft->fresh(), $e->getMessage(), $retryable);

        if (! $retryable) {
            $this->fail($e);

            return;
        }

        throw $e;
    }

    private function buildErrorMessage(Throwable $e, string $stage): string
    {
        $parts = [
            'Stage: ' . $stage,
            'Error: ' . mb_substr($e->getMessage(), 0, 4000),
        ];

        if ($stage === self::STAGE_VALIDATED) {
            $parts[] = 'Hint: Check that the content has a valid site, title, and credit configuration.';
        } elseif ($stage === self::STAGE_CREDITS_RESERVED) {
            $parts[] = 'Hint: Check subscription status and credit availability.';
        } elseif ($stage === self::STAGE_GENERATION_STARTED) {
            $parts[] = 'Hint: Check LLM provider configuration and API credentials.';
        }

        return implode("\n", $parts);
    }

    private function logStage(string $stage, array $context = []): void
    {
        $level = $stage === self::STAGE_JOB_FAILED ? 'error' : 'info';

        Log::log($level, "GenerateDraftJob: {$stage}", array_merge([
            'job_stage' => $stage,
        ], $context));
    }

    protected function isRetryable(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        if ($e instanceof \App\Exceptions\InsufficientCreditsException) {
            return false;
        }

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
            'draft has no client_site_id',
            'draft has no credit_cost',
            'draft validation failed',
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

        // Default: do not retry unknown errors
        return false;
    }

    public function failed(Throwable $exception): void
    {
        $draft = Draft::query()->find($this->draftId);
        if (! $draft || (string) $draft->status === 'generated') {
            return;
        }

        $draft->loadMissing('clientSite.workspace');
        $operationId = trim((string) data_get($draft->meta, 'async_operation_id', ''));
        $comparisonManagedCredits = (bool) data_get($draft->meta, 'draft_compare.comparison_credit_managed', false);

        if (! $comparisonManagedCredits && (string) $draft->credit_status === 'reserved') {
            try {
                app(CreditWalletService::class)->releaseReservationForDraft($draft, null, 'job_failed');
            } catch (Throwable) {
                // Best-effort release
            }
        }

        $draft->status = 'failed';
        $draft->last_error = mb_substr($exception->getMessage(), 0, 5000);
        $draft->save();

        Log::error('GenerateDraftJob permanently failed', [
            'draft_id' => $this->draftId,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        if ($operationId !== '') {
            app(AsyncOperationService::class)->markFailed(
                operationId: $operationId,
                errorMessage: $exception->getMessage(),
                errorCode: 'GENERATION_FAILED',
            );
        }

        if ($draft->clientSite?->workspace) {
            app(ApiWebhookPublisher::class)->publish(
                workspace: $draft->clientSite->workspace,
                eventType: 'draft.generation.failed',
                payload: [
                    'draft_id' => (string) $draft->id,
                    'brief_id' => (string) $draft->brief_id,
                    'operation_id' => $operationId !== '' ? $operationId : null,
                    'error' => $exception->getMessage(),
                ],
                contentDestinationId: $draft->content_destination_id,
                eventId: $operationId !== '' ? $operationId : (string) $draft->id,
            );
        }

        try {
            app(DraftComparisonProgressService::class)->markDraftFailed(
                $draft->fresh(),
                $exception->getMessage(),
                false,
            );
        } catch (Throwable) {
            // Best-effort sync
        }
    }
}
