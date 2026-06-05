<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentIndexationHealth extends Model
{
    use HasUuids;

    protected $table = 'content_indexation_health';

    protected $fillable = [
        'content_id',
        'indexed',
        'canonical_accepted',
        'duplicate_detected',
        'redirect_issue',
        'crawled_not_indexed',
        'noindex_detected',
        'sitemap_status',
        'last_checked_at',
        'health_score',
        'canonical_url',
        'google_selected_canonical',
        'issues_json',
        'discovered_urls_json',
    ];

    protected $casts = [
        'indexed' => 'boolean',
        'canonical_accepted' => 'boolean',
        'duplicate_detected' => 'boolean',
        'redirect_issue' => 'boolean',
        'crawled_not_indexed' => 'boolean',
        'noindex_detected' => 'boolean',
        'last_checked_at' => 'datetime',
        'health_score' => 'integer',
        'issues_json' => 'array',
        'discovered_urls_json' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
