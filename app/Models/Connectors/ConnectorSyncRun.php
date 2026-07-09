<?php

namespace App\Models\Connectors;

use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectorSyncRun extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';

    public const STALE_FAILURE_MARKER = '[stale_connector_sync_run]';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_BACKFILL = 'backfill';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_DISCOVERY = 'discovery';

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_RUNNING,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_RUNNING => [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_SUCCEEDED => [],
        self::STATUS_FAILED => [],
        self::STATUS_SKIPPED => [],
        self::STATUS_CANCELLED => [],
    ];


    protected $fillable = [
        'connector_account_id',
        'connector_dataset_id',
        'workspace_id',
        'client_site_id',
        'provider_key',
        'dataset_key',
        'status',
        'run_type',
        'window_start',
        'window_end',
        'cursor_before_json',
        'cursor_after_json',
        'started_at',
        'finished_at',
        'attempts',
        'error_message',
        'metrics_json',
        'rate_limit_json',
        'retry_json',
        'next_retry_at',
        'cancelled_at',
        'idempotency_key',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'cursor_before_json' => 'array',
        'cursor_after_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempts' => 'integer',
        'metrics_json' => 'array',
        'rate_limit_json' => 'array',
        'retry_json' => 'array',
        'next_retry_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    public function isStaleFailure(): bool
    {
        return $this->status === self::STATUS_FAILED
            && str_contains((string) $this->error_message, self::STALE_FAILURE_MARKER);
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

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

    public function marketingObservations(): HasMany
    {
        return $this->hasMany(MarketingObservation::class, 'connector_sync_run_id');
    }
}
