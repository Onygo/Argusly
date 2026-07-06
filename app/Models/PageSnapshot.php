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

class PageSnapshot extends Model
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
        'snapshot_number',
        'requested_url',
        'final_url',
        'canonical_url',
        'http_status',
        'content_type',
        'response_headers_json',
        'redirect_chain_json',
        'raw_html_path',
        'raw_html',
        'raw_html_bytes',
        'raw_html_preview',
        'raw_html_hash',
        'text_hash',
        'content_changed',
        'canonical_conflict',
        'fetch_duration_ms',
        'fetched_at',
        'fetcher_version',
        'error_code',
        'error_message',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'snapshot_number' => 'integer',
        'http_status' => 'integer',
        'response_headers_json' => 'array',
        'redirect_chain_json' => 'array',
        'raw_html_bytes' => 'integer',
        'content_changed' => 'boolean',
        'canonical_conflict' => 'boolean',
        'fetch_duration_ms' => 'integer',
        'fetched_at' => 'datetime',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function contentExtraction(): HasOne
    {
        return $this->hasOne(PageContentExtraction::class);
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
}
