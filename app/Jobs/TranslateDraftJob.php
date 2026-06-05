<?php

namespace App\Jobs;

use App\Enums\SupportedLanguage;
use App\Events\Agents\TranslationCompleted;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Content;
use App\Models\ContentCreditLog;
use App\Models\ContentTranslation;
use App\Models\CreditAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Services\Content\ContentLifecycleService;
use App\Services\Content\TranslationDebugService;
use App\Services\Content\TranslationLockService;
use App\Services\ContentAutomation\AutomationRunItemStateService;
use App\Services\CreditWalletService;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;
use App\Services\Translation\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TranslateDraftJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    private ?int $startedAtNs = null;

    private string $lastLifecycleEvent = 'NOT_STARTED';

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public string $sourceDraftId,
        public string $targetLanguage,
        public ?string $userId = null,
        public ?string $modelOverride = null,
        public ?string $operationId = null,
        public ?string $targetContentId = null,
        public ?string $translationRequestId = null,
        public ?string $dispatchJobUuid = null,
        public ?string $sourceContentId = null,
        public ?string $traceId = null,
    ) {
        $this->queue = config('translation.queue.name', 'default');
        $this->connection = config('translation.queue.connection');
        $this->dispatchJobUuid ??= (string) Str::uuid();
        $this->traceId ??= (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return sprintf(
            'translate-draft:%s:%s',
            $this->sourceContentId ?: ($this->translationRequestId ?: $this->sourceDraftId),
            $this->targetLanguage
        );
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        TranslationService $translationService,
        CreditWalletService $creditWalletService,
        AsyncOperationService $operationService,
        ApiWebhookPublisher $webhookPublisher,
        ContentLifecycleService $contentLifecycleService,
        AutomationRunItemStateService $automationItemState,
        TranslationLockService $translationLocks,
        ?TranslationDebugService $translationDebug = null,
    ): void {
        $translationDebug ??= app(TranslationDebugService::class);
        $sourceDraft = null;
        $translationRequest = $this->translationRequestId
            ? ContentTranslation::query()->find($this->translationRequestId)
            : null;
        $targetLanguage = null;
        $reservationEntry = null;
        $translatedDraft = null;
        $caughtException = null;
        $skipFailureFinalizer = false;

        try {
            $this->startedAtNs = hrtime(true);
            $this->lastLifecycleEvent = 'JOB_STARTED';
            $targetLanguage = SupportedLanguage::tryFrom($this->targetLanguage);

            if (! $targetLanguage) {
                throw new RuntimeException("Invalid target language: {$this->targetLanguage}");
            }

            $sourceDraft = Draft::query()->findOrFail($this->sourceDraftId);
            $sourceDraft->loadMissing('clientSite.workspace.organization', 'brief', 'content');
            $this->lastLifecycleEvent = 'SOURCE_LOADED';

            if ($translationRequest instanceof ContentTranslation && ! $this->traceId) {
                $this->traceId = (string) ($translationRequest->translation_trace_id ?: $this->traceId);
            }

            Log::info('TranslateDraftJob source loaded', $this->jobContext($sourceDraft, $translationRequest, null) + [
                'source_draft_id' => $this->sourceDraftId,
                'source_locale' => trim((string) $sourceDraft->getRawOriginal('language')),
            ]);
            $translationDebug->logJobStart($this->jobContext($sourceDraft, $translationRequest, null));
            $translationDebug->logStateSnapshot('Initial translation state loaded.', $this->jobContext($sourceDraft, $translationRequest, null));

            if ($this->operationId) {
                $operationService->markProcessing($this->operationId);
            }

            Log::info('TranslateDraftJob started', $this->jobContext($sourceDraft, $translationRequest, null) + [
                'translation_request_id' => $this->translationRequestId,
                'dispatch_job_uuid' => $this->dispatchJobUuid,
            ]);

            if ($translationRequest instanceof ContentTranslation) {
                $this->lastLifecycleEvent = 'LOCK_CHECKED';
                $translationDebug->logLockState('Checking translation lock before job execution.', $this->jobContext($sourceDraft, $translationRequest, null));
                $cleanup = $translationLocks->cleanupStaleLocks(collect([$translationRequest]), force: true)->first();

                if (is_array($cleanup) && (string) ($cleanup['reason'] ?? '') !== '') {
                    Log::warning('translation.job.recovering_stale_lock', $this->jobContext($sourceDraft, $translationRequest, null) + [
                        'translation_request_id' => (string) $translationRequest->id,
                        'reason' => (string) $cleanup['reason'],
                    ]);
                }
            }

            $this->markTranslationRequestStatus(ContentTranslation::STATUS_PROCESSING, null, null, $translationLocks);
            $this->lastLifecycleEvent = 'LOCK_ACQUIRED';
            $translationDebug->logLockState('Translation lock acquired for job.', $this->jobContext($sourceDraft, $translationRequest?->fresh(), null));
            $this->touchTranslationHeartbeat($translationLocks);

            $translationDebug->logStateSnapshot('Resolving target content before translation.', $this->jobContext($sourceDraft, $translationRequest?->fresh(), null));
            $resolvedTargetContent = $this->targetContentId
                ? Content::query()->findOrFail($this->targetContentId)
                : $translationService->resolveTargetVariantContent($sourceDraft, $targetLanguage);
            $allowExistingTarget = $resolvedTargetContent instanceof Content;

            if ($resolvedTargetContent instanceof Content) {
                $this->syncTranslationRequestTargetContent($resolvedTargetContent->id);
            }

            $translationDebug->logStateSnapshot('Validating source and target translation prerequisites.', $this->jobContext($sourceDraft, $translationRequest?->fresh(), null) + [
                'target_content_exists' => $resolvedTargetContent instanceof Content,
            ]);
            $translationService->validateSourceDraft($sourceDraft);
            $translationService->validateTargetLanguageAvailabilityForJob(
                draft: $sourceDraft,
                targetLanguage: $targetLanguage,
                allowExisting: $allowExistingTarget,
                currentJobUuid: $this->lockOwnerUuid(),
                currentTranslationRequestId: $this->translationRequestId,
                currentQueueJobId: $this->currentQueueJobId(),
                currentTargetContentId: $resolvedTargetContent?->id ? (string) $resolvedTargetContent->id : null,
                bypassDispatchOnlyProcessingCheck: true,
            );
            $this->lastLifecycleEvent = 'TARGET_VALIDATED';

            $reservationEntry = $this->reserveCredits($sourceDraft, $creditWalletService);

            $this->touchTranslationHeartbeat($translationLocks);
            $this->lastLifecycleEvent = 'PROVIDER_REQUEST_STARTED';
            $translationResult = $translationService->translate(
                $sourceDraft,
                $targetLanguage,
                $this->modelOverride,
                $allowExistingTarget,
                $this->jobContext($sourceDraft, $translationRequest?->fresh(), null)
            );
            $this->touchTranslationHeartbeat($translationLocks);

            Log::info('TranslateDraftJob translation response received', [
                'source_draft_id' => $this->sourceDraftId,
                'target_language' => $this->targetLanguage,
                'translated_title' => (string) ($translationResult['title'] ?? ''),
                'content_html_length' => strlen((string) ($translationResult['content_html'] ?? '')),
                'model_used' => $translationResult['model_used'] ?? null,
                'request_id' => $translationResult['request_id'] ?? null,
            ]);

            Log::info('TranslateDraftJob persisting translation variant', [
                'source_draft_id' => $this->sourceDraftId,
                'target_language' => $this->targetLanguage,
                'mode' => $allowExistingTarget ? 'refresh' : 'create',
                'target_content_id' => $resolvedTargetContent?->id ? (string) $resolvedTargetContent->id : $this->targetContentId,
            ]);
            $translationDebug->logStateSnapshot('Persisting translated content and draft.', $this->jobContext($sourceDraft, $translationRequest?->fresh(), null) + [
                'target_content_exists' => $resolvedTargetContent instanceof Content,
            ]);

            $translatedDraft = $resolvedTargetContent instanceof Content
                ? $translationService->refreshTranslatedDraft(
                    $sourceDraft,
                    $resolvedTargetContent,
                    $targetLanguage,
                    $translationResult,
                    $this->userId
                )
                : $translationService->createTranslatedDraft(
                    $sourceDraft,
                    $targetLanguage,
                    $translationResult,
                    $this->userId
                );
            $this->touchTranslationHeartbeat($translationLocks);

            Log::info('TranslateDraftJob translated draft persisted', [
                'source_draft_id' => $this->sourceDraftId,
                'translated_draft_id' => (string) $translatedDraft->id,
                'translated_content_id' => (string) ($translatedDraft->content_id ?? ''),
                'target_language' => $this->targetLanguage,
            ]);

            $contentLifecycleService->ensureRevisionFromDraft(
                $translatedDraft,
                $this->userId ? (int) $this->userId : null
            );

            if ($translatedDraft->content instanceof Content) {
                $automationItemState->syncFromContent($translatedDraft->content->fresh(['drafts', 'publications']) ?? $translatedDraft->content);
            }
            $translationDebug->logStateSnapshot('Translation saved and automation sync completed.', $this->jobContext($sourceDraft, $translationRequest?->fresh(), null) + [
                'translated_draft_id' => (string) $translatedDraft->id,
                'translated_content_id' => (string) ($translatedDraft->content_id ?? ''),
            ]);

            $this->commitCredits(
                $translatedDraft,
                $reservationEntry,
                $creditWalletService,
                $translationResult
            );

            Log::info('TranslateDraftJob completed successfully', [
                'source_draft_id' => $this->sourceDraftId,
                'translated_draft_id' => $translatedDraft->id,
                'target_language' => $this->targetLanguage,
                'target_content_id' => $this->targetContentId,
            ]);

            $this->markTranslationRequestStatus(
                ContentTranslation::STATUS_COMPLETED,
                $translatedDraft->content_id ? (string) $translatedDraft->content_id : null,
                null,
                $translationLocks
            );
            $translationDebug->logCompletion($this->jobContext($sourceDraft, $translationRequest?->fresh(), null) + [
                'translated_draft_id' => (string) $translatedDraft->id,
                'translated_content_id' => (string) ($translatedDraft->content_id ?? ''),
            ]);

            if ($this->operationId) {
                $operationService->markCompleted($this->operationId, [
                    'source_draft_id' => (string) $sourceDraft->id,
                    'translated_draft_id' => (string) $translatedDraft->id,
                    'target_language' => $this->targetLanguage,
                    'target_content_id' => $this->targetContentId,
                ]);
            }

            TranslationCompleted::dispatch(
                sourceDraftId: (string) $sourceDraft->id,
                translatedDraftId: (string) $translatedDraft->id,
                sourceContentId: $sourceDraft->content_id ? (string) $sourceDraft->content_id : null,
                translatedContentId: $translatedDraft->content_id ? (string) $translatedDraft->content_id : null,
                targetLocale: $this->targetLanguage,
            );

            if ($sourceDraft->clientSite?->workspace) {
                $webhookPublisher->publish(
                    workspace: $sourceDraft->clientSite->workspace,
                    eventType: 'draft.translated',
                    payload: [
                        'source_draft_id' => (string) $sourceDraft->id,
                        'translated_draft_id' => (string) $translatedDraft->id,
                        'target_language' => $this->targetLanguage,
                        'target_content_id' => $this->targetContentId,
                        'operation_id' => $this->operationId,
                    ],
                    contentDestinationId: $sourceDraft->content_destination_id,
                    eventId: $this->operationId ?: (string) $translatedDraft->id,
                );
            }

        } catch (InsufficientCreditsException $e) {
            $caughtException = $e;
            $skipFailureFinalizer = true;
            $failureDetails = [
                'pattern' => 'insufficient_credits',
                'error_code' => 'PL-CREDITS-INSUFFICIENT',
                'required_credits' => (int) $e->required,
                'available_credits' => (int) $e->available,
                'user_safe_message' => sprintf(
                    'This automation could not continue because there are not enough credits available. Required: %d, available: %d. Please add credits or reduce the automation scope and try again.',
                    $e->required,
                    $e->available,
                ),
                'admin_message' => implode("\n", [
                    'Exception: ' . $e::class,
                    'Required credits: ' . $e->required,
                    'Available credits: ' . $e->available,
                    'Job: ' . self::class,
                    'Source location: ' . $e->getFile() . ':' . $e->getLine(),
                    'Run ID: ' . (string) ($sourceDraft->content?->automation_run_id ?? ''),
                    'Automation ID: ' . (string) ($sourceDraft->content?->automation_id ?? ''),
                ]),
                'exception_class' => $e::class,
                'job' => self::class,
                'source_location' => $e->getFile() . ':' . $e->getLine(),
                'run_id' => (string) ($sourceDraft->content?->automation_run_id ?? ''),
                'automation_id' => (string) ($sourceDraft->content?->automation_id ?? ''),
            ];

            Log::warning('TranslateDraftJob failed: insufficient credits', $this->jobContext($sourceDraft, $translationRequest, $e) + [
                'required' => $e->required,
                'available' => $e->available,
            ]);
            $translationDebug->logFailure('Translation failed because credits were insufficient.', $this->jobContext($sourceDraft, $translationRequest, $e) + [
                'required_credits' => $e->required,
                'available_credits' => $e->available,
            ]);

            if ($translationRequest instanceof ContentTranslation) {
                $translationLocks->markTranslationInsufficientCreditsIfOwned(
                    $translationRequest,
                    $this->dispatchJobUuid,
                    $e->required,
                    $e->available,
                    sprintf(
                        'Not enough credits to translate this article. Required: %d, available: %d.',
                        $e->required,
                        $e->available
                    ),
                    'client_site_allocation',
                );
                $translationDebug->logLockState('Translation lock cleared because credits were insufficient.', $this->jobContext($sourceDraft, $translationRequest->fresh(), $e));
            }

            $automationItemState->markTranslationInsufficientCreditsFailure(
                $sourceDraft,
                $this->targetLanguage,
                $e,
                [
                    'job' => self::class,
                    'target_content_id' => $this->targetContentId,
                ],
            );

            if ($this->operationId) {
                $operationService->markFailed(
                    operationId: $this->operationId,
                    errorMessage: $failureDetails['user_safe_message'],
                    errorCode: 'PL-CREDITS-INSUFFICIENT',
                );
            }

            return;
        } catch (Throwable $e) {
            $caughtException = $e;

            Log::error('TranslateDraftJob failed', $this->jobContext($sourceDraft, $translationRequest, $e));
            Log::channel('translation')->error('TranslateDraftJob failed', [
                'trace_id' => $this->traceId,
                'job_database_id' => $this->currentQueueJobId(),
                'job_uuid' => $this->lockOwnerUuid(),
                'translation_request_id' => $this->translationRequestId,
                'source_content_id' => (string) ($sourceDraft?->content_id ?? $translationRequest?->content_id ?? $this->sourceContentId ?? ''),
                'target_locale' => $this->targetLanguage,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'last_lifecycle_event' => $this->lastLifecycleEvent,
                'early_failure_before_provider' => $this->failedBeforeProviderRequest(),
                'stack_frames' => array_slice($e->getTrace(), 0, 8),
            ]);

            if ($this->failedBeforeProviderRequest()) {
                Log::channel('translation')->error('EARLY_TRANSLATION_FAILURE_BEFORE_PROVIDER', [
                    'trace_id' => $this->traceId,
                    'job_uuid' => $this->lockOwnerUuid(),
                    'translation_request_id' => $this->translationRequestId,
                    'source_content_id' => (string) ($sourceDraft?->content_id ?? $translationRequest?->content_id ?? $this->sourceContentId ?? ''),
                    'target_locale' => $this->targetLanguage,
                    'last_lifecycle_event' => $this->lastLifecycleEvent,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
            $translationDebug->logFailure(
                $this->startedLessThan100msAgo()
                    ? 'Early translation failure detected.'
                    : 'TranslateDraftJob failed.',
                $this->jobContext($sourceDraft, $translationRequest, $e)
            );

            // Release reserved credits if we reserved them but never got a translated draft
            if ($reservationEntry && ! $translatedDraft) {
                $this->releaseCredits($sourceDraft, $reservationEntry, $creditWalletService, $e->getMessage());
            }

            // Mark async operation as failed (these don't retry)
            if ($this->operationId) {
                $operationService->markFailed(
                    operationId: $this->operationId,
                    errorMessage: $e->getMessage(),
                    errorCode: 'TRANSLATION_FAILED',
                );
            }

            if ($translationRequest instanceof ContentTranslation) {
                $translationLocks->markTranslationFailedIfOwned(
                    $translationRequest,
                    $this->dispatchJobUuid,
                    $e->getMessage(),
                );
                $translationDebug->logLockState('Translation lock cleared after failure.', $this->jobContext($sourceDraft, $translationRequest->fresh(), $e));
            }

            throw $e;
        } finally {
            if (! $skipFailureFinalizer && $caughtException instanceof Throwable && $translationRequest instanceof ContentTranslation) {
                $translationLocks->markTranslationFailedIfOwned(
                    $translationRequest,
                    $this->dispatchJobUuid,
                    $caughtException->getMessage(),
                );
                $translationDebug->logLockState('Failure finalizer executed for translation lock.', $this->jobContext($sourceDraft, $translationRequest->fresh(), $caughtException));
            }
        }
    }

    private function markTranslationRequestStatus(
        string $status,
        ?string $targetContentId,
        ?string $errorMessage,
        TranslationLockService $translationLocks,
        bool $clearJobId = false
    ): void {
        if (! $this->translationRequestId) {
            return;
        }

        $translation = ContentTranslation::query()->find($this->translationRequestId);

        if (! $translation instanceof ContentTranslation) {
            return;
        }

        if ($status === ContentTranslation::STATUS_COMPLETED) {
            $translationLocks->releaseTranslationLockIfOwned(
                $translation,
                $this->dispatchJobUuid,
                ContentTranslation::STATUS_COMPLETED,
                $targetContentId,
                null,
            );

            return;
        }

        if ($status === ContentTranslation::STATUS_FAILED) {
            $translationLocks->markTranslationFailedIfOwned($translation, $this->dispatchJobUuid, $errorMessage);

            return;
        }

        $translation = $translationLocks->claimTranslationLockForJob(
            $translation,
            $clearJobId ? (string) Str::uuid() : $this->lockOwnerUuid(),
            $clearJobId ? null : $this->currentQueueJobId(),
        );

        if ($targetContentId !== null) {
            $translation->forceFill([
                'target_content_id' => $targetContentId,
            ])->save();
        }

        if ($errorMessage !== null && $status === ContentTranslation::STATUS_QUEUED) {
            $translation->forceFill([
                'processing_error_message' => $errorMessage,
                'error_message' => $errorMessage,
                'status' => ContentTranslation::STATUS_FAILED,
                'processing_failed_at' => now(),
            ])->save();
        }
    }

    private function markTranslationRequestFailed(
        string $message,
        bool $clearJobId = true,
        ?TranslationLockService $translationLocks = null
    ): void
    {
        $translationLocks ??= app(TranslationLockService::class);

        $this->markTranslationRequestStatus(ContentTranslation::STATUS_FAILED, null, $message, $translationLocks, $clearJobId);
    }

    private function touchTranslationHeartbeat(TranslationLockService $translationLocks): void
    {
        if (! $this->translationRequestId) {
            return;
        }

        $translation = ContentTranslation::query()->find($this->translationRequestId);

        if ($translation instanceof ContentTranslation) {
            $translationLocks->touchHeartbeat($translation, $this->lockOwnerUuid(), $this->currentQueueJobId());
        }
    }

    private function syncTranslationRequestTargetContent(string $targetContentId): void
    {
        if (! $this->translationRequestId || trim($targetContentId) === '') {
            return;
        }

        $translation = ContentTranslation::query()->find($this->translationRequestId);

        if (! $translation instanceof ContentTranslation) {
            return;
        }

        if ((string) $translation->target_content_id === $targetContentId) {
            return;
        }

        $translation->forceFill([
            'target_content_id' => $targetContentId,
        ])->save();
    }

    private function currentQueueJobId(): ?string
    {
        return $this->job && method_exists($this->job, 'getJobId')
            ? (string) $this->job->getJobId()
            : null;
    }

    private function currentQueueJobUuid(): ?string
    {
        if ($this->job && method_exists($this->job, 'uuid')) {
            $uuid = $this->job->uuid();

            if (is_string($uuid) && trim($uuid) !== '') {
                return $uuid;
            }
        }

        if ($this->job && method_exists($this->job, 'payload')) {
            $payload = $this->job->payload();
            $uuid = is_array($payload) ? data_get($payload, 'uuid') : null;

            if (is_string($uuid) && trim($uuid) !== '') {
                return $uuid;
            }
        }

        return null;
    }

    private function lockOwnerUuid(): string
    {
        return $this->dispatchJobUuid ?: ($this->currentQueueJobUuid() ?: (string) Str::uuid());
    }

    private function startedLessThan100msAgo(): bool
    {
        if ($this->startedAtNs === null) {
            return false;
        }

        return (hrtime(true) - $this->startedAtNs) < 100_000_000;
    }

    private function failedBeforeProviderRequest(): bool
    {
        return $this->lastLifecycleEvent !== 'PROVIDER_REQUEST_STARTED';
    }

    /**
     * @return array<string,mixed>
     */
    private function jobContext(?Draft $sourceDraft, ?ContentTranslation $translationRequest, ?Throwable $exception): array
    {
        $content = $sourceDraft?->content;
        $workspace = $sourceDraft?->clientSite?->workspace;

        return [
            'content_id' => (string) ($content?->id ?? $translationRequest?->target_content_id ?? ''),
            'source_content_id' => (string) ($sourceDraft?->content_id ?? $translationRequest?->content_id ?? $this->sourceContentId ?? ''),
            'target_content_id' => (string) ($this->targetContentId ?? $translationRequest?->target_content_id ?? ''),
            'source_locale' => $sourceDraft ? trim((string) $sourceDraft->getRawOriginal('language')) : null,
            'target_locale' => $this->targetLanguage,
            'organization_id' => $workspace?->organization_id,
            'job_uuid' => $this->lockOwnerUuid(),
            'queue_name' => $this->queue,
            'queue_connection' => $this->connection,
            'trace_id' => $this->traceId,
            'translation_request_id' => $this->translationRequestId,
            'last_lifecycle_event' => $this->lastLifecycleEvent,
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $exception?->getMessage(),
            'stack_trace' => $exception?->getTraceAsString(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
        ];
    }

    private function reserveCredits(
        Draft $sourceDraft,
        CreditWalletService $creditWalletService,
    ): CreditLedgerEntry {
        $action = CreditAction::query()
            ->where('key', 'translate.locale_version')
            ->where('is_active', true)
            ->first();

        $cost = $action
            ? (int) $action->credits_cost
            : (int) config('translation.default_credit_cost', 6);

        $idempotencyKey = sprintf(
            'translation:%s:%s:reserve',
            $this->sourceDraftId,
            $this->targetLanguage
        );

        $existing = CreditLedgerEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($sourceDraft, $cost, $action, $idempotencyKey) {
            $wallet = CreditWallet::query()
                ->where('client_site_id', $sourceDraft->client_site_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException('No credit wallet found for client site.');
            }

            $available = (int) $wallet->available;
            if ($available < $cost) {
                throw new InsufficientCreditsException($cost, $available);
            }

            $organizationId = $sourceDraft->clientSite?->workspace?->organization_id;

            $reservation = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => CreditWalletService::TYPE_RESERVATION,
                'source' => 'usage',
                'amount' => $cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $this->sourceDraftId,
                'brief_id' => $sourceDraft->brief_id,
                'client_site_id' => $sourceDraft->client_site_id,
                'organization_id' => $organizationId,
                'user_id' => $this->userId,
                'meta' => [
                    'action' => 'draft_translation',
                    'credit_action_id' => $action?->id,
                    'credit_action_key' => 'translate.locale_version',
                    'source_draft_id' => $this->sourceDraftId,
                    'target_language' => $this->targetLanguage,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($wallet->getTable() !== 'site_credit_allocations') {
                $wallet->reserved_cached += $cost;
                $wallet->save();
            }
            app(SiteCreditAllocationService::class)->reserve((string) $sourceDraft->client_site_id, $cost);
            app(WorkspaceCreditLedgerService::class)->adjustReserved((string) $sourceDraft->clientSite?->workspace_id, $cost);
            app(WorkspaceCreditLedgerService::class)->recordReservation(
                workspaceId: (string) $sourceDraft->clientSite?->workspace_id,
                amount: $cost,
                clientSiteId: (string) $sourceDraft->client_site_id,
                allocationId: \App\Models\SiteCreditAllocation::query()->where('client_site_id', $sourceDraft->client_site_id)->value('id'),
                metadata: [
                    'feature' => 'draft_translation',
                    'source_draft_id' => $this->sourceDraftId,
                    'target_language' => $this->targetLanguage,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            return $reservation;
        });
    }

    private function commitCredits(
        Draft $translatedDraft,
        CreditLedgerEntry $reservationEntry,
        CreditWalletService $creditWalletService,
        array $translationResult,
    ): void {
        $cost = (int) $reservationEntry->amount;

        $usageIdempotencyKey = sprintf(
            'translation:%s:%s:usage:%s',
            $this->sourceDraftId,
            $this->targetLanguage,
            $reservationEntry->id
        );

        $existing = CreditLedgerEntry::query()
            ->where('idempotency_key', $usageIdempotencyKey)
            ->first();

        if ($existing) {
            $translatedDraft->credit_ledger_entry_id = $existing->id;
            $translatedDraft->credit_status = 'committed';
            $translatedDraft->credit_cost = $cost;
            $translatedDraft->save();

            return;
        }

        DB::transaction(function () use (
            $translatedDraft,
            $reservationEntry,
            $cost,
            $usageIdempotencyKey,
            $translationResult,
        ) {
            $wallet = CreditWallet::query()
                ->whereKey($reservationEntry->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => CreditWalletService::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $translatedDraft->id,
                'brief_id' => $translatedDraft->brief_id,
                'client_site_id' => $translatedDraft->client_site_id,
                'organization_id' => $reservationEntry->organization_id,
                'user_id' => $this->userId,
                'meta' => [
                    'reservation_entry_id' => $reservationEntry->id,
                    'reason' => 'capture_for_translation',
                ],
                'idempotency_key' => sprintf(
                    'translation:%s:%s:release:%s',
                    $this->sourceDraftId,
                    $this->targetLanguage,
                    $reservationEntry->id
                ),
            ]);

            $usage = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => CreditWalletService::TYPE_USAGE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $translatedDraft->id,
                'brief_id' => $translatedDraft->brief_id,
                'client_site_id' => $translatedDraft->client_site_id,
                'organization_id' => $reservationEntry->organization_id,
                'user_id' => $this->userId,
                'meta' => [
                    'action' => 'draft_translation',
                    'credit_action_key' => 'translate.locale_version',
                    'source_draft_id' => $this->sourceDraftId,
                    'target_language' => $this->targetLanguage,
                    'reservation_entry_id' => $reservationEntry->id,
                    'llm_model' => $translationResult['model_used'] ?? null,
                    'llm_input_tokens' => $translationResult['input_tokens'] ?? 0,
                    'llm_output_tokens' => $translationResult['output_tokens'] ?? 0,
                    'llm_total_tokens' => $translationResult['total_tokens'] ?? 0,
                    'llm_request_id' => $translationResult['request_id'] ?? null,
                ],
                'idempotency_key' => $usageIdempotencyKey,
            ]);

            if ($wallet->getTable() !== 'site_credit_allocations') {
                $wallet->reserved_cached = max(0, $wallet->reserved_cached - $cost);
                $wallet->balance_cached -= $cost;
                $wallet->save();
            }
            app(SiteCreditAllocationService::class)->captureUsage((string) $translatedDraft->client_site_id, $cost, $cost);
            app(WorkspaceCreditLedgerService::class)->adjustReserved((string) $translatedDraft->clientSite?->workspace_id, -$cost);
            $workspaceUsage = app(WorkspaceCreditLedgerService::class)->commitUsage(
                workspaceId: (string) $translatedDraft->clientSite?->workspace_id,
                amount: $cost,
                clientSiteId: (string) $translatedDraft->client_site_id,
                allocationId: \App\Models\SiteCreditAllocation::query()->where('client_site_id', $translatedDraft->client_site_id)->value('id'),
                metadata: [
                    'feature' => 'draft_translation',
                    'source_draft_id' => $this->sourceDraftId,
                    'target_language' => $this->targetLanguage,
                ],
                referenceType: Draft::class,
                referenceId: (string) $translatedDraft->id,
                idempotencyKey: 'workspace:' . $usageIdempotencyKey
            );

            $translatedDraft->credit_wallet_id = $wallet->id;
            $translatedDraft->workspace_credit_wallet_id = $workspaceUsage->workspace_credit_wallet_id;
            $translatedDraft->credit_ledger_entry_id = $usage->id;
            $translatedDraft->workspace_credit_transaction_id = $workspaceUsage->id;
            $translatedDraft->credit_status = 'committed';
            $translatedDraft->credit_cost = $cost;
            $translatedDraft->save();

            if ($translatedDraft->content_id) {
                ContentCreditLog::create([
                    'id' => (string) Str::uuid(),
                    'content_id' => $translatedDraft->content_id,
                    'draft_id' => $translatedDraft->id,
                    'credit_ledger_entry_id' => $usage->id,
                    'workspace_credit_transaction_id' => $workspaceUsage->id,
                    'event' => 'commit',
                    'credits_used' => $cost,
                    'mode_multiplier' => 1.0,
                    'meta' => [
                        'event_type' => 'translation',
                        'source_draft_id' => $this->sourceDraftId,
                        'target_language' => $this->targetLanguage,
                    ],
                ]);
            }
        });
    }

    private function releaseCredits(
        Draft $sourceDraft,
        CreditLedgerEntry $reservationEntry,
        CreditWalletService $creditWalletService,
        string $reason,
    ): void {
        $cost = (int) $reservationEntry->amount;

        $releaseIdempotencyKey = sprintf(
            'translation:%s:%s:release-fail:%s',
            $this->sourceDraftId,
            $this->targetLanguage,
            $reservationEntry->id
        );

        $existing = CreditLedgerEntry::query()
            ->where('idempotency_key', $releaseIdempotencyKey)
            ->first();

        if ($existing) {
            return;
        }

        DB::transaction(function () use ($sourceDraft, $reservationEntry, $cost, $releaseIdempotencyKey, $reason) {
            $wallet = CreditWallet::query()
                ->whereKey($reservationEntry->credit_wallet_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                return;
            }

            CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => CreditWalletService::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $this->sourceDraftId,
                'brief_id' => $sourceDraft->brief_id,
                'client_site_id' => $sourceDraft->client_site_id,
                'organization_id' => $reservationEntry->organization_id,
                'user_id' => $this->userId,
                'meta' => [
                    'reservation_entry_id' => $reservationEntry->id,
                    'reason' => 'translation_failed',
                    'failure_message' => mb_substr($reason, 0, 500),
                ],
                'idempotency_key' => $releaseIdempotencyKey,
            ]);

            if ($wallet->getTable() !== 'site_credit_allocations') {
                $wallet->reserved_cached = max(0, $wallet->reserved_cached - $cost);
                $wallet->save();
            }
            app(SiteCreditAllocationService::class)->releaseReserved((string) $sourceDraft->client_site_id, $cost);
            app(WorkspaceCreditLedgerService::class)->adjustReserved((string) $sourceDraft->clientSite?->workspace_id, -$cost);
            app(WorkspaceCreditLedgerService::class)->recordRelease(
                workspaceId: (string) $sourceDraft->clientSite?->workspace_id,
                amount: $cost,
                clientSiteId: (string) $sourceDraft->client_site_id,
                allocationId: \App\Models\SiteCreditAllocation::query()->where('client_site_id', $sourceDraft->client_site_id)->value('id'),
                metadata: [
                    'feature' => 'draft_translation',
                    'source_draft_id' => $this->sourceDraftId,
                    'target_language' => $this->targetLanguage,
                    'reason' => 'translation_failed',
                ]
            );
        });

        Log::info('Translation credits released due to failure', [
            'source_draft_id' => $this->sourceDraftId,
            'target_language' => $this->targetLanguage,
            'credits_released' => $cost,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $sourceDraft = Draft::query()->with('content')->find($this->sourceDraftId);
        if ($sourceDraft instanceof Draft) {
            app(AutomationRunItemStateService::class)->markTranslationFailure(
                $sourceDraft,
                $this->targetLanguage,
                $exception,
            );
        }

        $translationRequest = $this->translationRequestId
            ? ContentTranslation::query()->find($this->translationRequestId)
            : null;
        $this->traceId = $this->traceId ?: (string) ($translationRequest?->translation_trace_id ?: $this->traceId);

        Log::error('TranslateDraftJob permanently failed', $this->jobContext($sourceDraft, $translationRequest, $exception));
        app(TranslationDebugService::class)->logFailure(
            'TranslateDraftJob failed callback executed.',
            $this->jobContext($sourceDraft, $translationRequest, $exception)
        );

        if ($translationRequest instanceof ContentTranslation) {
            app(TranslationLockService::class)->markTranslationFailedIfOwned(
                $translationRequest,
                $this->dispatchJobUuid,
                $exception->getMessage(),
            );
            app(TranslationDebugService::class)->logLockState(
                'Failed callback cleared owned translation lock.',
                $this->jobContext($sourceDraft, $translationRequest->fresh(), $exception)
            );
        }
    }
}
