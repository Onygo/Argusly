<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMetric extends Model
{
    protected $fillable = [
        'analytics_site_id',
        'url',
        'url_key',
        'avg_scroll_depth',
        'max_scroll_depth',
        'avg_read_time',
        'median_read_time',
        'engaged_rate',
        'read_through_rate',
        'estimated_read_time',
        'roi_score',
        'conversion_signals',
        'attribution_signals',
        'ai_traffic_signals',
    ];

    protected $casts = [
        'avg_scroll_depth' => 'float',
        'max_scroll_depth' => 'integer',
        'avg_read_time' => 'float',
        'median_read_time' => 'float',
        'engaged_rate' => 'float',
        'read_through_rate' => 'float',
        'estimated_read_time' => 'float',
        'roi_score' => 'float',
        'conversion_signals' => 'array',
        'attribution_signals' => 'array',
        'ai_traffic_signals' => 'array',
    ];
}
