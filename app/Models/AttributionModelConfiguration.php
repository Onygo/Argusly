<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributionModelConfiguration extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'workspace_id',
        'key',
        'label',
        'model_key',
        'status',
        'is_default',
        'lookback_days',
        'settings_json',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'lookback_days' => 'integer',
        'settings_json' => 'array',
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

    public function runs(): HasMany
    {
        return $this->hasMany(AttributionRun::class);
    }
}
