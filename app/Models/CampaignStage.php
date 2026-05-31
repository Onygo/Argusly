<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'campaign_id',
    'name',
    'position',
    'status',
])]
class CampaignStage extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'archived'];

    public const DEFAULT_STAGES = [
        'Ideas',
        'Planned',
        'In production',
        'Review',
        'Scheduled',
        'Live',
        'Done',
    ];

    protected static function booted(): void
    {
        static::creating(function (CampaignStage $stage): void {
            $stage->uuid ??= (string) Str::uuid();
            $stage->status ??= 'active';
        });

        static::saving(function (CampaignStage $stage): void {
            if (! in_array($stage->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid campaign stage status [{$stage->status}].");
            }

            $campaign = Campaign::query()->find($stage->campaign_id);

            if (! $campaign || $campaign->account_id !== $stage->account_id || $campaign->brand_id !== $stage->brand_id) {
                throw new InvalidArgumentException('Campaign stage must belong to the same campaign tenant.');
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

    public function items(): HasMany
    {
        return $this->hasMany(CampaignItem::class)->orderBy('position')->latest();
    }
}
