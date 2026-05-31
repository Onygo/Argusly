<?php

namespace App\Models;

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
    'description',
    'status',
    'metadata',
])]
class Audience extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'inactive', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Audience $audience): void {
            $audience->uuid ??= (string) Str::uuid();
            $audience->status ??= 'active';
        });

        static::saving(function (Audience $audience): void {
            if (! in_array($audience->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid audience status [{$audience->status}].");
            }

            $audience->validateBrand();
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

    public function members(): HasMany
    {
        return $this->hasMany(AudienceMember::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $brand->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Audience brand must belong to the same account.');
        }
    }
}
