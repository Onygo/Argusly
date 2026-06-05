<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftImprovementResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'draft_id',
        'before_analysis_id',
        'after_analysis_id',
        'action',
        'status',
        'operation_key',
        'requested_by_user_id',
        'prompt_version',
        'provider',
        'model_used',
        'request_id',
        'tokens_used',
        'before_content_hash',
        'after_content_hash',
        'affected_sections',
        'summary',
        'change_notes',
        'fully_applied',
        'score_delta_snapshot',
        'started_at',
        'completed_at',
        'failed_at',
        'error',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
        'affected_sections' => 'array',
        'change_notes' => 'array',
        'fully_applied' => 'boolean',
        'score_delta_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function beforeAnalysis(): BelongsTo
    {
        return $this->belongsTo(DraftAnalysis::class, 'before_analysis_id');
    }

    public function afterAnalysis(): BelongsTo
    {
        return $this->belongsTo(DraftAnalysis::class, 'after_analysis_id');
    }

    public function deltas(): HasMany
    {
        return $this->hasMany(DraftIntelligenceDelta::class);
    }
}
