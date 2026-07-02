<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProvenanceEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'event_type',
        'actor_type',
        'actor_id',
        'actor_label',
        'summary',
        'input_hash',
        'output_hash',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
