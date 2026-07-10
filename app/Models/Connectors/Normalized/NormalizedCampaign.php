<?php

namespace App\Models\Connectors\Normalized;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedCampaign extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_normalized_campaigns';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider',
        'provider_campaign_id',
        'account_id',
        'name',
        'objective',
        'status',
        'start_date',
        'end_date',
        'budget',
        'currency',
        'raw_reference',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:6',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(NormalizedMarketingAccount::class, 'account_id');
    }
}
