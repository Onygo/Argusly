<?php

namespace App\Models\Connectors;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorBackfillRange extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_dataset_id',
        'requested_by_user_id',
        'provider_key',
        'dataset_key',
        'status',
        'range_start',
        'range_end',
        'attempts',
        'connector_sync_run_id',
        'last_error',
        'idempotency_key',
        'metadata_json',
    ];

    protected $casts = [
        'range_start' => 'date',
        'range_end' => 'date',
        'attempts' => 'integer',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ConnectorDataset::class, 'connector_dataset_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ConnectorSyncRun::class, 'connector_sync_run_id');
    }

    public function normalizationRuns(): HasMany
    {
        return $this->hasMany(NormalizationRun::class, 'connector_backfill_range_id');
    }
}
