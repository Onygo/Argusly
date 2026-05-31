<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'ga4_property_id',
    'content_asset_id',
    'page_path',
    'date',
    'sessions',
    'users',
    'pageviews',
    'engagement_rate',
    'conversions',
    'metadata',
])]
class Ga4MetricSnapshot extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Ga4MetricSnapshot $snapshot): void {
            $snapshot->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Ga4MetricSnapshot $snapshot): void {
            self::assertTenantReferences($snapshot);
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
     * @return BelongsTo<Ga4Property, $this>
     */
    public function ga4Property(): BelongsTo
    {
        return $this->belongsTo(Ga4Property::class);
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
            'date' => 'date',
            'engagement_rate' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    private static function assertTenantReferences(Ga4MetricSnapshot $snapshot): void
    {
        $property = Ga4Property::query()->find($snapshot->ga4_property_id);

        if (! $property || $property->account_id !== $snapshot->account_id || $property->brand_id !== $snapshot->brand_id) {
            throw new InvalidArgumentException('GA4 metric snapshot property must belong to the same account and brand.');
        }

        if ($snapshot->content_asset_id !== null) {
            $asset = ContentAsset::query()->find($snapshot->content_asset_id);

            if (! $asset || $asset->account_id !== $snapshot->account_id || $asset->brand_id !== $snapshot->brand_id) {
                throw new InvalidArgumentException('GA4 metric snapshot content asset must belong to the same account and brand.');
            }
        }
    }
}
