<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'narrative_id',
    'source_type',
    'source_id',
    'observation',
    'sentiment',
    'confidence_score',
    'detected_at',
])]
class NarrativeObservation extends Model
{
    use HasFactory;

    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'mixed'];

    protected static function booted(): void
    {
        static::creating(function (NarrativeObservation $observation): void {
            $observation->uuid ??= (string) Str::uuid();
            $observation->detected_at ??= now();
        });

        static::saving(function (NarrativeObservation $observation): void {
            if ($observation->sentiment !== null && ! in_array($observation->sentiment, self::SENTIMENTS, true)) {
                throw new InvalidArgumentException("Invalid narrative observation sentiment [{$observation->sentiment}].");
            }

            $narrative = Narrative::query()->find($observation->narrative_id);

            if (! $narrative || $narrative->account_id !== $observation->account_id || $narrative->brand_id !== $observation->brand_id) {
                throw new InvalidArgumentException('Narrative observation must belong to the same tenant as the narrative.');
            }
        });
    }

    public function narrative(): BelongsTo
    {
        return $this->belongsTo(Narrative::class);
    }

    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'detected_at' => 'datetime',
        ];
    }
}
