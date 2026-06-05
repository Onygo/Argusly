<?php

namespace App\Services\Content;

use App\Jobs\TranslateDraftJob;
use App\Models\ContentTranslation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TranslationLockService
{
    public function __construct(
        private readonly TranslationDebugService $debug,
    ) {}

    public function staleTimeoutMinutes(): int
    {
        return max(5, (int) config('publishlayer.translations.stale_lock_timeout_minutes', 10));
    }

    public function staleTimeoutSeconds(): int
    {
        return $this->staleTimeoutMinutes() * 60;
    }

    public function acquireTranslationLock(
        ContentTranslation $translation,
        string $status = ContentTranslation::STATUS_QUEUED,
        ?string $jobUuid = null,
        ?string $jobId = null,
        ?CarbonInterface $now = null,
    ): ContentTranslation {
        $timestamp = ($now ?? now())->copy();

        $translation->forceFill([
            'status' => $status,
            'failure_reason' => null,
            'required_credits' => null,
            'available_credits' => null,
            'entitlement_source' => null,
            'job_id' => $jobId,
            'processing_job_uuid' => $jobUuid,
            'processing_locked_at' => $timestamp,
            'processing_last_heartbeat_at' => $timestamp,
            'processing_started_at' => $status === ContentTranslation::STATUS_PROCESSING
                ? ($translation->processing_started_at ?? $timestamp)
                : $translation->processing_started_at,
            'processing_failed_at' => null,
            'processing_last_recovered_at' => $status === ContentTranslation::STATUS_FAILED
                ? ($translation->processing_last_recovered_at ?? $timestamp)
                : null,
            'processing_error_message' => null,
            'processing_recovery_count' => $status === ContentTranslation::STATUS_PROCESSING
                ? (int) ($translation->processing_recovery_count ?? 0)
                : 0,
            'error_message' => null,
            'updated_at' => $timestamp,
        ]);

        $translation->save();

        return $translation->fresh() ?? $translation;
    }

    public function lockBelongsToJob(ContentTranslation $translation, ?string $jobUuid): bool
    {
        $jobUuid = trim((string) $jobUuid);

        return $jobUuid !== ''
            && trim((string) $translation->processing_job_uuid) === $jobUuid;
    }

    public function translationJobIsFresh(ContentTranslation $translation): bool
    {
        return $this->translationIsActuallyRunning(
            $translation,
            collect($this->pendingJobsByTranslationRequestId([(string) $translation->id])[(string) $translation->id] ?? [])
        );
    }

    public function claimTranslationLockForJob(
        ContentTranslation $translation,
        string $jobUuid,
        ?string $jobId = null,
    ): ContentTranslation {
        return DB::transaction(function () use ($translation, $jobUuid, $jobId): ContentTranslation {
            $locked = ContentTranslation::query()->lockForUpdate()->findOrFail($translation->id);
            $jobUuid = trim($jobUuid);

            $this->cleanupStaleLocks(collect([$locked]), force: true);
            $locked = $locked->fresh() ?? $locked;

            if ($this->lockBelongsToJob($locked, $jobUuid) || $this->queueJobBelongsToTranslation($locked, $jobId)) {
                return $this->acquireTranslationLock(
                    $locked,
                    ContentTranslation::STATUS_PROCESSING,
                    $jobUuid,
                    $jobId,
                );
            }

            if ($this->translationJobIsFresh($locked)) {
                throw new \RuntimeException(
                    sprintf(
                        "A translation to '%s' is already processing.",
                        \App\Enums\SupportedLanguage::fromStringOrDefault((string) $locked->target_locale)->englishLabel()
                    )
                );
            }

            return $this->acquireTranslationLock(
                $locked,
                ContentTranslation::STATUS_PROCESSING,
                $jobUuid,
                $jobId,
            );
        });
    }

    public function releaseTranslationLock(
        ContentTranslation $translation,
        string $status = ContentTranslation::STATUS_COMPLETED,
        ?string $targetContentId = null,
        ?string $message = null,
        ?CarbonInterface $now = null,
    ): ContentTranslation {
        $timestamp = ($now ?? now())->copy();

        $translation->forceFill([
            'status' => $status,
            'target_content_id' => $targetContentId ?? $translation->target_content_id,
            'failure_reason' => $status === ContentTranslation::STATUS_FAILED
                ? ($translation->failure_reason ?: ContentTranslation::FAILURE_REASON_UNKNOWN)
                : null,
            'job_id' => null,
            'processing_job_uuid' => null,
            'processing_locked_at' => null,
            'processing_last_heartbeat_at' => null,
            'processing_started_at' => $status === ContentTranslation::STATUS_COMPLETED
                ? ($translation->processing_started_at ?? $timestamp)
                : $translation->processing_started_at,
            'processing_failed_at' => $status === ContentTranslation::STATUS_FAILED ? $timestamp : null,
            'processing_last_recovered_at' => $status === ContentTranslation::STATUS_FAILED ? $timestamp : $translation->processing_last_recovered_at,
            'processing_error_message' => $message,
            'processing_recovery_count' => $status === ContentTranslation::STATUS_FAILED
                ? (int) ($translation->processing_recovery_count ?? 0)
                : 0,
            'error_message' => $message,
            'updated_at' => $timestamp,
        ]);

        $translation->save();

        return $translation->fresh() ?? $translation;
    }

    public function releaseTranslationLockIfOwned(
        ContentTranslation $translation,
        ?string $jobUuid,
        string $status = ContentTranslation::STATUS_FAILED,
        ?string $targetContentId = null,
        ?string $message = null,
    ): ContentTranslation {
        $fresh = $translation->fresh() ?? $translation;

        if (! $this->lockBelongsToJob($fresh, $jobUuid)) {
            return $fresh;
        }

        return $this->releaseTranslationLock($fresh, $status, $targetContentId, $message);
    }

    public function markTranslationFailed(
        ContentTranslation $translation,
        ?string $message = null,
        bool $stale = false,
        ?CarbonInterface $now = null,
    ): ContentTranslation {
        $timestamp = ($now ?? now())->copy();
        $finalMessage = $this->normalizeFailureMessage($message ?: 'Translation failed.');

        if ($stale && ! str_contains($finalMessage, ContentTranslation::STALE_LOCK_MARKER)) {
            $finalMessage = trim(ContentTranslation::STALE_LOCK_MARKER . ' ' . $finalMessage);
        }

        return $this->releaseTranslationLock(
            translation: $translation,
            status: ContentTranslation::STATUS_FAILED,
            targetContentId: null,
            message: $finalMessage,
            now: $timestamp,
        );
    }

    public function markTranslationInsufficientCredits(
        ContentTranslation $translation,
        int $requiredCredits,
        int $availableCredits,
        ?string $message = null,
        ?string $entitlementSource = null,
        ?CarbonInterface $now = null,
    ): ContentTranslation {
        $timestamp = ($now ?? now())->copy();
        $finalMessage = trim($message ?: sprintf(
            'Not enough credits to translate this article. Required: %d, available: %d.',
            $requiredCredits,
            $availableCredits
        ));

        $translation->forceFill([
            'status' => ContentTranslation::STATUS_FAILED,
            'failure_reason' => ContentTranslation::FAILURE_REASON_INSUFFICIENT_CREDITS,
            'required_credits' => $requiredCredits,
            'available_credits' => $availableCredits,
            'entitlement_source' => $entitlementSource,
            'job_id' => null,
            'processing_job_uuid' => null,
            'processing_locked_at' => null,
            'processing_last_heartbeat_at' => null,
            'processing_started_at' => $translation->processing_started_at,
            'processing_failed_at' => $timestamp,
            'processing_error_message' => $finalMessage,
            'error_message' => $finalMessage,
            'updated_at' => $timestamp,
        ])->save();

        return $translation->fresh() ?? $translation;
    }

    public function markTranslationInsufficientCreditsIfOwned(
        ContentTranslation $translation,
        ?string $jobUuid,
        int $requiredCredits,
        int $availableCredits,
        ?string $message = null,
        ?string $entitlementSource = null,
    ): ContentTranslation {
        $fresh = $translation->fresh() ?? $translation;

        if (! $this->lockBelongsToJob($fresh, $jobUuid)) {
            return $fresh;
        }

        return $this->markTranslationInsufficientCredits(
            $fresh,
            $requiredCredits,
            $availableCredits,
            $message,
            $entitlementSource,
        );
    }

    public function markTranslationFailedIfOwned(
        ContentTranslation $translation,
        ?string $jobUuid,
        ?string $message = null,
        bool $stale = false,
    ): ContentTranslation {
        $fresh = $translation->fresh() ?? $translation;

        if (! $this->lockBelongsToJob($fresh, $jobUuid)) {
            return $fresh;
        }

        return $this->markTranslationFailed($fresh, $message, $stale);
    }

    /**
     * @param  Collection<int,ContentTranslation>|null  $translations
     * @return Collection<int,array{
     *     translation:ContentTranslation,
     *     reason:string,
     *     running:bool,
     *     pending_jobs:Collection<int,array<string,mixed>>,
     *     failed_jobs:Collection<int,array<string,mixed>>,
     *     heartbeat_age_seconds:?int,
     *     queue_state:string
     * }>
     */
    public function detectStaleLocks(?Collection $translations = null, ?string $contentId = null, ?string $locale = null, int $limit = 250): Collection
    {
        $translations ??= ContentTranslation::query()
            ->when($contentId, fn ($query) => $query->where('content_id', $contentId))
            ->when($locale, fn ($query) => $query->where('target_locale', $locale))
            ->whereIn('status', [
                ContentTranslation::STATUS_QUEUED,
                ContentTranslation::STATUS_PROCESSING,
                ContentTranslation::STATUS_FAILED,
            ])
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->get();

        if ($translations->isEmpty()) {
            return collect();
        }

        $indexedPending = $this->pendingJobsByTranslationRequestId(
            $translations->pluck('id')->map(fn ($id) => (string) $id)->all()
        );
        $indexedFailed = $this->failedJobsByTranslationRequestId(
            $translations->pluck('id')->map(fn ($id) => (string) $id)->all()
        );

        return $translations->map(function (ContentTranslation $translation) use ($indexedPending, $indexedFailed): array {
            $pendingJobs = collect($indexedPending[(string) $translation->id] ?? []);
            $failedJobs = collect($indexedFailed[(string) $translation->id] ?? []);
            $running = $this->translationIsActuallyRunning($translation, $pendingJobs);
            $reason = $this->staleReason($translation, $running, $pendingJobs, $failedJobs);
            $heartbeatAt = $this->lockReferenceAt($translation);
            $queueState = $this->queueState($translation, $running, $pendingJobs, $failedJobs, $reason);

            $this->debug->logQueueState(
                'Translation queue state inspected.',
                $this->debug->buildContext($translation, [
                    'queue_state' => $queueState,
                    'reason' => $reason,
                    'pending_jobs_count' => $pendingJobs->count(),
                    'failed_jobs_count' => $failedJobs->count(),
                    'queue_job_exists' => $this->translationJobExists($translation->processing_job_uuid, (string) $translation->id),
                    'failed_job_exists' => $failedJobs->isNotEmpty(),
                    'running' => $running,
                    'heartbeat_age_seconds' => $heartbeatAt ? $heartbeatAt->diffInSeconds(now()) : null,
                ])
            );

            return [
                'translation' => $translation,
                'reason' => $reason ?? '',
                'running' => $running,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'heartbeat_age_seconds' => $heartbeatAt ? $heartbeatAt->diffInSeconds(now()) : null,
                'queue_state' => $queueState,
            ];
        })->values();
    }

    /**
     * @param  Collection<int,ContentTranslation>|null  $translations
     * @return Collection<int,array<string,mixed>>
     */
    public function cleanupStaleLocks(?Collection $translations = null, bool $force = false, ?string $contentId = null, ?string $locale = null): Collection
    {
        $rows = $this->detectStaleLocks($translations, $contentId, $locale);

        return $rows->map(function (array $row) use ($force): array {
            if (($row['reason'] ?? '') === '') {
                return $row + ['recovered' => false];
            }

            if (! $force) {
                return $row + ['recovered' => false];
            }

            $translation = $this->markTranslationFailed(
                $row['translation'],
                $this->staleMessage(
                    $row['translation'],
                    (string) $row['reason'],
                    (int) (($row['pending_jobs']->first()['attempts'] ?? $row['failed_jobs']->first()['attempts'] ?? 0)),
                    $row['failed_jobs']
                ),
                stale: true,
            );

            $this->debug->logRecovery(
                'Stale translation lock recovered.',
                $this->debug->buildContext($translation, [
                    'reason' => $row['reason'] ?? null,
                    'queue_state' => $row['queue_state'] ?? null,
                    'heartbeat_age_seconds' => $row['heartbeat_age_seconds'] ?? null,
                    'pending_jobs_count' => collect($row['pending_jobs'] ?? [])->count(),
                    'failed_jobs_count' => collect($row['failed_jobs'] ?? [])->count(),
                ])
            );

            return $row + [
                'translation' => $translation,
                'recovered' => true,
            ];
        })->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>|null  $pendingJobs
     */
    public function translationIsActuallyRunning(ContentTranslation $translation, ?Collection $pendingJobs = null): bool
    {
        if (! in_array((string) $translation->status, [
            ContentTranslation::STATUS_QUEUED,
            ContentTranslation::STATUS_PROCESSING,
        ], true)) {
            return false;
        }

        if (($pendingJobs ?? collect())->isNotEmpty()) {
            return true;
        }

        if ($this->translationJobExists(
            $translation->processing_job_uuid,
            (string) $translation->id
        )) {
            return true;
        }

        $reference = $this->lockReferenceAt($translation);

        if ((string) $translation->status !== ContentTranslation::STATUS_PROCESSING) {
            return false;
        }

        return $reference !== null
            && $reference->gte(now()->subSeconds($this->staleTimeoutSeconds()));
    }

    public function translationJobExists(?string $jobUuid, ?string $translationRequestId = null): bool
    {
        $jobUuid = trim((string) $jobUuid);
        $translationRequestId = trim((string) $translationRequestId);

        if ($jobUuid !== '') {
            if ($this->databaseQueueJobExistsByUuid($jobUuid) || $this->redisQueueJobExistsByUuid($jobUuid)) {
                return true;
            }
        }

        if ($translationRequestId === '') {
            return false;
        }

        return collect($this->pendingJobsByTranslationRequestId([$translationRequestId])[$translationRequestId] ?? [])->isNotEmpty()
            || $this->redisQueueJobExistsByTranslationRequestId($translationRequestId);
    }

    public function touchHeartbeat(ContentTranslation $translation, ?string $jobUuid = null, ?string $jobId = null): ContentTranslation
    {
        $translation->forceFill([
            'job_id' => $jobId ?? $translation->job_id,
            'processing_job_uuid' => $jobUuid ?? $translation->processing_job_uuid,
            'processing_last_heartbeat_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $translation->fresh() ?? $translation;
    }

    public function queueJobBelongsToTranslation(ContentTranslation $translation, ?string $jobId): bool
    {
        $jobId = trim((string) $jobId);

        return $jobId !== ''
            && trim((string) $translation->job_id) === $jobId;
    }

    public function lockReferenceAt(ContentTranslation $translation): ?CarbonInterface
    {
        foreach ([
            $translation->processing_last_heartbeat_at,
            $translation->processing_locked_at,
            $translation->processing_started_at,
            $translation->updated_at,
            $translation->created_at,
        ] as $candidate) {
            if ($candidate instanceof CarbonInterface) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function pendingJobsByTranslationRequestId(array $translationIds = []): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $rows = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->where('payload', 'like', '%' . addcslashes(TranslateDraftJob::class, '\\') . '%')
            ->orderBy('id')
            ->get();

        return $this->indexJobsByTranslationRequestId($rows, $translationIds, false);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function queuedTranslateJobs(?string $sourceContentId = null, ?string $locale = null): Collection
    {
        if (! Schema::hasTable('jobs')) {
            return collect();
        }

        return DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->where('payload', 'like', '%' . addcslashes(TranslateDraftJob::class, '\\') . '%')
            ->orderByDesc('id')
            ->get()
            ->map(function (object $row): ?array {
                $metadata = $this->extractTranslateDraftJobMetadata((string) $row->payload);

                if ($metadata === []) {
                    return null;
                }

                return [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'attempts' => (int) $row->attempts,
                    'reserved_at' => $row->reserved_at,
                    'available_at' => $row->available_at,
                    'created_at' => $row->created_at,
                    'metadata' => $metadata,
                    'source_content_id' => (string) ($metadata['source_content_id'] ?? ''),
                    'target_locale' => (string) ($metadata['target_language'] ?? ''),
                    'translation_request_id' => (string) ($metadata['translation_request_id'] ?? ''),
                    'dispatch_job_uuid' => (string) ($metadata['dispatch_job_uuid'] ?? ''),
                ];
            })
            ->filter()
            ->when($sourceContentId !== null && trim($sourceContentId) !== '', fn (Collection $rows) => $rows->where('source_content_id', trim($sourceContentId)))
            ->when($locale !== null && trim($locale) !== '', fn (Collection $rows) => $rows->where('target_locale', trim($locale)))
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function findQueuedDuplicateJobs(?string $sourceContentId = null, ?string $locale = null): Collection
    {
        return $this->queuedTranslateJobs($sourceContentId, $locale)
            ->groupBy(fn (array $row): string => $row['source_content_id'] . ':' . $row['target_locale'])
            ->filter(fn (Collection $rows): bool => $rows->count() > 1)
            ->map(function (Collection $rows, string $fingerprint): array {
                $sorted = $rows->sortByDesc(fn (array $row): int => (int) $row['id'])->values();

                return [
                    'fingerprint' => $fingerprint,
                    'source_content_id' => (string) ($sorted->first()['source_content_id'] ?? ''),
                    'target_locale' => (string) ($sorted->first()['target_locale'] ?? ''),
                    'keep' => $sorted->first(),
                    'delete' => $sorted->slice(1)->values(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function clearQueuedDuplicateJobs(?string $sourceContentId = null, ?string $locale = null, bool $force = false): Collection
    {
        $duplicates = $this->findQueuedDuplicateJobs($sourceContentId, $locale);

        if (! $force || $duplicates->isEmpty() || ! Schema::hasTable('jobs')) {
            return $duplicates;
        }

        foreach ($duplicates as $row) {
            $deleteIds = collect($row['delete'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();

            if ($deleteIds !== []) {
                DB::table('jobs')->whereIn('id', $deleteIds)->delete();
            }
        }

        return $duplicates;
    }

    public function clearQueuedJobsForFingerprint(string $sourceContentId, string $locale, ?string $keepDispatchJobUuid = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $rows = $this->queuedTranslateJobs($sourceContentId, $locale);
        $keepDispatchJobUuid = trim((string) $keepDispatchJobUuid);

        $deleteIds = $rows
            ->reject(fn (array $row): bool => $keepDispatchJobUuid !== '' && (string) $row['dispatch_job_uuid'] === $keepDispatchJobUuid)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->all();

        if ($deleteIds === []) {
            return 0;
        }

        return DB::table('jobs')->whereIn('id', $deleteIds)->delete();
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function failedJobsByTranslationRequestId(array $translationIds = []): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $rows = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->where('payload', 'like', '%' . addcslashes(TranslateDraftJob::class, '\\') . '%')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->get();

        return $this->indexJobsByTranslationRequestId($rows, $translationIds, true);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $linkedFailedJobs
     */
    public function staleMessage(
        ContentTranslation $translation,
        string $reason,
        int $attempts,
        Collection $linkedFailedJobs
    ): string {
        $existingMessage = $translation->displayErrorMessage() ?: 'Translation lock is stale. Retry translation.';
        $baseMessage = $this->normalizeFailureMessage($existingMessage);
        $ageMinutes = $this->lockReferenceAt($translation)
            ? max(1, (int) ceil($this->lockReferenceAt($translation)->diffInSeconds(now()) / 60))
            : 0;

        return match ($reason) {
            'failed_already_processing' => sprintf(
                '%s %s',
                ContentTranslation::STALE_LOCK_MARKER,
                $baseMessage !== '' ? $baseMessage : 'Previous failure still reported "already processing" without a live queue job.'
            ),
            'linked_failed_job', 'failed_with_linked_failed_job' => sprintf(
                '%s %s',
                ContentTranslation::STALE_LOCK_MARKER,
                $baseMessage !== '' ? $baseMessage : sprintf('%d linked failed queue job(s) were found.', $linkedFailedJobs->count())
            ),
            'missing_active_job' => sprintf(
                '%s %s',
                ContentTranslation::STALE_LOCK_MARKER,
                $baseMessage !== '' ? $baseMessage : 'No active queue job exists for this translation request.'
            ),
            default => sprintf(
                '%s %s',
                ContentTranslation::STALE_LOCK_MARKER,
                $baseMessage !== '' ? $baseMessage : sprintf('Lock recovered after %d minute(s) without a valid heartbeat.', $ageMinutes)
            ),
        };
    }

    private function normalizeFailureMessage(string $message): string
    {
        $normalized = trim(str_replace(ContentTranslation::STALE_LOCK_MARKER, '', $message));
        $normalized = preg_replace('/Lock recovered because.*$/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/Lock recovered after.*$/i', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function staleReason(
        ContentTranslation $translation,
        bool $running,
        Collection $pendingJobs,
        Collection $failedJobs
    ): ?string {
        if ($translation->status === ContentTranslation::STATUS_COMPLETED) {
            return null;
        }

        if ($translation->status === ContentTranslation::STATUS_FAILED) {
            if ($translation->hasAlreadyProcessingError() && ! $running) {
                return 'failed_already_processing';
            }

            if ($translation->isStaleFailure()) {
                return 'failed_stale_lock';
            }

            if ($failedJobs->isNotEmpty()) {
                return 'failed_with_linked_failed_job';
            }

            return null;
        }

        if ($running) {
            return null;
        }

        if ($pendingJobs->isEmpty() && $this->isOlderThanStaleTimeout($translation)) {
            return 'missing_active_job';
        }

        if ($failedJobs->isNotEmpty()) {
            return 'linked_failed_job';
        }

        return null;
    }

    private function queueState(
        ContentTranslation $translation,
        bool $running,
        Collection $pendingJobs,
        Collection $failedJobs,
        ?string $reason
    ): string {
        if ($reason !== null && $reason !== '') {
            return $translation->status === ContentTranslation::STATUS_FAILED ? 'stale_recovered' : 'stale';
        }

        if ($translation->status === ContentTranslation::STATUS_FAILED) {
            return 'failed';
        }

        if ($translation->status === ContentTranslation::STATUS_COMPLETED) {
            return 'completed';
        }

        if ($translation->status === ContentTranslation::STATUS_QUEUED || $pendingJobs->isNotEmpty()) {
            return 'queued';
        }

        if ($translation->status === ContentTranslation::STATUS_PROCESSING || $running) {
            return 'processing';
        }

        if ($failedJobs->isNotEmpty()) {
            return 'failed';
        }

        return 'ready';
    }

    private function isOlderThanStaleTimeout(ContentTranslation $translation): bool
    {
        $reference = $this->lockReferenceAt($translation);

        return $reference !== null
            && $reference->lte(now()->subSeconds($this->staleTimeoutSeconds()));
    }

    private function databaseQueueJobExistsByUuid(string $jobUuid): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')->where('payload', 'like', '%"uuid":"' . addcslashes($jobUuid, '\\') . '"%')->exists();
    }

    private function redisQueueJobExistsByUuid(string $jobUuid): bool
    {
        $connection = (string) config('translation.queue.connection', config('queue.default'));

        if ($connection !== 'redis') {
            return false;
        }

        try {
            foreach ($this->redisQueueKeys() as $key) {
                $entries = Redis::connection($connection)->lrange($key, 0, 1000);

                foreach ($entries as $entry) {
                    if (is_string($entry) && str_contains($entry, '"uuid":"' . $jobUuid . '"')) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Throwable $exception) {
            Log::warning('translation.lock.redis_queue_lookup_failed', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private function redisQueueJobExistsByTranslationRequestId(string $translationRequestId): bool
    {
        $connection = (string) config('translation.queue.connection', config('queue.default'));

        if ($connection !== 'redis') {
            return false;
        }

        try {
            foreach ($this->redisQueueKeys() as $key) {
                $entries = Redis::connection($connection)->lrange($key, 0, 1000);

                foreach ($entries as $entry) {
                    if (is_string($entry) && str_contains($entry, $translationRequestId)) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Throwable $exception) {
            Log::warning('translation.lock.redis_queue_lookup_failed', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<int,string>
     */
    private function redisQueueKeys(): array
    {
        $queue = (string) config('translation.queue.name', 'default');

        return [
            'queues:' . $queue,
            'queues:' . $queue . ':delayed',
            'queues:' . $queue . ':reserved',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,object>  $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function indexJobsByTranslationRequestId(Collection $rows, array $translationIds, bool $failed): array
    {
        $requestedIds = collect($translationIds)
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->flip();

        $index = [];

        foreach ($rows as $row) {
            $metadata = $this->extractTranslateDraftJobMetadata((string) $row->payload);
            $translationRequestId = trim((string) ($metadata['translation_request_id'] ?? ''));

            if ($translationRequestId === '') {
                continue;
            }

            if ($requestedIds->isNotEmpty() && ! $requestedIds->has($translationRequestId)) {
                continue;
            }

            $index[$translationRequestId] ??= [];
            $index[$translationRequestId][] = [
                'id' => (string) $row->id,
                'uuid' => (string) ($row->uuid ?? ($metadata['uuid'] ?? '')),
                'queue' => (string) ($row->queue ?? ''),
                'failed_at' => $row->failed_at ?? null,
                'attempts' => (int) ($metadata['attempts'] ?? 0),
                'translation_request_id' => $translationRequestId,
                'target_language' => $metadata['target_language'] ?? null,
                'source_draft_id' => $metadata['source_draft_id'] ?? null,
                'error_summary' => $failed
                    ? (trim((string) str($row->exception)->before("\n")) ?: 'Unknown error')
                    : null,
                'reserved_at' => $row->reserved_at ?? null,
                'available_at' => $row->available_at ?? null,
                'created_at' => $row->created_at ?? null,
            ];
        }

        return $index;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractTranslateDraftJobMetadata(string $payloadJson): array
    {
        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            return [];
        }

        $commandName = trim((string) data_get($payload, 'data.commandName', data_get($payload, 'displayName', '')));

        if ($commandName !== '' && $commandName !== TranslateDraftJob::class) {
            return [];
        }

        $metadata = [
            'uuid' => data_get($payload, 'uuid'),
            'translation_request_id' => data_get($payload, 'translation_request_id'),
            'target_language' => data_get($payload, 'target_language'),
            'source_draft_id' => data_get($payload, 'source_draft_id'),
            'source_content_id' => data_get($payload, 'source_content_id'),
            'dispatch_job_uuid' => data_get($payload, 'dispatch_job_uuid'),
            'attempts' => data_get($payload, 'attempts', data_get($payload, 'data.attempts', 0)),
        ];

        $command = data_get($payload, 'data.command');

        if (! is_string($command) || $command === '') {
            return $metadata;
        }

        try {
            $job = unserialize($command, ['allowed_classes' => [TranslateDraftJob::class]]);
        } catch (Throwable) {
            return $metadata;
        }

        if (! $job instanceof TranslateDraftJob) {
            return $metadata;
        }

        return [
            'uuid' => $metadata['uuid'],
            'translation_request_id' => $job->translationRequestId,
            'target_language' => $job->targetLanguage,
            'source_draft_id' => $job->sourceDraftId,
            'source_content_id' => $job->sourceContentId,
            'dispatch_job_uuid' => $job->dispatchJobUuid,
            'attempts' => (int) ($metadata['attempts'] ?? 0),
        ];
    }
}
