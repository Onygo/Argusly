<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingInitiative extends Model
{
    use HasUuids;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'marketing_objective_id',
        'marketing_theme_id',
        'owner_user_id',
        'name',
        'summary',
        'status',
        'priority',
        'market_pack_key',
        'starts_on',
        'ends_on',
        'topics_json',
        'entities_json',
        'channels_json',
        'competitors_json',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'topics_json' => 'array',
        'entities_json' => 'array',
        'channels_json' => 'array',
        'competitors_json' => 'array',
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

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(MarketingTheme::class, 'marketing_theme_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
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
