<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageGeoObservation extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_page_id',
        'page_snapshot_id',
        'llm_tracking_query_id',
        'llm_tracking_query_run_id',
        'query',
        'query_hash',
        'answer_engine',
        'provider',
        'model',
        'locale',
        'observed_at',
        'cited_url',
        'cited_url_hash',
        'cited_domain',
        'citation_position',
        'citation_count',
        'mentioned_brands_json',
        'mentioned_competitors_json',
        'client_cited',
        'competitors_cited',
        'brand_mentioned',
        'sentiment',
        'topic_ownership_score',
        'consistency_score',
        'geo_visibility_score',
        'breakdown_json',
        'answer_summary',
        'raw_payload_json',
        'retention_policy',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'observed_at' => 'datetime',
        'citation_position' => 'integer',
        'citation_count' => 'integer',
        'mentioned_brands_json' => 'array',
        'mentioned_competitors_json' => 'array',
        'client_cited' => 'boolean',
        'competitors_cited' => 'boolean',
        'brand_mentioned' => 'boolean',
        'topic_ownership_score' => 'decimal:4',
        'consistency_score' => 'decimal:4',
        'geo_visibility_score' => 'decimal:4',
        'breakdown_json' => 'array',
        'raw_payload_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
    }

    public function trackingQuery(): BelongsTo
    {
        return $this->belongsTo(LlmTrackingQuery::class, 'llm_tracking_query_id');
    }

    public function trackingRun(): BelongsTo
    {
        return $this->belongsTo(LlmTrackingQueryRun::class, 'llm_tracking_query_run_id');
    }
}
