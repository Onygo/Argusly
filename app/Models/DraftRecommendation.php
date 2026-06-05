<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftRecommendation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'draft_id',
        'draft_analysis_id',
        'metric_key',
        'title',
        'summary',
        'why_it_matters',
        'suggested_action',
        'impact_level',
        'effort_level',
        'confidence_level',
        'priority_score',
        'sort_order',
        'context_payload',
    ];

    protected $casts = [
        'priority_score' => 'integer',
        'sort_order' => 'integer',
        'context_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(DraftAnalysis::class, 'draft_analysis_id');
    }
}
