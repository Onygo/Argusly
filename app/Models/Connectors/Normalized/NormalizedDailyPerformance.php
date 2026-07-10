<?php

namespace App\Models\Connectors\Normalized;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedDailyPerformance extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_normalized_daily_performances';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider',
        'entity_type',
        'entity_id',
        'date',
        'impressions',
        'clicks',
        'cost',
        'conversions',
        'ctr',
        'cpc',
        'cpm',
        'revenue',
        'raw_reference',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'decimal:6',
        'conversions' => 'decimal:6',
        'ctr' => 'decimal:6',
        'cpc' => 'decimal:6',
        'cpm' => 'decimal:6',
        'revenue' => 'decimal:6',
        'raw_reference' => 'array',
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

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }
}
