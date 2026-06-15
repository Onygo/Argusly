<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GrowthAutopilotQueueItem extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_NEEDS_APPROVAL = 'needs_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $table = 'growth_autopilot_queue_items';

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'recommended_action_id',
        'source_type',
        'source_id',
        'source_signature',
        'source_group',
        'status',
        'opportunity',
        'recommended_action',
        'expected_impact',
        'expected_impact_score',
        'confidence_score',
        'priority_score',
        'priority_label',
        'approval_requirement',
        'approval_required',
        'prepared_assets',
        'approval_cta_label',
        'approval_cta_url',
        'metadata',
        'queued_at',
        'approved_at',
        'completed_at',
        'dismissed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'expected_impact_score' => 'integer',
        'confidence_score' => 'integer',
        'priority_score' => 'integer',
        'approval_required' => 'boolean',
        'prepared_assets' => 'array',
        'metadata' => 'array',
        'queued_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function recommendedAction(): BelongsTo
    {
        return $this->belongsTo(RecommendedAction::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_QUEUED,
            self::STATUS_NEEDS_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_PREPARED,
        ]);
    }

    public function approve(): self
    {
        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'approved_at' => now(),
        ])->save();

        return $this->refresh();
    }

    public function dismiss(): self
    {
        $this->forceFill([
            'status' => self::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();

        return $this->refresh();
    }
}
