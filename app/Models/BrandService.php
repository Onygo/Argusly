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
    'status',
    'metadata',
])]
class BrandService extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (BrandService $service): void {
            $service->uuid ??= (string) Str::uuid();
            $service->status ??= 'draft';
        });

        static::saving(function (BrandService $service): void {
            if (! in_array($service->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid brand service status [{$service->status}].");
            }

            $brand = Brand::query()->find($service->brand_id);

            if (! $brand || $brand->account_id !== $service->account_id) {
                throw new InvalidArgumentException('Brand service brand must belong to the same account.');
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
