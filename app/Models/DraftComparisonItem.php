<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftComparisonItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'draft_comparison_id',
        'draft_id',
        'sort_order',
        'provider',
        'model',
        'status',
        'credit_cost',
        'charged_credits',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'generation_started_at',
        'generation_completed_at',
        'error_message',
        'metrics',
        'meta',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'credit_cost' => 'integer',
        'charged_credits' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'generation_started_at' => 'datetime',
        'generation_completed_at' => 'datetime',
        'metrics' => 'array',
        'meta' => 'array',
    ];

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(DraftComparison::class, 'draft_comparison_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }
}
