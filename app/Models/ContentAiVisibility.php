<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentAiVisibility extends Model
{
    protected $table = 'content_ai_visibility';

    protected $fillable = [
        'analytics_site_id',
        'url',
        'url_key',
        'llm_citations',
        'brand_mentions',
        'competitor_mentions',
        'ai_visibility_score',
        'last_checked_at',
    ];

    protected $casts = [
        'llm_citations' => 'integer',
        'brand_mentions' => 'integer',
        'competitor_mentions' => 'integer',
        'ai_visibility_score' => 'float',
        'last_checked_at' => 'datetime',
    ];
}
