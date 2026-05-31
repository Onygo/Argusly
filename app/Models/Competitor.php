<?php

namespace App\Models;

use App\Models\Concerns\HasTopics;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'website',
    'industry',
    'status',
])]
class Competitor extends Model
{
    use HasFactory, HasTopics;

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Competitor $competitor): void {
            $competitor->uuid ??= (string) Str::uuid();
            $competitor->status ??= 'active';
        });

        static::saving(function (Competitor $competitor): void {
            if (! in_array($competitor->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid competitor status [{$competitor->status}].");
            }

            $brand = Brand::query()->find($competitor->brand_id);

            if (! $brand || $brand->account_id !== $competitor->account_id) {
                throw new InvalidArgumentException('Competitor brand must belong to the competitor account.');
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

    /**
     * @return HasMany<CompetitorSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CompetitorSnapshot::class);
    }

    /**
     * @return HasMany<CompetitorSnapshot, $this>
     */
    public function latestSnapshot(): HasMany
    {
        return $this->hasMany(CompetitorSnapshot::class)->latest('captured_at')->latest();
    }

    /**
     * @param  Builder<Competitor>  $query
     * @return Builder<Competitor>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
