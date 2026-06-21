<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanSignalEvidence extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'human_signal_evidence';

    protected $fillable = [
        'human_signal_id',
        'source_type',
        'source_id',
        'title',
        'summary',
        'weight',
        'metrics_json',
        'metadata_json',
    ];

    protected $casts = [
        'weight' => 'float',
        'metrics_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(HumanSignal::class, 'human_signal_id');
    }
}
