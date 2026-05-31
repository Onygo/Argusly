<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'content_asset_id',
    'language',
    'locale',
    'status',
    'health_score',
    'freshness_score',
    'performance_score',
    'visibility_score',
    'refresh_priority',
    'reason',
    'signals',
    'scored_at',
])]
class ContentLifecycleScore extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = [
        'healthy',
        'watch',
        'decaying',
        'needs_refresh',
        'critical',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContentLifecycleScore $score): void {
            $score->uuid ??= (string) Str::uuid();
            $score->scored_at ??= now();
            $score->language ??= $score->contentAsset?->language ?? 'en';
            $score->locale ??= $score->contentAsset?->locale;
        });

        static::saving(function (ContentLifecycleScore $score): void {
            $contentAsset = ContentAsset::query()->find($score->content_asset_id);

            if (! $contentAsset || $contentAsset->account_id !== $score->account_id || $contentAsset->brand_id !== $score->brand_id) {
                throw new InvalidArgumentException('Content lifecycle score asset must belong to the same account and brand.');
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
     * @return BelongsTo<ContentAsset, $this>
     */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }

    protected function casts(): array
    {
        return [
            'health_score' => 'integer',
            'freshness_score' => 'integer',
            'performance_score' => 'integer',
            'visibility_score' => 'integer',
            'refresh_priority' => 'integer',
            'signals' => 'array',
            'scored_at' => 'datetime',
        ];
    }
}
