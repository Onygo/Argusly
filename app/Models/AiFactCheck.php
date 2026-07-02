<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFactCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'claim',
        'status',
        'confidence',
        'evidence',
        'notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'evidence' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
