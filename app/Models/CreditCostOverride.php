<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['account_id', 'brand_id', 'credit_cost_catalog_id', 'override_cost', 'status'])]
class CreditCostOverride extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'inactive'];

    protected static function booted(): void
    {
        static::creating(function (CreditCostOverride $override): void {
            $override->uuid ??= (string) Str::uuid();
        });

        static::saving(function (CreditCostOverride $override): void {
            if (! in_array($override->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid credit override status [{$override->status}].");
            }

            if ($override->brand_id !== null) {
                $brand = Brand::query()->find($override->brand_id);

                if (! $brand || ($override->account_id !== null && $brand->account_id !== $override->account_id)) {
                    throw new InvalidArgumentException('Credit override brand must belong to the override account.');
                }
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
     * @return BelongsTo<CreditCostCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(CreditCostCatalog::class, 'credit_cost_catalog_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'override_cost' => 'integer',
        ];
    }
}
