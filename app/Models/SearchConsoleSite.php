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
    'site_url',
    'status',
    'metadata',
    'last_synced_at',
])]
class SearchConsoleSite extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'connected', 'syncing', 'error', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (SearchConsoleSite $site): void {
            $site->uuid ??= (string) Str::uuid();
            $site->status ??= 'draft';
        });

        static::saving(function (SearchConsoleSite $site): void {
            if (! in_array($site->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid Search Console site status [{$site->status}].");
            }

            self::assertTenantReferences($site);
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
     * @return HasMany<SearchConsoleQuerySnapshot, $this>
     */
    public function querySnapshots(): HasMany
    {
        return $this->hasMany(SearchConsoleQuerySnapshot::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    private static function assertTenantReferences(SearchConsoleSite $site): void
    {
        $brand = Brand::query()->find($site->brand_id);

        if (! $brand || $brand->account_id !== $site->account_id) {
            throw new InvalidArgumentException('Search Console site brand must belong to the same account.');
        }

        if ($site->integration_connection_id !== null) {
            $connection = IntegrationConnection::query()->find($site->integration_connection_id);

            if (! $connection || $connection->account_id !== $site->account_id || ($connection->brand_id !== null && $connection->brand_id !== $site->brand_id)) {
                throw new InvalidArgumentException('Search Console integration connection must belong to the same account and brand scope.');
            }
        }
    }
}
