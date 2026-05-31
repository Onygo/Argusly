<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'campaign_id',
    'related_type',
    'related_id',
    'title',
    'description',
    'type',
    'status',
    'start_at',
    'end_at',
    'assigned_to',
    'metadata',
])]
class MarketingCalendarItem extends Model
{
    use HasFactory;

    public const TYPES = [
        'social_post',
        'content_asset',
        'campaign_task',
        'marketing_task',
        'approval',
        'newsletter',
        'event',
        'reminder',
    ];

    public const STATUSES = [
        'planned',
        'in_progress',
        'scheduled',
        'completed',
        'cancelled',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingCalendarItem $item): void {
            $item->uuid ??= (string) Str::uuid();
            $item->status ??= 'planned';
        });

        static::saving(function (MarketingCalendarItem $item): void {
            if (! in_array($item->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid marketing calendar item type [{$item->type}].");
            }

            if (! in_array($item->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid marketing calendar item status [{$item->status}].");
            }

            if ($item->brand_id === null) {
                return;
            }

            $brand = Brand::query()->find($item->brand_id);

            if (! $brand || $brand->account_id !== $item->account_id) {
                throw new InvalidArgumentException('Marketing calendar item brand must belong to the same account.');
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $scope) => $scope->where('brand_id', $brand->id));
    }

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
