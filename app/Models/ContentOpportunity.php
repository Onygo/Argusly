<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentOpportunity extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    public const STATUS_OPEN = 'open';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'content_opportunity_run_id',
        'type',
        'status',
        'freshness_status',
        'title',
        'reasoning',
        'why_this_matters',
        'why_now',
        'competitor_pressure',
        'ai_visibility_opportunity',
        'target_audience',
        'funnel_stage',
        'primary_search_intent',
        'angle',
        'expected_impact',
        'confidence_score',
        'urgency_score',
        'business_value_score',
        'priority_score',
        'related_entities',
        'supporting_existing_content',
        'recommended_internal_links',
        'localization_recommendation',
        'suggested_cta',
        'suggested_schema',
        'source_signals',
        'query_intent_payload',
        'normalized_payload',
        'dedupe_hash',
        'first_seen_at',
        'last_seen_at',
        'stale_at',
        'expires_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'confidence_score' => 'float',
        'urgency_score' => 'float',
        'business_value_score' => 'float',
        'priority_score' => 'float',
        'related_entities' => 'array',
        'supporting_existing_content' => 'array',
        'recommended_internal_links' => 'array',
        'localization_recommendation' => 'array',
        'source_signals' => 'array',
        'query_intent_payload' => 'array',
        'normalized_payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'stale_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentOpportunityRun::class, 'content_opportunity_run_id');
    }
}
