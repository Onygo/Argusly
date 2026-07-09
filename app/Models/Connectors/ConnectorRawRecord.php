<?php

namespace App\Models\Connectors;

use App\Models\ClientSite;
use App\Models\Workspace;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorRawRecord extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'connector_provider_id',
        'connector_account_id',
        'connector_dataset_id',
        'connector_sync_run_id',
        'provider_key',
        'dataset_key',
        'record_type',
        'external_record_id',
        'fingerprint',
        'period_start',
        'period_end',
        'observed_at',
        'payload_json',
        'metadata_json',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'observed_at' => 'datetime',
        'payload_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function setPayloadJsonAttribute(array $value): void
    {
        $this->attributes['payload_json'] = json_encode(MarketingMetadataRedactor::redact($value), JSON_THROW_ON_ERROR);
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value), JSON_THROW_ON_ERROR);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ConnectorProvider::class, 'connector_provider_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ConnectorDataset::class, 'connector_dataset_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ConnectorSyncRun::class, 'connector_sync_run_id');
    }
}
