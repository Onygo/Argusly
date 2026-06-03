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
    'official_name',
    'tagline',
    'short_description',
    'long_description',
    'mission',
    'vision',
    'positioning',
    'value_proposition',
    'tone_of_voice',
    'primary_audience',
    'secondary_audience',
    'website',
    'metadata',
])]
class BrandProfile extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (BrandProfile $profile): void {
            $profile->uuid ??= (string) Str::uuid();
        });

        static::saving(function (BrandProfile $profile): void {
            $brand = Brand::query()->find($profile->brand_id);

            if (! $brand || $brand->account_id !== $profile->account_id) {
                throw new InvalidArgumentException('Brand profile brand must belong to the same account.');
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
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
