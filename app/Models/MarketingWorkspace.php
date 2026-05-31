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
    'name',
    'description',
    'status',
    'settings',
])]
class MarketingWorkspace extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (MarketingWorkspace $workspace): void {
            $workspace->uuid ??= (string) Str::uuid();
            $workspace->status ??= 'active';
        });

        static::saving(function (MarketingWorkspace $workspace): void {
            if (! in_array($workspace->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid marketing workspace status [{$workspace->status}].");
            }

            if ($workspace->brand_id !== null) {
                $brand = Brand::query()->find($workspace->brand_id);

                if (! $brand || $brand->account_id !== $workspace->account_id) {
                    throw new InvalidArgumentException('Marketing workspace brand must belong to the same account.');
                }
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
            'settings' => 'array',
        ];
    }
}
