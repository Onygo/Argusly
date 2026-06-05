<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StructuredAnswerBlock extends Model
{
    use HasUuids;

    protected $fillable = [
        'content_id',
        'question',
        'answer',
        'entities',
        'platforms',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * @return Attribute<array<int,mixed>,array<int,mixed>|string|null>
     */
    protected function entities(): Attribute
    {
        return Attribute::make(
            get: fn ($value): array => $this->safeJsonArray($value),
            set: fn ($value): ?string => $this->encodeJsonArray($value),
        );
    }

    /**
     * @return Attribute<array<int,mixed>,array<int,mixed>|string|null>
     */
    protected function platforms(): Attribute
    {
        return Attribute::make(
            get: fn ($value): array => $this->safeJsonArray($value),
            set: fn ($value): ?string => $this->encodeJsonArray($value),
        );
    }

    /**
     * @return array<int,mixed>
     */
    private function safeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJsonArray(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode(array_values((array) $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
