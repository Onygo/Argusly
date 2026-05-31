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
    'source_entity_id',
    'target_entity_id',
    'relationship_type',
])]
class EntityRelationship extends Model
{
    use HasFactory;

    public const TYPES = ['owns', 'offers', 'uses', 'competes_with', 'located_in', 'related_to'];

    protected static function booted(): void
    {
        static::creating(function (EntityRelationship $relationship): void {
            $relationship->uuid ??= (string) Str::uuid();
        });

        static::saving(function (EntityRelationship $relationship): void {
            if (! in_array($relationship->relationship_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid entity relationship type [{$relationship->relationship_type}].");
            }

            if ($relationship->source_entity_id === $relationship->target_entity_id) {
                throw new InvalidArgumentException('Entity relationship source and target must be different.');
            }

            $source = Entity::query()->find($relationship->source_entity_id);
            $target = Entity::query()->find($relationship->target_entity_id);

            if (! $source || ! $target || $source->account_id !== $relationship->account_id || $target->account_id !== $relationship->account_id) {
                throw new InvalidArgumentException('Entity relationship entities must belong to the same account.');
            }

            if ($relationship->brand_id !== null) {
                $brand = Brand::query()->find($relationship->brand_id);

                if (! $brand || $brand->account_id !== $relationship->account_id) {
                    throw new InvalidArgumentException('Entity relationship brand must belong to the same account.');
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
     * @return BelongsTo<Entity, $this>
     */
    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
