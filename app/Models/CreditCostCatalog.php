<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['code', 'name', 'description', 'category', 'default_cost', 'minimum_cost', 'maximum_cost', 'cost_type', 'status', 'metadata'])]
class CreditCostCatalog extends Model
{
    use HasFactory;

    protected $table = 'credit_cost_catalog';

    public const CATEGORIES = ['content', 'translation', 'visibility', 'social', 'newsletter', 'agent', 'monitoring', 'system'];

    public const COST_TYPES = ['fixed', 'variable'];

    public const STATUSES = ['active', 'inactive'];

    protected static function booted(): void
    {
        static::creating(function (CreditCostCatalog $catalog): void {
            $catalog->uuid ??= (string) Str::uuid();
        });

        static::saving(function (CreditCostCatalog $catalog): void {
            if (! in_array($catalog->category, self::CATEGORIES, true)) {
                throw new InvalidArgumentException("Invalid credit catalog category [{$catalog->category}].");
            }

            if (! in_array($catalog->cost_type, self::COST_TYPES, true)) {
                throw new InvalidArgumentException("Invalid credit catalog cost type [{$catalog->cost_type}].");
            }

            if (! in_array($catalog->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid credit catalog status [{$catalog->status}].");
            }
        });
    }

    /**
     * @return HasMany<CreditCostOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(CreditCostOverride::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_cost' => 'integer',
            'minimum_cost' => 'integer',
            'maximum_cost' => 'integer',
            'metadata' => 'array',
        ];
    }
}
