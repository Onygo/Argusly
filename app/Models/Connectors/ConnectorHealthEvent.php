<?php

namespace App\Models\Connectors;

use App\Models\ClientSite;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorHealthEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_WARNING = 'warning';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_EXPIRED_TOKEN = 'expired_token';
    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERROR = 'error';
    public const STATUS_CRITICAL = 'critical';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    public const EVENT_RESOLVED = 'health.resolved';
    public const EVENT_RECOVERED = 'health.recovered';
    public const EVENT_TOKEN_EXPIRED = 'token.expired';
    public const EVENT_RECONNECT_REQUIRED = 'oauth.needs_reconnect';
    public const EVENT_RATE_LIMITED = 'api.rate_limited';
    public const EVENT_DISABLED = 'connector.disabled';

    protected $fillable = [
        'connector_account_id',
        'connector_dataset_id',
        'workspace_id',
        'client_site_id',
        'provider_key',
        'severity',
        'event_type',
        'message',
        'context_json',
        'occurred_at',
    ];

    protected $casts = [
        'context_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ConnectorDataset::class, 'connector_dataset_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }
}
