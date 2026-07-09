<?php

namespace App\Models\Connectors;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorQuotaBudget extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPE_HOURLY = 'hourly';
    public const TYPE_DAILY = 'daily';

    public const STATUS_OK = 'ok';
    public const STATUS_SOFT_WARNING = 'soft_warning';
    public const STATUS_HARD_STOP = 'hard_stop';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider_key',
        'budget_type',
        'limit_value',
        'used_value',
        'warning_threshold_percent',
        'status',
        'period_started_at',
        'period_ends_at',
        'reset_at',
        'metadata_json',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'used_value' => 'integer',
        'warning_threshold_percent' => 'integer',
        'period_started_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'reset_at' => 'datetime',
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
