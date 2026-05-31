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
    'integration_connection_id',
    'property_id',
    'display_name',
    'website_url',
    'status',
    'metadata',
    'last_synced_at',
])]
class Ga4Property extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'connected', 'syncing', 'error', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Ga4Property $property): void {
            $property->uuid ??= (string) Str::uuid();
            $property->status ??= 'draft';
        });

        static::saving(function (Ga4Property $property): void {
            if (! in_array($property->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid GA4 property status [{$property->status}].");
            }

            self::assertTenantReferences($property);
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
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<Ga4MetricSnapshot, $this>
     */
    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(Ga4MetricSnapshot::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    private static function assertTenantReferences(Ga4Property $property): void
    {
        $brand = Brand::query()->find($property->brand_id);

        if (! $brand || $brand->account_id !== $property->account_id) {
            throw new InvalidArgumentException('GA4 property brand must belong to the same account.');
        }

        if ($property->integration_connection_id !== null) {
            $connection = IntegrationConnection::query()->find($property->integration_connection_id);

            if (! $connection || $connection->account_id !== $property->account_id || ($connection->brand_id !== null && $connection->brand_id !== $property->brand_id)) {
                throw new InvalidArgumentException('GA4 integration connection must belong to the same account and brand scope.');
            }
        }

        if ($property->property_id !== null) {
            $contentProperty = Property::query()->find($property->property_id);

            if (! $contentProperty || $contentProperty->account_id !== $property->account_id || $contentProperty->brand_id !== $property->brand_id) {
                throw new InvalidArgumentException('GA4 property mapping must belong to the same account and brand.');
            }
        }
    }
}
