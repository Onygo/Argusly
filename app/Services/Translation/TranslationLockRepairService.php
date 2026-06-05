<?php

namespace App\Services\Translation;

use App\Models\ContentTranslation;
use App\Services\Content\TranslationLockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TranslationLockRepairService
{
    public function __construct(
        private readonly TranslationLockService $locks,
    ) {}

    /**
     * @return Collection<int,array{
     *     translation:ContentTranslation,
     *     reason:string,
     *     pending_jobs:Collection<int,array<string,mixed>>,
     *     linked_failed_jobs:Collection<int,array<string,mixed>>,
     *     attempts:int,
     *     lock_state:string
     * }>
     */
    public function findStaleTranslations(?int $limit = 250, bool $includeFailed = true): Collection
    {
        return $this->inspectTranslations(
            ContentTranslation::query()
                ->whereIn('status', $includeFailed
                    ? [ContentTranslation::STATUS_QUEUED, ContentTranslation::STATUS_PROCESSING, ContentTranslation::STATUS_FAILED]
                    : [ContentTranslation::STATUS_QUEUED, ContentTranslation::STATUS_PROCESSING]
                )
                ->orderBy('updated_at')
                ->limit(max(1, (int) ($limit ?? 250)))
                ->get()
        )->filter(fn (array $row): bool => $row['reason'] !== '')->values();
    }

    /**
     * @param  Collection<int,ContentTranslation>  $translations
     * @return Collection<int,array{
     *     translation:ContentTranslation,
     *     reason:string,
     *     pending_jobs:Collection<int,array<string,mixed>>,
     *     linked_failed_jobs:Collection<int,array<string,mixed>>,
     *     attempts:int,
     *     lock_state:string
     * }>
     */
    public function inspectTranslations(Collection $translations): Collection
    {
        return $this->locks->detectStaleLocks($translations)->map(function (array $row): array {
            $pendingJobs = $row['pending_jobs'];
            $failedJobs = $row['failed_jobs'];

            return [
                'translation' => $row['translation'],
                'reason' => (string) ($row['reason'] ?? ''),
                'pending_jobs' => $pendingJobs,
                'linked_failed_jobs' => $failedJobs,
                'attempts' => max(
                    (int) ($pendingJobs->first()['attempts'] ?? 0),
                    (int) ($failedJobs->first()['attempts'] ?? 0)
                ),
                'lock_state' => (string) ($row['queue_state'] ?? ''),
            ];
        })->values();
    }

    /**
     * @return array{found_count:int,fixed_count:int,retried_count:int,rows:Collection<int,array<string,mixed>>}
     */
    public function repair(?int $limit = 250, bool $apply = false, bool $includeFailed = true): array
    {
        $rows = $this->findStaleTranslations($limit, $includeFailed);

        if (! $apply) {
            return [
                'found_count' => $rows->count(),
                'fixed_count' => 0,
                'retried_count' => 0,
                'rows' => $rows,
            ];
        }

        $fixed = 0;

        foreach ($rows as $row) {
            $fixed += $this->releaseLock(
                translation: $row['translation'],
                message: $this->staleMessage(
                    $row['translation'],
                    (string) $row['reason'],
                    (int) $row['attempts'],
                    $row['linked_failed_jobs']
                ),
                keepFailedStatus: true,
            ) ? 1 : 0;
        }

        return [
            'found_count' => $rows->count(),
            'fixed_count' => $fixed,
            'retried_count' => 0,
            'rows' => $rows,
        ];
    }

    public function reconcileIfStale(ContentTranslation $translation): bool
    {
        $row = $this->inspectTranslations(collect([$translation->fresh() ?? $translation]))->first();

        if (! is_array($row) || ($row['reason'] ?? '') === '') {
            return false;
        }

        return $this->releaseLock(
            translation: $translation,
            message: $this->staleMessage(
                $translation,
                (string) $row['reason'],
                (int) ($row['attempts'] ?? 0),
                $row['linked_failed_jobs']
            ),
            keepFailedStatus: true,
        );
    }

    public function releaseLock(
        ContentTranslation $translation,
        ?string $message = null,
        bool $keepFailedStatus = true
    ): bool {
        $translation = $translation->fresh() ?? $translation;

        if ($translation->status === ContentTranslation::STATUS_COMPLETED) {
            return false;
        }

        $finalMessage = $message ?: 'Translation lock released by admin.';

        if ($keepFailedStatus) {
            $this->locks->markTranslationFailed(
                $translation,
                $finalMessage,
                stale: str_contains($finalMessage, ContentTranslation::STALE_LOCK_MARKER)
            );
        } else {
            $this->locks->releaseTranslationLock(
                $translation,
                ContentTranslation::STATUS_QUEUED,
                null,
                $finalMessage,
            );
        }

        Log::warning('translation.lock.released', [
            'translation_id' => (string) $translation->id,
            'content_id' => (string) $translation->content_id,
            'target_locale' => (string) $translation->target_locale,
            'status' => (string) $translation->status,
            'message' => $finalMessage,
        ]);

        return true;
    }

    public function resetForRetry(ContentTranslation $translation, ?string $message = null): bool
    {
        $translation = $translation->fresh() ?? $translation;

        if ($translation->status === ContentTranslation::STATUS_COMPLETED) {
            return false;
        }

        $finalMessage = $message !== null
            ? (str_contains($message, ContentTranslation::STALE_LOCK_MARKER)
                ? $message
                : trim(ContentTranslation::STALE_LOCK_MARKER . ' ' . $message))
            : $translation->error_message;

        $this->locks->markTranslationFailed($translation, $finalMessage, stale: true);

        return true;
    }

    public function hasPendingTranslationJob(string $translationRequestId): bool
    {
        return $this->locks->translationJobExists(null, $translationRequestId);
    }

    public function pendingJobsByTranslationRequestId(array $translationIds = []): array
    {
        return $this->locks->pendingJobsByTranslationRequestId($translationIds);
    }

    public function failedJobsByTranslationRequestId(array $translationIds = []): array
    {
        return $this->locks->failedJobsByTranslationRequestId($translationIds);
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
        return $this->locks->staleMessage($translation, $reason, $attempts, $linkedFailedJobs);
    }
}
