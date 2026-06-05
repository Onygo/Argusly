<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorContentItem extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'site_competitor_id',
        'source_type',
        'url',
        'url_hash',
        'title',
        'meta_description',
        'content_excerpt',
        'normalized_text',
        'content_type',
        'content_format',
        'query_intent',
        'funnel_stage',
        'landing_page_angle',
        'is_comparison_page',
        'has_answer_block_pattern',
        'has_schema_pattern',
        'detected_topics',
        'detected_entities',
        'seo_patterns',
        'aeo_patterns',
        'normalized_payload',
        'normalized_payload_hash',
        'imported_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'is_comparison_page' => 'boolean',
        'has_answer_block_pattern' => 'boolean',
        'has_schema_pattern' => 'boolean',
        'detected_topics' => 'array',
        'detected_entities' => 'array',
        'seo_patterns' => 'array',
        'aeo_patterns' => 'array',
        'normalized_payload' => 'array',
        'imported_at' => 'datetime',
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
