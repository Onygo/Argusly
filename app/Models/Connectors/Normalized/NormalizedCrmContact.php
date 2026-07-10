<?php

namespace App\Models\Connectors\Normalized;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedCrmContact extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'connector_normalized_crm_contacts';

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'provider',
        'provider_contact_id',
        'company_id',
        'email_hash',
        'first_name',
        'last_name',
        'job_title',
        'owner_id',
        'lifecycle_stage',
        'raw_reference',
    ];

    protected $casts = [
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
}
