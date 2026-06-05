<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefSuggestion extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'brief_id',
        'suggestion_type',
        'original_value',
        'suggested_value',
        'rationale',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function isPending(): bool
    {
        return (string) $this->status === self::STATUS_PENDING;
    }

    public function isApplied(): bool
    {
        return (string) $this->status === self::STATUS_APPLIED;
    }

    public function isRejected(): bool
    {
        return (string) $this->status === self::STATUS_REJECTED;
    }

    public function decodedSuggestedValue(): mixed
    {
        $raw = trim((string) ($this->suggested_value ?? ''));

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
