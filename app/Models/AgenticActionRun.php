<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticActionRun extends Model
{
    use HasUuids;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPROVAL_REQUIRED = 'approval_required';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id',
        'brand_id',
        'goal_id',
        'opportunity_id',
        'content_id',
        'action_id',
        'action_type',
        'execution_mode_snapshot',
        'status',
        'reason',
        'policy_snapshot',
        'input_snapshot',
        'output_snapshot',
        'estimated_credits',
        'actual_credits',
        'approved_by',
        'approved_at',
        'executed_by_agent',
        'job_id',
        'error_message',
    ];

    protected $casts = [
        'policy_snapshot' => 'array',
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'estimated_credits' => 'integer',
        'actual_credits' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'executed_by_agent' => 'boolean',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PROPOSED,
            self::STATUS_APPROVAL_REQUIRED,
            self::STATUS_APPROVED,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_BLOCKED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class, 'brand_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'goal_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingAction::class, 'action_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
