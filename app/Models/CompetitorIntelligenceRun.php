<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitorIntelligenceRun extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'site_competitor_id',
        'status',
        'source_type',
        'cache_key',
        'content_items_count',
        'topics_count',
        'opportunities_count',
        'input',
        'result',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'content_items_count' => 'integer',
        'topics_count' => 'integer',
        'opportunities_count' => 'integer',
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

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SiteCompetitor::class, 'site_competitor_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(CompetitorContentOpportunity::class, 'competitor_intelligence_run_id');
    }
}
