<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorAsyncReportJob extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_dataset_id',
        'provider_key',
        'dataset_key',
        'report_type',
        'external_report_id',
        'status',
        'requested_at',
        'ready_at',
        'completed_at',
        'failed_at',
        'rate_limit_json',
        'payload_json',
        'metadata_json',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'rate_limit_json' => 'array',
        'payload_json' => 'array',
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
}
