<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAuditReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_transparency_record_id',
        'format',
        'status',
        'path',
        'checksum',
        'snapshot',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
    ];

    public function transparencyRecord(): BelongsTo
    {
        return $this->belongsTo(AiTransparencyRecord::class, 'ai_transparency_record_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
