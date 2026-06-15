<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentPackage extends Model
{
    use HasUuids;

    public const STATUS_PREPARING = 'preparing';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'workspace_id',
        'organization_id',
        'client_site_id',
        'growth_autopilot_queue_item_id',
        'recommended_action_id',
        'source_type',
        'source_id',
        'source_signature',
        'status',
        'title',
        'opportunity_summary',
        'recommended_action',
        'brief_id',
        'draft_id',
        'linkedin_variant_id',
        'cta_recommendation',
        'internal_linking_suggestions',
        'publishing_checklist',
        'prepared_assets',
        'metadata',
        'prepared_at',
        'completed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'cta_recommendation' => 'array',
        'internal_linking_suggestions' => 'array',
        'publishing_checklist' => 'array',
        'prepared_assets' => 'array',
        'metadata' => 'array',
        'prepared_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(GrowthAutopilotQueueItem::class, 'growth_autopilot_queue_item_id');
    }

    public function recommendedAction(): BelongsTo
    {
        return $this->belongsTo(RecommendedAction::class);
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function linkedInVariant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class, 'linkedin_variant_id');
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }
}
