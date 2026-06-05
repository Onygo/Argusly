<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorContentOpportunity extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'site_competitor_id',
        'competitor_intelligence_run_id',
        'type',
        'status',
        'title',
        'topic',
        'query_intent',
        'funnel_stage',
        'recommended_format',
        'priority_score',
        'confidence_score',
        'impact_score',
        'effort_score',
        'attackable_angle',
        'reason',
        'competitor_evidence',
        'publishlayer_coverage',
        'normalized_payload',
        'dedupe_hash',
        'last_seen_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'priority_score' => 'float',
        'confidence_score' => 'float',
        'impact_score' => 'float',
        'effort_score' => 'float',
        'competitor_evidence' => 'array',
        'publishlayer_coverage' => 'array',
        'normalized_payload' => 'array',
        'last_seen_at' => 'datetime',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(CompetitorIntelligenceRun::class, 'competitor_intelligence_run_id');
    }
}
