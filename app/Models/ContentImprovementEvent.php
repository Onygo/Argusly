<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentImprovementEvent extends Model
{
    protected $fillable = [
        'content_improvement_run_id',
        'event_type',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentImprovementRun::class, 'content_improvement_run_id');
    }
}
