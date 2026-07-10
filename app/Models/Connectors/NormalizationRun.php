<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class NormalizationRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'connector_normalization_runs';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'connector_dataset_id',
        'connector_sync_run_id',
        'connector_backfill_range_id',
        'provider',
        'dataset_key',
        'source_type',
        'source_key',
        'scope_start_date',
        'scope_end_date',
        'scope_hash',
        'active_scope_hash',
        'trigger',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'records_processed',
        'records_written',
        'records_failed',
        'records_skipped',
        'latest_error',
        'metadata_json',
    ];

    protected $casts = [
        'scope_start_date' => 'date',
        'scope_end_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'records_processed' => 'integer',
        'records_written' => 'integer',
        'records_failed' => 'integer',
        'records_skipped' => 'integer',
        'metadata_json' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_RUNNING];
    }

    /**
     * @return array<int, string>
     */
    public static function terminalStatuses(): array
    {
        return [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function scopeHashFor(array $attributes): string
    {
        $payload = [
            'workspace_id' => self::scopeValue($attributes['workspace_id'] ?? null),
            'connector_account_id' => self::scopeValue($attributes['connector_account_id'] ?? null),
            'connector_dataset_id' => self::scopeValue($attributes['connector_dataset_id'] ?? null),
            'provider' => self::scopeValue($attributes['provider'] ?? null),
            'dataset_key' => self::scopeValue($attributes['dataset_key'] ?? null),
            'connector_sync_run_id' => self::scopeValue($attributes['connector_sync_run_id'] ?? null),
            'connector_backfill_range_id' => self::scopeValue($attributes['connector_backfill_range_id'] ?? null),
            'source_type' => self::scopeValue($attributes['source_type'] ?? null),
            'source_key' => self::scopeValue($attributes['source_key'] ?? null),
            'scope_start_date' => self::dateScopeValue($attributes['scope_start_date'] ?? null),
            'scope_end_date' => self::dateScopeValue($attributes['scope_end_date'] ?? null),
        ];

        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::activeStatuses(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::terminalStatuses(), true);
    }

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

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ConnectorSyncRun::class, 'connector_sync_run_id');
    }

    public function backfillRange(): BelongsTo
    {
        return $this->belongsTo(ConnectorBackfillRange::class, 'connector_backfill_range_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NormalizationRunItem::class, 'connector_normalization_run_id');
    }

    private static function scopeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function dateScopeValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
