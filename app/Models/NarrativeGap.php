<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
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
    'desired_state',
    'detected_state',
    'gap_score',
    'status',
])]
class NarrativeGap extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = ['new', 'reviewed', 'resolved'];

    protected static function booted(): void
    {
        static::creating(function (NarrativeGap $gap): void {
            $gap->uuid ??= (string) Str::uuid();
            $gap->status ??= 'new';
        });

        static::saving(function (NarrativeGap $gap): void {
            $gap->status ??= 'new';

            if (! in_array($gap->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid narrative gap status [{$gap->status}].");
            }

            $narrative = Narrative::query()->find($gap->narrative_id);

            if (! $narrative || $narrative->account_id !== $gap->account_id || $narrative->brand_id !== $gap->brand_id) {
                throw new InvalidArgumentException('Narrative gap must belong to the same tenant as the narrative.');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function narrative(): BelongsTo
    {
        return $this->belongsTo(Narrative::class);
    }

    protected function casts(): array
    {
        return [
            'gap_score' => 'integer',
        ];
    }
}
