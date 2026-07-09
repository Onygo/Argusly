<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingTimelineEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'marketing_objective_id',
        'marketing_initiative_id',
        'actor_id',
        'occurred_at',
        'event_type',
        'title',
        'summary',
        'resource_type',
        'resource_id',
        'resource_key',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'occurred_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('created_at');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(MarketingInitiative::class, 'marketing_initiative_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
