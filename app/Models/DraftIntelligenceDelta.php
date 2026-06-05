<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftIntelligenceDelta extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'draft_id',
        'draft_improvement_result_id',
        'before_analysis_id',
        'after_analysis_id',
        'metric_key',
        'score_before',
        'score_after',
        'delta',
        'explanation',
        'confidence_level',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function improvementResult(): BelongsTo
    {
        return $this->belongsTo(DraftImprovementResult::class, 'draft_improvement_result_id');
    }

    public function beforeAnalysis(): BelongsTo
    {
        return $this->belongsTo(DraftAnalysis::class, 'before_analysis_id');
    }

    public function afterAnalysis(): BelongsTo
    {
        return $this->belongsTo(DraftAnalysis::class, 'after_analysis_id');
    }
}
