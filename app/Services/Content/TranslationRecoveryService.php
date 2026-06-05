<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Jobs\TranslateDraftJob;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Translation\TranslationLockRepairService;
use App\Services\Translation\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class TranslationRecoveryService
{
    public function __construct(
        private readonly TranslationLockRepairService $repairService,
        private readonly TranslationLockService $translationLocks,
        private readonly TranslationDebugService $translationDebug,
        private readonly ContentLocalizationService $localizations,
        private readonly LocaleMismatchService $mismatchService,
        private readonly TranslationService $translations,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryExistingTranslation(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        $translation = $translation->fresh(['content', 'targetContent']) ?? $translation;

        if (! $this->ensureRecoverable($translation)) {
            return ['ok' => false, 'message' => 'Completed translations cannot enter the recovery flow.'];
        }

        $before = $this->snapshot($translation);
        $requeued = $this->dispatchExistingTranslation($translation, $actor, false);

        $this->auditLogService->log($actor, $requeued, 'translation.retry_requeued', $before, $this->snapshot($requeued), $request);
        $this->translationDebug->logRecovery(
            'Existing translation recovered and requeued.',
            $this->translationDebug->buildContext($requeued, [
                'actor_id' => $actor?->id,
                'recovery_action' => 'retry_existing_translation',
            ], $requeued->content)
        );

        return ['ok' => true, 'message' => 'Existing translation recovered and requeued.'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function forceResetAndRetry(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        $translation = $translation->fresh(['content', 'targetContent']) ?? $translation;

        if (! $this->ensureRecoverable($translation)) {
            return ['ok' => false, 'message' => 'Completed translations cannot enter the recovery flow.'];
        }

        $before = $this->snapshot($translation);
        $failedJobIds = $this->linkedFailedJobIds($translation);
        $deletedFailedJobs = $this->deleteLinkedFailedJobs($failedJobIds);
        $deletedQueuedJobs = $this->translationLocks->clearQueuedJobsForFingerprint(
            (string) $translation->content_id,
            (string) $translation->target_locale,
        );

        $requeued = $this->dispatchExistingTranslation($translation, $actor, true);

        $this->auditLogService->log($actor, $requeued, 'translation.force_reset_and_retry', $before, $this->snapshot($requeued), $request);
        $this->translationDebug->logRecovery(
            'Translation reset and queued again.',
            $this->translationDebug->buildContext($requeued, [
                'actor_id' => $actor?->id,
                'recovery_action' => 'force_reset_and_retry',
                'deleted_failed_jobs' => $deletedFailedJobs,
                'deleted_queued_jobs' => $deletedQueuedJobs,
            ], $requeued->content)
        );

        Log::warning('translation.recovery.force_reset_and_retry', [
            'translation_id' => (string) $translation->id,
            'actor_id' => $actor?->id,
            'deleted_failed_jobs' => $deletedFailedJobs,
            'deleted_queued_jobs' => $deletedQueuedJobs,
        ]);

        return ['ok' => true, 'message' => 'Translation reset and queued again.'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function releaseLock(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        $translation = $translation->fresh(['content']) ?? $translation;

        if (! $this->ensureRecoverable($translation)) {
            return ['ok' => false, 'message' => 'Completed translations do not have a recoverable lock to release.'];
        }

        $before = $this->snapshot($translation);
        $released = $this->repairService->releaseLock($translation, 'Stale lock cleared successfully.', true);

        if (! $released) {
            return ['ok' => false, 'message' => 'Recovery failed because the translation record is corrupted.'];
        }

        $fresh = $translation->fresh(['content']) ?? $translation;
        $this->auditLogService->log($actor, $fresh, 'translation.lock.released', $before, $this->snapshot($fresh), $request);
        $this->translationDebug->logRecovery(
            'Stale lock cleared successfully.',
            $this->translationDebug->buildContext($fresh, [
                'actor_id' => $actor?->id,
                'recovery_action' => 'release_lock',
            ], $fresh->content)
        );

        return ['ok' => true, 'message' => 'Stale lock cleared successfully.'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function markAsFailed(ContentTranslation $translation, ?User $actor = null, ?Request $request = null): array
    {
        $translation = $translation->fresh(['content']) ?? $translation;

        if (! $this->ensureRecoverable($translation)) {
            return ['ok' => false, 'message' => 'Completed translations cannot be marked as failed from recovery tools.'];
        }

        $before = $this->snapshot($translation);
        $fresh = $this->translationLocks->markTranslationFailed($translation, 'Translation marked as failed by platform admin.');

        $this->auditLogService->log($actor, $fresh, 'translation.marked_failed', $before, $this->snapshot($fresh), $request);
        $this->translationDebug->logRecovery(
            'Translation marked as failed.',
            $this->translationDebug->buildContext($fresh, [
                'actor_id' => $actor?->id,
                'recovery_action' => 'mark_as_failed',
            ], $fresh->content)
        );

        return ['ok' => true, 'message' => 'Translation marked as failed.'];
    }

    private function dispatchExistingTranslation(ContentTranslation $translation, ?User $actor = null, bool $forceReset = false): ContentTranslation
    {
        $translation->loadMissing('content', 'targetContent');
        $sourceContent = $translation->content;

        if (! $sourceContent instanceof Content) {
            throw new RuntimeException('Recovery failed because translation record is corrupted.');
        }

        $sourceContent = $this->mismatchService->autoCorrectSourceLocale(
            $this->localizations->source($sourceContent)
        )['content'];

        $targetLanguage = SupportedLanguage::fromStringOrDefault((string) $translation->target_locale);
        $sourceSelection = $this->localizations->resolveTranslationSource(
            $sourceContent,
            $actor?->id ?? ($translation->requested_by_user_id ? (int) $translation->requested_by_user_id : null)
        );
        $sourceDraft = $sourceSelection['draft'];
        $existingVariant = $translation->targetContent
            ?? $this->localizations->variantForLocale($sourceContent, $targetLanguage->value);

        $this->translations->validateSourceDraft($sourceDraft);
        $this->translations->validateTargetLanguageAvailabilityForJob(
            draft: $sourceDraft,
            targetLanguage: $targetLanguage,
            allowExisting: $existingVariant instanceof Content,
            currentJobUuid: trim((string) ($translation->processing_job_uuid ?? '')),
            currentTranslationRequestId: (string) $translation->id,
            currentQueueJobId: trim((string) ($translation->job_id ?? '')),
            currentTargetContentId: $existingVariant?->id ? (string) $existingVariant->id : null,
            bypassDispatchOnlyProcessingCheck: true,
        );

        $dispatchJobUuid = (string) Str::uuid();
        $traceId = (string) ($translation->translation_trace_id ?: Str::uuid());

        $queuedTranslation = DB::transaction(function () use (
            $translation,
            $sourceContent,
            $targetLanguage,
            $dispatchJobUuid,
            $traceId,
            $actor,
            $existingVariant,
            $forceReset
        ): ContentTranslation {
            $locked = ContentTranslation::query()
                ->whereKey($translation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->ensureRecoverable($locked)) {
                throw new RuntimeException('Completed translations cannot enter the recovery flow.');
            }

            $this->clearRuntimeState($locked, $actor?->id ? (string) $actor->id : null, $existingVariant, $traceId, $forceReset);
            $locked = $this->translationLocks->acquireTranslationLock($locked, ContentTranslation::STATUS_QUEUED, $dispatchJobUuid);
            $this->translationLocks->clearQueuedJobsForFingerprint((string) $sourceContent->id, $targetLanguage->value, $dispatchJobUuid);

            return $locked->fresh(['content', 'targetContent']) ?? $locked;
        });

        $job = new TranslateDraftJob(
            sourceDraftId: (string) $sourceDraft->id,
            targetLanguage: $targetLanguage->value,
            userId: $actor?->id ? (string) $actor->id : ($queuedTranslation->requested_by_user_id ? (string) $queuedTranslation->requested_by_user_id : null),
            targetContentId: $existingVariant?->id ? (string) $existingVariant->id : null,
            translationRequestId: (string) $queuedTranslation->id,
            dispatchJobUuid: $dispatchJobUuid,
            sourceContentId: (string) $sourceContent->id,
            traceId: $traceId,
        );

        $this->translationDebug->logDispatch(
            $this->translationDebug->buildContext($queuedTranslation, [
                'trace_id' => $traceId,
                'queue_name' => config('translation.queue.name', 'default'),
                'dispatch_job_uuid' => $dispatchJobUuid,
                'recovery_action' => $forceReset ? 'force_reset_and_retry' : 'retry_existing_translation',
            ], $sourceContent)
        );

        dispatch($job)->afterCommit();

        return $queuedTranslation;
    }

    private function clearRuntimeState(
        ContentTranslation $translation,
        ?string $actorId,
        ?Content $existingVariant,
        string $traceId,
        bool $forceReset
    ): void {
        $translation->forceFill([
            'target_content_id' => $existingVariant?->id ?: $translation->target_content_id,
            'requested_by_user_id' => $actorId ?: $translation->requested_by_user_id,
            'translation_trace_id' => $traceId,
            'status' => ContentTranslation::STATUS_FAILED,
            'failure_reason' => null,
            'required_credits' => null,
            'available_credits' => null,
            'entitlement_source' => null,
            'job_id' => null,
            'processing_started_at' => null,
            'processing_job_uuid' => null,
            'processing_locked_at' => null,
            'processing_last_heartbeat_at' => null,
            'processing_failed_at' => null,
            'processing_error_message' => null,
            'processing_recovery_count' => $forceReset ? 0 : (int) ($translation->processing_recovery_count ?? 0),
            'error_message' => null,
        ])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(ContentTranslation $translation): array
    {
        return $translation->only([
            'status',
            'failure_reason',
            'target_content_id',
            'job_id',
            'processing_job_uuid',
            'processing_started_at',
            'processing_locked_at',
            'processing_last_heartbeat_at',
            'processing_failed_at',
            'processing_error_message',
            'processing_recovery_count',
            'error_message',
        ]);
    }

    private function ensureRecoverable(ContentTranslation $translation): bool
    {
        return (string) $translation->status !== ContentTranslation::STATUS_COMPLETED;
    }

    /**
     * @return Collection<int,string>
     */
    private function linkedFailedJobIds(ContentTranslation $translation): Collection
    {
        $failedJobs = $this->translationLocks->failedJobsByTranslationRequestId([(string) $translation->id]);

        return collect($failedJobs[(string) $translation->id] ?? [])
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter();
    }

    private function deleteLinkedFailedJobs(Collection $failedJobIds): int
    {
        if ($failedJobIds->isEmpty() || ! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')
            ->whereIn('id', $failedJobIds->all())
            ->delete();
    }
}
