<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'audience_id',
    'name',
    'description',
    'rules',
    'status',
])]
class Segment extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'inactive', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Segment $segment): void {
            $segment->uuid ??= (string) Str::uuid();
            $segment->status ??= 'active';
        });

        static::saving(function (Segment $segment): void {
            if (! in_array($segment->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid segment status [{$segment->status}].");
            }

            $segment->validateBrand();
            $segment->validateAudience();
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

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
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
            'rules' => 'array',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $brand->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Segment brand must belong to the same account.');
        }
    }

    private function validateAudience(): void
    {
        if ($this->audience_id === null) {
            return;
        }

        $audience = Audience::query()->find($this->audience_id);

        if (! $audience || $audience->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Segment audience must belong to the same account.');
        }

        if ($audience->brand_id !== null && $this->brand_id !== null && $audience->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Segment audience must belong to the same brand scope.');
        }
    }
}
