<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageIntelligenceReportSnapshotAllocation extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'report_type',
        'market_pack_key',
        'period_start',
        'period_end',
        'identity_hash',
        'current_version',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'current_version' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }
}
