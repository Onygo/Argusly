<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonitoredPage extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const CRAWL_STATUS_NEW = 'new';
    public const CRAWL_STATUS_DISCOVERED = 'discovered';
    public const CRAWL_STATUS_FETCHED = 'fetched';
    public const CRAWL_STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_source_id',
        'canonical_url',
        'canonical_url_hash',
        'first_seen_url',
        'first_seen_url_hash',
        'final_url',
        'final_url_hash',
        'domain',
        'path',
        'source_type',
        'page_type',
        'content_type',
        'publisher_name',
        'language_current',
        'title_current',
        'published_at_current',
        'first_seen_at',
        'last_seen_at',
        'last_fetched_at',
        'last_changed_at',
        'crawl_status',
        'indexability_status',
        'dedupe_key',
        'syndication_group_key',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'published_at_current' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'last_changed_at' => 'datetime',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(MonitoredSource::class, 'monitored_source_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PageSnapshot::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PageSnapshot::class)->latestOfMany('snapshot_number');
    }

    public function contentExtractions(): HasMany
    {
        return $this->hasMany(PageContentExtraction::class);
    }

    public function latestContentExtraction(): HasOne
    {
        return $this->hasOne(PageContentExtraction::class)->latestOfMany('created_at');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(PageEntity::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PageMention::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(PageTopic::class);
    }

    public function sentiments(): HasMany
    {
        return $this->hasMany(PageSentiment::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(PageScore::class);
    }

    public function prValues(): HasMany
    {
        return $this->hasMany(PagePrValue::class);
    }

    public function campaignMatches(): HasMany
    {
        return $this->hasMany(PageCampaignMatch::class);
    }

    public function competitorMatches(): HasMany
    {
        return $this->hasMany(PageCompetitorMatch::class);
    }

    public function brandMatches(): HasMany
    {
        return $this->hasMany(PageBrandMatch::class);
    }

    public function marketPackMatches(): HasMany
    {
        return $this->hasMany(PageMarketPackMatch::class);
    }

    public function serpObservations(): HasMany
    {
        return $this->hasMany(PageSerpObservation::class);
    }

    public function geoObservations(): HasMany
    {
        return $this->hasMany(PageGeoObservation::class);
    }
}
