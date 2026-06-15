<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecommendedAction extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISMISSED = 'dismissed';

    public const SOURCE_OPPORTUNITY = 'opportunities';
    public const SOURCE_LEARNING = 'learning';
    public const SOURCE_AI_VISIBILITY = 'ai_visibility';
    public const SOURCE_AGENTIC_MARKETING = 'agentic_marketing';
    public const SOURCE_CAMPAIGN_PLANNING = 'campaign_planning';
    public const SOURCE_CONTENT_INTELLIGENCE = 'content_intelligence';
    public const SOURCE_COMPETITOR_INTELLIGENCE = 'competitor_intelligence';
    public const SOURCE_DISTRIBUTION = 'distribution';

    public const EFFORT_LOW = 'low';
    public const EFFORT_MEDIUM = 'medium';
    public const EFFORT_HIGH = 'high';
    public const EFFORT_AUTOMATED = 'automated';

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'user_id',
        'source_type',
        'source_id',
        'source_signature',
        'source_group',
        'action_type',
        'status',
        'title',
        'summary',
        'why_this_matters',
        'expected_outcome',
        'what_argusly_will_do',
        'what_requires_approval',
        'estimated_effort',
        'priority_score',
        'confidence_score',
        'expected_impact_score',
        'priority_label',
        'confidence_label',
        'expected_impact_label',
        'primary_cta_label',
        'primary_cta_url',
        'metadata',
        'visible_at',
        'approved_at',
        'completed_at',
        'dismissed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'priority_score' => 'integer',
        'confidence_score' => 'integer',
        'expected_impact_score' => 'integer',
        'metadata' => 'array',
        'visible_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->whereNull('visible_at')->orWhere('visible_at', '<=', now());
        });
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
