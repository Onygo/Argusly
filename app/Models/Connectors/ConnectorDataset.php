<?php

namespace App\Models\Connectors;

use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorDatasetCapability;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorDataset extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'connector_account_id',
        'workspace_id',
        'client_site_id',
        'provider_key',
        'dataset_key',
        'dataset_type',
        'external_dataset_id',
        'display_name',
        'status',
        'sync_frequency',
        'next_sync_at',
        'last_sync_at',
        'discovered_at',
        'last_seen_at',
        'deactivated_at',
        'health_status',
        'health_severity',
        'latest_health_event_id',
        'health_checked_at',
        'cursor_json',
        'capabilities_json',
        'sync_config_json',
        'config_json',
        'metadata_json',
    ];

    protected $casts = [
        'next_sync_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'health_checked_at' => 'datetime',
        'cursor_json' => 'array',
        'capabilities_json' => 'array',
        'sync_config_json' => 'array',
        'config_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeWithCapability(Builder $query, string $capability): Builder
    {
        $key = ConnectorDatasetCapability::normalizeKey($capability);

        return $query->where(function (Builder $query) use ($key): void {
            $query
                ->whereJsonContains('capabilities_json->keys', $key)
                ->orWhere('capabilities_json', 'like', '%"'.$key.'"%');
        });
    }

    public function isSyncEligible(?string $requiredCapability = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->deactivated_at !== null) {
            return false;
        }

        if ($requiredCapability !== null && ! $this->hasCapability($requiredCapability)) {
            return false;
        }

        return $this->next_sync_at === null || $this->next_sync_at->isPast();
    }

    public function hasCapability(string $capability): bool
    {
        $key = ConnectorDatasetCapability::normalizeKey($capability);
        $capabilities = (array) ($this->capabilities_json ?? []);

        if (in_array($key, (array) ($capabilities['keys'] ?? []), true)) {
            return true;
        }

        return (bool) data_get($capabilities, 'definitions.'.$key.'.enabled', false);
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(ConnectorSyncRun::class);
    }

    public function healthEvents(): HasMany
    {
        return $this->hasMany(ConnectorHealthEvent::class);
    }

    public function marketingObservations(): HasMany
    {
        return $this->hasMany(MarketingObservation::class, 'connector_dataset_id');
    }

    public function rawRecords(): HasMany
    {
        return $this->hasMany(ConnectorRawRecord::class, 'connector_dataset_id');
    }

    public function backfillRanges(): HasMany
    {
        return $this->hasMany(ConnectorBackfillRange::class, 'connector_dataset_id');
    }

    public function asyncReportJobs(): HasMany
    {
        return $this->hasMany(ConnectorAsyncReportJob::class, 'connector_dataset_id');
    }

    public function fieldMappingPreparations(): HasMany
    {
        return $this->hasMany(ConnectorFieldMappingPreparation::class, 'connector_dataset_id');
    }
}
