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
    'strength',
    'metadata',
])]
class EntityRelationship extends Model
{
    use HasFactory;

    public const TYPES = [
        'owns',
        'offers',
        'uses',
        'works_for',
        'competes_with',
        'mentions',
        'located_in',
        'related_to',
        'partner_of',
    ];

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

            if (! $source || ! $target) {
                throw new InvalidArgumentException('Entity relationship entities must exist.');
            }

            if ($source->account_id !== null && $target->account_id !== null && $source->account_id !== $target->account_id) {
                throw new InvalidArgumentException('Entity relationship entities must belong to the same account scope.');
            }

            if ($source->brand_id !== null && $target->brand_id !== null && $source->brand_id !== $target->brand_id) {
                throw new InvalidArgumentException('Entity relationship entities must belong to the same brand scope.');
            }

            if ($relationship->account_id !== null && ($source->account_id !== null && $source->account_id !== $relationship->account_id || $target->account_id !== null && $target->account_id !== $relationship->account_id)) {
                throw new InvalidArgumentException('Entity relationship account must match scoped entities.');
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

    protected function casts(): array
    {
        return [
            'strength' => 'integer',
            'metadata' => 'array',
        ];
    }
}
