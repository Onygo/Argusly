<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Services\Brief\NormalizeContentBrief;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonProgressService;
use App\Services\DraftGenerationService;
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
        ?DraftComparisonProgressService $draftComparisonProgressService = null
    ): void {
        $operationService ??= app(AsyncOperationService::class);
        $webhookPublisher ??= app(ApiWebhookPublisher::class);

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
