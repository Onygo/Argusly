<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributionResult extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'attribution_run_id',
        'attribution_touchpoint_id',
        'attribution_conversion_id',
        'result_key',
        'model_key',
        'credit',
        'value',
        'currency',
        'match_confidence',
        'metadata_json',
    ];

    protected $casts = [
        'credit' => 'decimal:8',
        'value' => 'decimal:6',
        'metadata_json' => 'array',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(AttributionRun::class, 'attribution_run_id');
    }

    public function touchpoint(): BelongsTo
    {
        return $this->belongsTo(AttributionTouchpoint::class, 'attribution_touchpoint_id');
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AttributionConversion::class, 'attribution_conversion_id');
    }
}
