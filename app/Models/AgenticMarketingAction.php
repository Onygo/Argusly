<?php

namespace App\Models;

use App\Enums\AgenticMarketingActionStatus;
use App\Enums\AgenticMarketingActionType;
use App\Support\AgenticMarketing\AgenticMarketingDedupe;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class AgenticMarketingAction extends Model
{
    use HasUuids;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'objective_id',
        'opportunity_id',
        'run_id',
        'content_id',
        'draft_id',
        'action_type',
        'status',
        'payload',
        'payload_hash',
        'dedupe_hash',
        'open_dedupe_hash',
        'result',
        'error_message',
        'estimated_credits',
        'credit_reservation_id',
        'credits_reserved',
        'credits_captured',
        'credit_status',
        'credit_error_message',
        'budget_checked_at',
        'budget_exceeded_at',
        'execution_claim_id',
        'approved_at',
        'dismissed_at',
        'execution_claimed_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'estimated_credits' => 'integer',
        'credits_reserved' => 'integer',
        'credits_captured' => 'integer',
        'budget_checked_at' => 'datetime',
        'budget_exceeded_at' => 'datetime',
        'approved_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'execution_claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (AgenticMarketingAction $action): void {
            $action->action_type = self::normalizeType($action->action_type);
            $action->status = self::normalizeStatus($action->status);

            $payloadHash = AgenticMarketingDedupe::payloadHash($action->payload ?? []);
            $action->payload_hash = $payloadHash;
            $action->dedupe_hash = AgenticMarketingDedupe::actionHash($action->action_type, $payloadHash);
            $action->open_dedupe_hash = self::isOpenStatus($action->status)
                ? $action->dedupe_hash
                : null;
        });

        static::created(function (AgenticMarketingAction $action): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.created', null, $action->attributesToArray());
            app(\App\Services\AgenticMarketing\AgenticActionRunLogger::class)
                ->recordActionSnapshot($action->loadMissing(['objective', 'opportunity']));
        });

        static::updated(function (AgenticMarketingAction $action): void {
            app(\App\Services\AgenticMarketing\AgenticMarketingAuditLogger::class)
                ->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.updated', $action->getOriginal(), $action->getChanges());
            if ($action->wasChanged(['status', 'result', 'error_message', 'credits_captured', 'content_id'])) {
                app(\App\Services\AgenticMarketing\AgenticActionRunLogger::class)
                    ->recordActionSnapshot($action->loadMissing(['objective', 'opportunity']));
            }
        });
    }

    public static function createOrReuseOpen(array $attributes): self
    {
        if (empty($attributes['opportunity_id'])) {
            throw new InvalidArgumentException('Agentic Marketing actions require an opportunity_id for deduplication.');
        }

        $attributes['action_type'] = self::normalizeType($attributes['action_type'] ?? null);
        $attributes['status'] = self::normalizeStatus($attributes['status'] ?? self::STATUS_PROPOSED);
        $attributes['payload'] = (array) ($attributes['payload'] ?? []);

        $payloadHash = AgenticMarketingDedupe::payloadHash($attributes['payload']);
        $dedupeHash = AgenticMarketingDedupe::actionHash($attributes['action_type'] ?? null, $payloadHash);

        $existing = self::query()
            ->where('opportunity_id', $attributes['opportunity_id'])
            ->where('dedupe_hash', $dedupeHash)
            ->open()
            ->first();

        if ($existing) {
            return $existing;
        }

        return self::query()->create(array_merge($attributes, [
            'payload_hash' => $payloadHash,
            'dedupe_hash' => $dedupeHash,
            'open_dedupe_hash' => $dedupeHash,
        ]));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PROPOSED,
            self::STATUS_APPROVED,
            self::STATUS_RUNNING,
        ]);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingRun::class, 'run_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function actionRuns(): HasMany
    {
        return $this->hasMany(AgenticActionRun::class, 'action_id');
    }

    public function canExecute(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public static function normalizeType(?string $type): ?string
    {
        $type = trim((string) $type);

        return AgenticMarketingActionType::tryFrom($type)?->value ?: ($type !== '' ? $type : null);
    }

    public static function normalizeStatus(?string $status): string
    {
        $status = trim((string) $status);

        return AgenticMarketingActionStatus::tryFrom($status)?->value ?: self::STATUS_PROPOSED;
    }

    public static function isOpenStatus(?string $status): bool
    {
        return AgenticMarketingActionStatus::tryFrom((string) $status)?->isOpen() ?? false;
    }
}
