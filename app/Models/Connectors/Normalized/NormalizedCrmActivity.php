<?php

namespace App\Models\Connectors\Normalized;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedCrmActivity extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_normalized_crm_activities';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider',
        'provider_activity_id',
        'company_id',
        'contact_id',
        'deal_id',
        'type',
        'subject',
        'occurred_at',
        'owner_id',
        'raw_reference',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(NormalizedCrmCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(NormalizedCrmContact::class, 'contact_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(NormalizedCrmDeal::class, 'deal_id');
    }
}
