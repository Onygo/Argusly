<?php

namespace App\Models;

use App\Enums\ContentRecommendationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRecommendation extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_id',
        'type',
        'priority',
        'status',
        'payload',
        'generated_by',
    ];

    protected $casts = [
        'status' => ContentRecommendationStatus::class,
        'payload' => 'array',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
