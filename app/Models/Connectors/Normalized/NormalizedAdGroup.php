<?php

namespace App\Models\Connectors\Normalized;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedAdGroup extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_normalized_ad_groups';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider',
        'provider_ad_group_id',
        'campaign_id',
        'name',
        'status',
        'bid_strategy',
        'budget',
        'raw_reference',
    ];

    protected $casts = [
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NormalizedCampaign::class, 'campaign_id');
    }
}
