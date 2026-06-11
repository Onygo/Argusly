<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SignalDetectionLink extends Pivot
{
    use HasFactory;
    use HasUuids;

    protected $table = 'signal_detection_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'signal_detection_id',
        'signal_event_id',
        'weight',
        'contribution',
    ];

    protected $casts = [
        'weight' => 'float',
        'contribution' => 'array',
    ];

    public function detection(): BelongsTo
    {
        return $this->belongsTo(SignalDetection::class, 'signal_detection_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SignalEvent::class, 'signal_event_id');
    }
}
