<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorTopicSignal extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'site_competitor_id',
        'topic',
        'topic_hash',
        'competitor_content_count',
        'publishlayer_content_count',
        'overlap_score',
        'opportunity_score',
        'coverage_status',
        'intent_mix',
        'formats',
        'entities',
        'examples',
        'last_seen_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'competitor_content_count' => 'integer',
        'publishlayer_content_count' => 'integer',
        'overlap_score' => 'float',
        'opportunity_score' => 'float',
        'intent_mix' => 'array',
        'formats' => 'array',
        'entities' => 'array',
        'examples' => 'array',
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
}
