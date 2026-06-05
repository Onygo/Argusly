<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftComparisonScore extends Model
{
    use HasUuids;

    protected $fillable = [
        'draft_comparison_variant_id',
        'metric_key',
        'metric_label',
        'metric_group',
        'source_type',
        'numeric_score',
        'text_score',
        'explanation',
    ];

    protected $casts = [
        'numeric_score' => 'decimal:3',
    ];

    public function draftComparisonVariant(): BelongsTo
    {
        return $this->belongsTo(DraftComparisonVariant::class);
    }
}
