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
    'name',
    'description',
    'category',
    'website',
    'status',
    'metadata',
])]
class BrandProduct extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (BrandProduct $product): void {
            $product->uuid ??= (string) Str::uuid();
            $product->status ??= 'draft';
        });

        static::saving(function (BrandProduct $product): void {
            if (! in_array($product->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid brand product status [{$product->status}].");
            }

            $brand = Brand::query()->find($product->brand_id);

            if (! $brand || $brand->account_id !== $product->account_id) {
                throw new InvalidArgumentException('Brand product brand must belong to the same account.');
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
            'metadata' => 'array',
        ];
    }
}
