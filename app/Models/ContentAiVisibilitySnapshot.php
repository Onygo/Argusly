<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAiVisibilitySnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_id',
        'provider',
        'visibility_score',
        'citation_count',
        'avg_position',
        'sentiment',
        'entities_detected',
        'captured_at',
    ];

    protected $casts = [
        'visibility_score' => 'integer',
        'citation_count' => 'integer',
        'avg_position' => 'float',
        'entities_detected' => 'array',
        'captured_at' => 'datetime',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
