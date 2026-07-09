<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorWebhookRegistration extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PREPARED = 'prepared';
    public const STATUS_NOT_SUPPORTED = 'not_supported';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider_key',
        'status',
        'event_types_json',
        'target_url',
        'external_webhook_id',
        'registered_at',
        'metadata_json',
    ];

    protected $casts = [
        'event_types_json' => 'array',
        'registered_at' => 'datetime',
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
}
