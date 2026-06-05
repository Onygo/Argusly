<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftComparisonVariant extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'draft_comparison_id',
        'provider_key',
        'model_key',
        'display_name',
        'sort_order',
        'status',
        'generation_job_uuid',
        'draft_id',
        'prompt_snapshot_json',
        'input_tokens',
        'output_tokens',
        'credit_cost',
        'latency_ms',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'prompt_snapshot_json' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'credit_cost' => 'integer',
        'latency_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function draftComparison(): BelongsTo
    {
        return $this->belongsTo(DraftComparison::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(DraftComparisonScore::class)->orderBy('created_at');
    }

    public function markPending(bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(self::STATUS_PENDING, persist: $persist, recalculateParent: $recalculateParent);
    }

    public function markQueued(bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(self::STATUS_QUEUED, persist: $persist, recalculateParent: $recalculateParent);
    }

    public function markProcessing(bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(self::STATUS_PROCESSING, persist: $persist, recalculateParent: $recalculateParent);
    }

    public function markCompleted(bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(self::STATUS_COMPLETED, persist: $persist, recalculateParent: $recalculateParent);
    }

    public function markFailed(?string $errorMessage = null, bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(
            self::STATUS_FAILED,
            errorMessage: $errorMessage,
            persist: $persist,
            recalculateParent: $recalculateParent,
        );
    }

    public function markCancelled(bool $persist = true, bool $recalculateParent = true): static
    {
        return $this->transitionTo(self::STATUS_CANCELLED, persist: $persist, recalculateParent: $recalculateParent);
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    private function transitionTo(
        string $status,
        ?string $errorMessage = null,
        bool $persist = true,
        bool $recalculateParent = true,
    ): static {
        $this->applyStatusTimestamps($status, $errorMessage);

        if ($persist) {
            $this->save();

            if ($recalculateParent) {
                $this->draftComparison()->first()?->recalculateAggregateStatus();
            }
        }

        return $this;
    }

    private function applyStatusTimestamps(string $status, ?string $errorMessage = null): void
    {
        $this->status = $status;

        if ($status === self::STATUS_PROCESSING) {
            $this->started_at = $this->started_at ?: now();
            $this->completed_at = null;
            $this->error_message = null;

            return;
        }

        if ($status === self::STATUS_COMPLETED) {
            $this->started_at = $this->started_at ?: now();
            $this->completed_at = $this->completed_at ?: now();
            $this->error_message = null;

            return;
        }

        if ($status === self::STATUS_FAILED) {
            $this->started_at = $this->started_at ?: now();
            $this->completed_at = $this->completed_at ?: now();
            if ($errorMessage !== null && trim($errorMessage) !== '') {
                $this->error_message = mb_substr($errorMessage, 0, 5000);
            }

            return;
        }

        if ($status === self::STATUS_CANCELLED) {
            $this->completed_at = $this->completed_at ?: now();
            $this->error_message = null;

            return;
        }

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_QUEUED], true)) {
            $this->completed_at = null;
            $this->error_message = null;
        }
    }
}
