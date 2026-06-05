<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_site_id',
        'event_type',
        'visitor_hash',
        'session_hash',
        'url',
        'canonical_url',
        'url_key',
        'canonical_url_hash',
        'path',
        'path_hash',
        'title',
        'referrer',
        'host',
        'article_id',
        'content_id',
        'page_type',
        'content_type',
        'meta',
        'received_at',
        'event_hash',
        'ip_hash',
        'user_agent_family',
        'device_type',
        'event_time',
    ];

    public static function computePathHash(string $path): string
    {
        return hash('sha256', $path);
    }

    protected $casts = [
        'meta' => 'array',
        'event_time' => 'datetime',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function analyticsSite(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class);
    }
}
