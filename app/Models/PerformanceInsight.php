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
    'content_asset_id',
    'campaign_id',
    'type',
    'title',
    'summary',
    'severity',
    'impact_score',
    'payload',
    'detected_at',
    'resolved_at',
])]
class PerformanceInsight extends Model
{
    use HasFactory;

    public const TYPES = [
        'traffic_drop',
        'ranking_drop',
        'ctr_opportunity',
        'social_gap',
        'translation_gap',
        'visibility_gap',
        'content_decay',
        'campaign_underperformance',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected static function booted(): void
    {
        static::creating(function (PerformanceInsight $insight): void {
            $insight->uuid ??= (string) Str::uuid();
            $insight->detected_at ??= now();
        });

        static::saving(function (PerformanceInsight $insight): void {
            if (! in_array($insight->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid performance insight type [{$insight->type}].");
            }

            if (! in_array($insight->severity, self::SEVERITIES, true)) {
                throw new InvalidArgumentException("Invalid performance insight severity [{$insight->severity}].");
            }

            self::assertTenantReferences($insight);
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

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @param  Builder<PerformanceInsight>  $query
     * @return Builder<PerformanceInsight>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'impact_score' => 'integer',
            'payload' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    private static function assertTenantReferences(PerformanceInsight $insight): void
    {
        $brand = Brand::query()->find($insight->brand_id);

        if (! $brand || $brand->account_id !== $insight->account_id) {
            throw new InvalidArgumentException('Performance insight brand must belong to the same account.');
        }

        if ($insight->content_asset_id !== null) {
            $asset = ContentAsset::query()->find($insight->content_asset_id);

            if (! $asset || $asset->account_id !== $insight->account_id || $asset->brand_id !== $insight->brand_id) {
                throw new InvalidArgumentException('Performance insight content asset must belong to the same account and brand.');
            }
        }

        if ($insight->campaign_id !== null) {
            $campaign = Campaign::query()->find($insight->campaign_id);

            if (! $campaign || $campaign->account_id !== $insight->account_id || $campaign->brand_id !== $insight->brand_id) {
                throw new InvalidArgumentException('Performance insight campaign must belong to the same account and brand.');
            }
        }
    }
}
