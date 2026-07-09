<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingObjective extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'marketing_theme_id',
        'name',
        'description',
        'desired_outcome',
        'status',
        'priority',
        'target_metric_key',
        'target_value',
        'current_value',
        'market_pack_key',
        'starts_on',
        'ends_on',
        'topics_json',
        'entities_json',
        'channels_json',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'target_value' => 'decimal:4',
        'current_value' => 'decimal:4',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'topics_json' => 'array',
        'entities_json' => 'array',
        'channels_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(MarketingTheme::class, 'marketing_theme_id');
    }

    public function initiatives(): HasMany
    {
        return $this->hasMany(MarketingInitiative::class);
    }

    public function priorities(): HasMany
    {
        return $this->hasMany(MarketingPriority::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(MarketingWorkflow::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(MarketingTimelineEvent::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MarketingReview::class);
    }

    public function operatingLinks(): HasMany
    {
        return $this->hasMany(MarketingOperatingLink::class);
    }
}
