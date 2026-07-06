<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PageContentExtraction extends Model
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
        'extraction_method',
        'extractor_version',
        'title',
        'meta_description',
        'h1',
        'headings_json',
        'author',
        'publisher',
        'published_at',
        'language',
        'summary',
        'main_text',
        'main_text_path',
        'main_text_hash',
        'main_text_bytes',
        'main_text_preview',
        'main_html',
        'main_html_path',
        'main_html_hash',
        'main_html_bytes',
        'word_count',
        'char_count',
        'estimated_tokens',
        'content_depth_score',
        'quality_score',
        'structured_data_json',
        'images_json',
        'media_json',
        'outbound_links_json',
        'internal_links_json',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'headings_json' => 'array',
        'published_at' => 'datetime',
        'main_text_bytes' => 'integer',
        'main_html_bytes' => 'integer',
        'word_count' => 'integer',
        'char_count' => 'integer',
        'estimated_tokens' => 'integer',
        'content_depth_score' => 'decimal:2',
        'quality_score' => 'decimal:2',
        'structured_data_json' => 'array',
        'images_json' => 'array',
        'media_json' => 'array',
        'outbound_links_json' => 'array',
        'internal_links_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class, 'monitored_page_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'page_snapshot_id');
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

    public function mainTextForAnalysis(): string
    {
        $inline = trim((string) $this->main_text);
        if ($inline !== '') {
            return $inline;
        }

        $path = trim((string) $this->main_text_path);
        if ($path === '') {
            return trim((string) $this->main_text_preview);
        }

        return (string) Storage::disk((string) config('page_intelligence.storage.extracted_text_disk', 'local'))->get($path);
    }
}
