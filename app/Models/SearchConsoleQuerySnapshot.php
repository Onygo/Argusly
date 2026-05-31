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
    'search_console_site_id',
    'content_asset_id',
    'date',
    'query',
    'page',
    'country',
    'device',
    'clicks',
    'impressions',
    'ctr',
    'position',
    'metadata',
])]
class SearchConsoleQuerySnapshot extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (SearchConsoleQuerySnapshot $snapshot): void {
            $snapshot->uuid ??= (string) Str::uuid();
        });

        static::saving(function (SearchConsoleQuerySnapshot $snapshot): void {
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
     * @return BelongsTo<SearchConsoleSite, $this>
     */
    public function searchConsoleSite(): BelongsTo
    {
        return $this->belongsTo(SearchConsoleSite::class);
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
            'ctr' => 'decimal:4',
            'position' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    private static function assertTenantReferences(SearchConsoleQuerySnapshot $snapshot): void
    {
        $site = SearchConsoleSite::query()->find($snapshot->search_console_site_id);

        if (! $site || $site->account_id !== $snapshot->account_id || $site->brand_id !== $snapshot->brand_id) {
            throw new InvalidArgumentException('Search Console query snapshot site must belong to the same account and brand.');
        }

        if ($snapshot->content_asset_id !== null) {
            $asset = ContentAsset::query()->find($snapshot->content_asset_id);

            if (! $asset || $asset->account_id !== $snapshot->account_id || $asset->brand_id !== $snapshot->brand_id) {
                throw new InvalidArgumentException('Search Console query snapshot content asset must belong to the same account and brand.');
            }
        }
    }
}
