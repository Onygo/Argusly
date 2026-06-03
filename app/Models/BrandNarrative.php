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
    'title',
    'description',
    'importance',
    'status',
])]
class BrandNarrative extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'active', 'archived'];

    public const IMPORTANCE_LEVELS = ['low', 'medium', 'high', 'critical'];

    protected static function booted(): void
    {
        static::creating(function (BrandNarrative $narrative): void {
            $narrative->uuid ??= (string) Str::uuid();
            $narrative->status ??= 'draft';
            $narrative->importance ??= 'medium';
        });

        static::saving(function (BrandNarrative $narrative): void {
            if (! in_array($narrative->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid brand narrative status [{$narrative->status}].");
            }

            if (! in_array($narrative->importance, self::IMPORTANCE_LEVELS, true)) {
                throw new InvalidArgumentException("Invalid brand narrative importance [{$narrative->importance}].");
            }

            $brand = Brand::query()->find($narrative->brand_id);

            if (! $brand || $brand->account_id !== $narrative->account_id) {
                throw new InvalidArgumentException('Brand narrative brand must belong to the same account.');
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
}
