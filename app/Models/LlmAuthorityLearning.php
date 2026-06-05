<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmAuthorityLearning extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'site_competitor_id',
        'llm_authority_entity_candidate_id',
        'llm_tracking_query_id',
        'provider',
        'learning_type',
        'title',
        'summary',
        'evidence',
        'recommended_action',
        'priority',
        'status',
    ];

    protected $casts = [
        'evidence' => 'array',
        'priority' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function competitor()
    {
        return $this->belongsTo(SiteCompetitor::class, 'site_competitor_id');
    }

    public function candidate()
    {
        return $this->belongsTo(LlmAuthorityEntityCandidate::class, 'llm_authority_entity_candidate_id');
    }

    public function trackingQuery()
    {
        return $this->belongsTo(LlmTrackingQuery::class, 'llm_tracking_query_id');
    }
}
