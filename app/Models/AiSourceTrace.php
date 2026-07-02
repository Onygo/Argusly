<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSourceTrace extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'source_type',
        'url',
        'title',
        'retrieval_status',
        'retrieved_at',
        'content_hash',
        'reliability_score',
        'used_for_sections',
        'metadata',
    ];

    protected $casts = [
        'retrieved_at' => 'datetime',
        'reliability_score' => 'integer',
        'used_for_sections' => 'array',
        'metadata' => 'array',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }
}
