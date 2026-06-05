<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'domain',
    'source_type',
    'is_owned',
    'is_competitor',
    'authority_score',
    'last_seen_at',
    'metadata_json',
])]
class VisibilitySource extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (VisibilitySource $source): void {
            $brand = Brand::query()->find($source->brand_id);

            if (! $brand || $brand->account_id !== $source->account_id) {
                throw new InvalidArgumentException('Visibility source brand must belong to the source account.');
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

    protected function casts(): array
    {
        return [
            'is_owned' => 'boolean',
            'is_competitor' => 'boolean',
            'authority_score' => 'integer',
            'last_seen_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }
}
