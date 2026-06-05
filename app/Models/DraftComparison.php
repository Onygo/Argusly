<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaClientSite;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftComparison extends Model
{
    use BelongsToOrganizationViaClientSite;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

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
        self::STATUS_PARTIALLY_FAILED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PARTIALLY_FAILED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'workspace_id',
        'brief_id',
        'content_id',
        'client_site_id',
        'created_by_user_id',
        'mode',
        'source_language',
        'target_language',
        'brand_voice_id',
        'title',
        'status',
        'requested_models_json',
        'requested_model_count',
        'estimated_input_tokens',
        'estimated_output_tokens',
        'estimated_credit_cost',
        'reserved_credit_amount',
        'final_credit_cost',
        'comparison_summary_json',
        'requested_max_output_tokens',
        'estimated_credits',
        'credits_used',
        'items_total',
        'items_done',
        'items_failed',
        'winner_draft_id',
        'hybrid_draft_id',
        'hybrid_status',
        'hybrid_last_error',
        'started_at',
        'completed_at',
        'failed_at',
        'hybrid_started_at',
        'hybrid_completed_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'requested_models_json' => 'array',
        'comparison_summary_json' => 'array',
        'requested_model_count' => 'integer',
        'estimated_input_tokens' => 'integer',
        'estimated_output_tokens' => 'integer',
        'estimated_credit_cost' => 'integer',
        'reserved_credit_amount' => 'integer',
        'final_credit_cost' => 'integer',
        'requested_max_output_tokens' => 'integer',
        'estimated_credits' => 'integer',
        'credits_used' => 'integer',
        'items_total' => 'integer',
        'items_done' => 'integer',
        'items_failed' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'hybrid_started_at' => 'datetime',
        'hybrid_completed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function winnerDraft(): BelongsTo
    {
        return $this->belongsTo(Draft::class, 'winner_draft_id');
    }

    public function hybridDraft(): BelongsTo
    {
        return $this->belongsTo(Draft::class, 'hybrid_draft_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DraftComparisonItem::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(DraftComparisonVariant::class)->orderBy('sort_order');
    }

    public function markPending(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_PENDING, $persist);
    }

    public function markQueued(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_QUEUED, $persist);
    }

    public function markProcessing(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_PROCESSING, $persist);
    }

    public function markCompleted(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_COMPLETED, $persist);
    }

    public function markFailed(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_FAILED, $persist);
    }

    public function markCancelled(bool $persist = true): static
    {
        return $this->transitionTo(self::STATUS_CANCELLED, $persist);
    }

    public function recalculateAggregateStatus(bool $persist = true): string
    {
        if ((string) $this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        $counts = $this->aggregateVariantStatusCounts();
        $target = $this->resolveAggregateStatus($counts);

        if (! $persist) {
            return $target;
        }

        $this->transitionTo($target, true);

        return $target;
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    private function transitionTo(string $status, bool $persist): static
    {
        $this->applyStatusTimestamps($status);

        if ($persist) {
            $this->save();
        }

        return $this;
    }

    private function applyStatusTimestamps(string $status): void
    {
        $this->status = $status;

        if ($status === self::STATUS_PROCESSING) {
            $this->started_at = $this->started_at ?: now();
            $this->completed_at = null;
            $this->failed_at = null;

            return;
        }

        if ($status === self::STATUS_COMPLETED) {
            $this->started_at = $this->started_at ?: now();
            $this->completed_at = $this->completed_at ?: now();
            $this->failed_at = null;

            return;
        }

        if ($status === self::STATUS_FAILED) {
            $this->started_at = $this->started_at ?: now();
            $this->failed_at = $this->failed_at ?: now();
            $this->completed_at = null;

            return;
        }

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_PARTIALLY_FAILED, self::STATUS_CANCELLED], true)) {
            $this->completed_at = null;
            $this->failed_at = null;
        }
    }

    /**
     * @return array{total:int,pending:int,queued:int,processing:int,completed:int,failed:int,cancelled:int}
     */
    private function aggregateVariantStatusCounts(): array
    {
        $statuses = $this->variants()->pluck('status')->all();

        // Transitional fallback for legacy compare items still in-flight.
        if ($statuses === []) {
            $statuses = collect($this->items()->pluck('status')->all())
                ->map(fn (string $status): ?string => $this->normalizeLegacyItemStatus($status))
                ->filter()
                ->values()
                ->all();
        }

        $counts = [
            'total' => count($statuses),
            'pending' => 0,
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($statuses as $status) {
            $normalized = trim((string) $status);
            if (array_key_exists($normalized, $counts)) {
                $counts[$normalized]++;
            }
        }

        return $counts;
    }

    /**
     * @param array{total:int,pending:int,queued:int,processing:int,completed:int,failed:int,cancelled:int} $counts
     */
    private function resolveAggregateStatus(array $counts): string
    {
        $total = $counts['total'];
        if ($total === 0) {
            return self::STATUS_PENDING;
        }

        $failedLike = $counts['failed'] + $counts['cancelled'];

        if ($counts['pending'] === $total) {
            return self::STATUS_PENDING;
        }

        if ($counts['processing'] > 0) {
            return self::STATUS_PROCESSING;
        }

        if ($counts['completed'] === $total) {
            return self::STATUS_COMPLETED;
        }

        if ($failedLike === $total) {
            return self::STATUS_FAILED;
        }

        if ($counts['completed'] > 0 && $failedLike > 0) {
            return self::STATUS_PARTIALLY_FAILED;
        }

        if ($counts['queued'] > 0 || $counts['pending'] > 0) {
            return self::STATUS_QUEUED;
        }

        return self::STATUS_PENDING;
    }

    private function normalizeLegacyItemStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'pending' => self::STATUS_PENDING,
            'queued' => self::STATUS_QUEUED,
            'processing', 'generating' => self::STATUS_PROCESSING,
            'completed', 'generated' => self::STATUS_COMPLETED,
            'failed' => self::STATUS_FAILED,
            'cancelled', 'canceled' => self::STATUS_CANCELLED,
            default => null,
        };
    }
}
