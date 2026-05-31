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
    'provider',
    'query',
    'brand',
    'status',
    'last_checked_at',
])]
class VisibilityCheck extends Model
{
    use HasFactory, HasTopics;

    public const PROVIDERS = ['Google', 'Google AI Overviews', 'ChatGPT', 'Claude', 'Gemini', 'Perplexity'];

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (VisibilityCheck $check): void {
            $check->uuid ??= (string) Str::uuid();
            $check->status ??= 'active';
        });

        static::saving(function (VisibilityCheck $check): void {
            if (! in_array($check->provider, self::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid visibility provider [{$check->provider}].");
            }

            if (! in_array($check->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid visibility check status [{$check->status}].");
            }

            $brand = Brand::query()->find($check->brand_id);

            if (! $brand || $brand->account_id !== $check->account_id) {
                throw new InvalidArgumentException('Visibility check brand must belong to the check account.');
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
    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * @return HasMany<VisibilityResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(VisibilityResult::class);
    }

    /**
     * @return HasMany<VisibilityResult, $this>
     */
    public function latestResult(): HasMany
    {
        return $this->hasMany(VisibilityResult::class)->latest('captured_at')->latest();
    }

    /**
     * @return HasMany<VisibilityProviderRun, $this>
     */
    public function providerRuns(): HasMany
    {
        return $this->hasMany(VisibilityProviderRun::class);
    }

    /**
     * @return HasMany<VisibilityProviderRun, $this>
     */
    public function latestProviderRun(): HasMany
    {
        return $this->hasMany(VisibilityProviderRun::class)->latest('captured_at')->latest();
    }

    /**
     * @param  Builder<VisibilityCheck>  $query
     * @return Builder<VisibilityCheck>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
        ];
    }
}
