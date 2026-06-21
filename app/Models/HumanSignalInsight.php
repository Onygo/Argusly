<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanSignalInsight extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'human_signal_id',
        'title',
        'insight',
        'recommended_action',
        'quality_score',
        'metadata_json',
    ];

    protected $casts = [
        'quality_score' => 'float',
        'metadata_json' => 'array',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(HumanSignal::class, 'human_signal_id');
    }
}
