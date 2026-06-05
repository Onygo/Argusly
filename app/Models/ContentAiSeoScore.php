<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentAiSeoScore extends Model
{
    protected $table = 'content_ai_seo_scores';

    protected $fillable = [
        'analytics_site_id',
        'url',
        'url_key',
        'url_hash',
        'content_roi_score',
        'ai_visibility_score',
        'ai_visibility_score_normalized',
        'ai_seo_score',
        'weights_json',
        'formula_version',
        'inputs_json',
        'calculated_at',
        'content_metrics_updated_at',
        'ai_visibility_updated_at',
    ];

    protected $casts = [
        'content_roi_score' => 'float',
        'ai_visibility_score' => 'float',
        'ai_visibility_score_normalized' => 'float',
        'ai_seo_score' => 'float',
        'weights_json' => 'array',
        'inputs_json' => 'array',
        'calculated_at' => 'datetime',
        'content_metrics_updated_at' => 'datetime',
        'ai_visibility_updated_at' => 'datetime',
    ];
}
