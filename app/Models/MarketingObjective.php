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
    'campaign_id',
    'name',
    'description',
    'type',
    'status',
    'target_value',
    'current_value',
    'unit',
    'start_date',
    'end_date',
    'metadata',
])]
class MarketingObjective extends Model
{
    use HasFactory;

    public const TYPES = [
        'visibility',
        'traffic',
        'leads',
        'engagement',
        'content_output',
        'campaign_performance',
        'brand_awareness',
    ];

    public const STATUSES = ['active', 'paused', 'completed', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (MarketingObjective $objective): void {
            $objective->uuid ??= (string) Str::uuid();
            $objective->status ??= 'active';
        });

        static::saving(function (MarketingObjective $objective): void {
            if (! in_array($objective->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid marketing objective type [{$objective->type}].");
            }

            if (! in_array($objective->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid marketing objective status [{$objective->status}].");
            }

            if ($objective->brand_id !== null) {
                $brand = Brand::query()->find($objective->brand_id);

                if (! $brand || $brand->account_id !== $objective->account_id) {
                    throw new InvalidArgumentException('Marketing objective brand must belong to the same account.');
                }
            }

            if ($objective->campaign_id !== null) {
                $campaign = Campaign::query()->find($objective->campaign_id);

                if (! $campaign || $campaign->account_id !== $objective->account_id) {
                    throw new InvalidArgumentException('Marketing objective campaign must belong to the same account.');
                }

                if ($objective->brand_id !== null && $campaign->brand_id !== $objective->brand_id) {
                    throw new InvalidArgumentException('Marketing objective campaign must belong to the same brand scope.');
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
            'target_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'metadata' => 'array',
        ];
    }
}
