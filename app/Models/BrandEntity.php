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
    'entity_id',
])]
class BrandEntity extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (BrandEntity $brandEntity): void {
            $brandEntity->uuid ??= (string) Str::uuid();
        });

        static::saving(function (BrandEntity $brandEntity): void {
            $brand = Brand::query()->find($brandEntity->brand_id);
            $entity = Entity::query()->find($brandEntity->entity_id);

            if (! $brand || $brand->account_id !== $brandEntity->account_id) {
                throw new InvalidArgumentException('Brand entity brand must belong to the same account.');
            }

            if (! $entity || $entity->account_id !== $brandEntity->account_id) {
                throw new InvalidArgumentException('Brand entity must belong to the same account.');
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
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
