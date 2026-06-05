<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentTranslation extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const STALE_LOCK_MARKER = '[stale_translation_lock]';

    public const FAILURE_REASON_INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const FAILURE_REASON_PROVIDER_ERROR = 'provider_error';
    public const FAILURE_REASON_VALIDATION_ERROR = 'validation_error';
    public const FAILURE_REASON_LOCK_CONFLICT = 'lock_conflict';
    public const FAILURE_REASON_TIMEOUT = 'timeout';
    public const FAILURE_REASON_UNKNOWN = 'unknown';

    protected $fillable = [
        'content_id',
        'target_locale',
        'target_content_id',
        'status',
        'failure_reason',
        'required_credits',
        'available_credits',
        'entitlement_source',
        'requested_by_user_id',
        'translation_trace_id',
        'job_id',
        'processing_started_at',
        'processing_job_uuid',
        'processing_locked_at',
        'processing_last_heartbeat_at',
        'processing_failed_at',
        'processing_last_recovered_at',
        'processing_error_message',
        'processing_recovery_count',
        'error_message',
    ];

    protected $casts = [
        'processing_started_at' => 'datetime',
        'processing_locked_at' => 'datetime',
        'processing_last_heartbeat_at' => 'datetime',
        'processing_failed_at' => 'datetime',
        'processing_last_recovered_at' => 'datetime',
        'processing_recovery_count' => 'integer',
        'required_credits' => 'integer',
        'available_credits' => 'integer',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function targetContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'target_content_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public static function processingLockTtlSeconds(): int
    {
        return max(
            60,
            (int) config('translation.processing_lock_ttl_seconds', config('publishlayer.translations.stale_lock_timeout_minutes', 10) * 60)
        );
    }

    public function isQueuedOrProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ], true);
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function lockReferenceAt(): ?CarbonInterface
    {
        foreach ([
            $this->processing_last_heartbeat_at,
            $this->processing_locked_at,
            $this->processing_started_at,
            $this->updated_at,
            $this->created_at,
        ] as $reference) {
            if ($reference instanceof CarbonInterface) {
                return $reference;
            }
        }

        return null;
    }

    public function normalizedJobId(): ?string
    {
        $jobId = trim((string) $this->job_id);

        if ($jobId === '' || in_array(strtolower($jobId), ['n/a', 'na', 'null', 'none'], true)) {
            return null;
        }

        return $jobId;
    }

    public function hasJobReference(): bool
    {
        return $this->normalizedJobId() !== null;
    }

    public function hasAlreadyProcessingError(): bool
    {
        return str_contains(strtolower((string) $this->error_message), 'already processing');
    }

    public function isInsufficientCreditsFailure(): bool
    {
        return $this->failure_reason === self::FAILURE_REASON_INSUFFICIENT_CREDITS;
    }

    public function isOlderThanProcessingLockTtl(?CarbonInterface $now = null): bool
    {
        $reference = $this->lockReferenceAt();

        if (! $reference) {
            return false;
        }

        return $reference->lte(($now ?? now())->copy()->subSeconds(self::processingLockTtlSeconds()));
    }

    public function isExpiredProcessingLock(?CarbonInterface $now = null): bool
    {
        if (! $this->isQueuedOrProcessing()) {
            return false;
        }

        return $this->isOlderThanProcessingLockTtl($now);
    }

    public function hasRecoverableFailedLock(?CarbonInterface $now = null): bool
    {
        if (! $this->isFailed()) {
            return false;
        }

        if ($this->isStaleFailure()) {
            return true;
        }

        if ($this->isInsufficientCreditsFailure()) {
            return false;
        }

        if ($this->hasJobReference()) {
            return false;
        }

        if ($this->hasAlreadyProcessingError()) {
            return true;
        }

        return $this->isOlderThanProcessingLockTtl($now);
    }

    public function markAsStaleFailure(string $message): bool
    {
        $finalMessage = str_contains($message, self::STALE_LOCK_MARKER)
            ? $message
            : trim(self::STALE_LOCK_MARKER . ' ' . $message);

        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => self::FAILURE_REASON_UNKNOWN,
            'job_id' => null,
            'processing_job_uuid' => null,
            'processing_locked_at' => null,
            'processing_last_heartbeat_at' => null,
            'processing_failed_at' => now(),
            'processing_last_recovered_at' => now(),
            'processing_error_message' => $finalMessage,
            'processing_recovery_count' => (int) ($this->processing_recovery_count ?? 0) + 1,
            'error_message' => $finalMessage,
        ])->save();

        return true;
    }

    public function reconcileExpiredProcessingLock(?CarbonInterface $now = null): bool
    {
        if (! $this->isExpiredProcessingLock($now)) {
            return false;
        }

        $reference = $this->updated_at ?? $this->created_at;
        $ageMinutes = max(1, (int) ceil($reference->diffInSeconds($now ?? now()) / 60));

        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => self::FAILURE_REASON_UNKNOWN,
            'job_id' => null,
            'processing_job_uuid' => null,
            'processing_locked_at' => null,
            'processing_last_heartbeat_at' => null,
            'processing_failed_at' => now(),
            'processing_last_recovered_at' => now(),
            'processing_error_message' => sprintf(
                '%s Translation processing lock became stale after %d minute(s). Retry translation.',
                self::STALE_LOCK_MARKER,
                $ageMinutes
            ),
            'processing_recovery_count' => (int) ($this->processing_recovery_count ?? 0) + 1,
            'error_message' => sprintf(
                '%s Translation processing lock became stale after %d minute(s). Retry translation.',
                self::STALE_LOCK_MARKER,
                $ageMinutes
            ),
        ])->save();

        return true;
    }

    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_FAILED && $this->isInsufficientCreditsFailure()) {
            return self::STATUS_INSUFFICIENT_CREDITS;
        }

        if ($this->status === self::STATUS_FAILED && $this->isStaleFailure()) {
            return 'stale';
        }

        return $this->status;
    }

    public function isStaleFailure(): bool
    {
        return $this->status === self::STATUS_FAILED
            && str_contains((string) $this->error_message, self::STALE_LOCK_MARKER);
    }

    public function isActiveLock(): bool
    {
        return $this->isQueuedOrProcessing() && ! $this->isExpiredProcessingLock();
    }

    public function reconcileRecoverableLockState(?CarbonInterface $now = null): bool
    {
        if ($this->reconcileExpiredProcessingLock($now)) {
            return true;
        }

        if (! $this->hasRecoverableFailedLock($now)) {
            return false;
        }

        $message = $this->displayErrorMessage() ?: 'Translation lock is stale and can be retried.';

        return $this->markAsStaleFailure($message);
    }

    public function displayErrorMessage(): ?string
    {
        $message = trim((string) ($this->processing_error_message ?: $this->error_message));

        if ($message === '') {
            return null;
        }

        if ($this->isStaleFailure()) {
            $message = trim(str_replace(self::STALE_LOCK_MARKER, '', $message));
        }

        return $message !== '' ? $message : null;
    }
}
