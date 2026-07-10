<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributionRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'attribution_model_configuration_id',
        'model_key',
        'status',
        'period_start',
        'period_end',
        'lookback_days',
        'started_at',
        'finished_at',
        'conversions_processed',
        'touchpoints_matched',
        'results_written',
        'latest_error',
        'metadata_json',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'lookback_days' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'conversions_processed' => 'integer',
        'touchpoints_matched' => 'integer',
        'results_written' => 'integer',
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

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(AttributionModelConfiguration::class, 'attribution_model_configuration_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AttributionResult::class);
    }
}
