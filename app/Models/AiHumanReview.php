<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHumanReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'reviewer_id',
        'status',
        'checklist',
        'notes',
        'reviewed_at',
    ];

    protected $casts = [
        'checklist' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
