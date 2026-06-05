<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsRollupDaily extends Model
{
    protected $table = 'analytics_rollups_daily';

    protected $fillable = [
        'analytics_site_id',
        'date',
        'path',
        'path_hash',
        'article_id',
        'title',
        'page_views',
        'unique_visitors',
        'scroll_50',
        'scroll_100',
        'heartbeats',
        'engaged_views',
        'total_time_seconds',
    ];

    protected $casts = [
        'date' => 'date',
        'page_views' => 'integer',
        'unique_visitors' => 'integer',
        'scroll_50' => 'integer',
        'scroll_100' => 'integer',
        'heartbeats' => 'integer',
        'engaged_views' => 'integer',
        'total_time_seconds' => 'integer',
    ];

    public function analyticsSite(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class);
    }

    public static function computePathHash(string $path): string
    {
        return hash('sha256', $path);
    }
}
