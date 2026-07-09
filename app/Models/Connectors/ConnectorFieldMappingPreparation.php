<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorFieldMappingPreparation extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PREPARED = 'prepared';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_dataset_id',
        'provider_key',
        'object_key',
        'status',
        'source_fields_json',
        'target_preview_json',
        'metadata_json',
        'prepared_at',
    ];

    protected $casts = [
        'source_fields_json' => 'array',
        'target_preview_json' => 'array',
        'metadata_json' => 'array',
        'prepared_at' => 'datetime',
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
