<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignClusterRun extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'workspace_id', 'client_site_id', 'status', 'source_type',
        'clusters_count', 'created_count', 'refreshed_count', 'input', 'result',
        'failure_reason', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'clusters_count' => 'integer',
        'created_count' => 'integer',
        'refreshed_count' => 'integer',
        'input' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(CampaignCluster::class, 'campaign_cluster_run_id');
    }
}
