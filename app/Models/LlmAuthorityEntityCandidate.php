<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmAuthorityEntityCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'llm_tracking_query_id',
        'site_competitor_id',
        'brand_name',
        'normalized_name',
        'entity_category',
        'mention_count',
        'average_rank',
        'latest_rank',
        'first_seen_at',
        'last_seen_at',
        'source_urls',
        'provider_breakdown',
        'query_breakdown',
        'evidence',
        'confidence_score',
        'status',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'source_urls' => 'array',
        'provider_breakdown' => 'array',
        'query_breakdown' => 'array',
        'evidence' => 'array',
        'confidence_score' => 'float',
        'average_rank' => 'float',
        'latest_rank' => 'integer',
        'mention_count' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function trackingQuery()
    {
        return $this->belongsTo(LlmTrackingQuery::class, 'llm_tracking_query_id');
    }

    public function competitor()
    {
        return $this->belongsTo(SiteCompetitor::class, 'site_competitor_id');
    }

    public function learnings()
    {
        return $this->hasMany(LlmAuthorityLearning::class, 'llm_authority_entity_candidate_id');
    }
}
