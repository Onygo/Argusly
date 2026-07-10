<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributionTouchpoint extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'touchpoint_key',
        'anonymous_or_contact_key',
        'occurred_at',
        'channel',
        'source',
        'medium',
        'campaign_id',
        'ad_group_id',
        'ad_id',
        'landing_page',
        'referrer',
        'session_key',
        'raw_reference',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw_reference' => 'array',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(AttributionResult::class);
    }
}
