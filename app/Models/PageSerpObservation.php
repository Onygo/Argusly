<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PageSerpObservation extends Model
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
        'serp_query_set_id',
        'serp_query_id',
        'query',
        'query_hash',
        'locale',
        'country',
        'device',
        'search_engine',
        'observed_at',
        'result_type',
        'position',
        'absolute_position',
        'page_url',
        'page_url_hash',
        'domain',
        'title',
        'snippet',
        'serp_features_json',
        'competitor_presence_json',
        'search_volume',
        'keyword_intent',
        'click_potential',
        'visibility_score',
        'breakdown_json',
        'raw_payload_json',
        'provider_key',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'observed_at' => 'datetime',
        'position' => 'integer',
        'absolute_position' => 'integer',
        'serp_features_json' => 'array',
        'competitor_presence_json' => 'array',
        'search_volume' => 'integer',
        'click_potential' => 'decimal:4',
        'visibility_score' => 'decimal:4',
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

    public function querySet(): BelongsTo
    {
        return $this->belongsTo(SerpQuerySet::class, 'serp_query_set_id');
    }

    public function serpQuery(): BelongsTo
    {
        return $this->belongsTo(SerpQuery::class, 'serp_query_id');
    }
}
