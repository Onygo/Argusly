<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'name',
    'description',
    'aliases',
    'entity_type',
])]
class Entity extends Model
{
    use HasFactory;

    public const TYPES = ['Person', 'Company', 'Product', 'Service', 'Location', 'Technology', 'Topic'];

    protected static function booted(): void
    {
        static::creating(function (Entity $entity): void {
            $entity->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Entity $entity): void {
            if (! in_array($entity->entity_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid entity type [{$entity->entity_type}].");
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
     * @return HasMany<BrandEntity, $this>
     */
    public function brandEntities(): HasMany
    {
        return $this->hasMany(BrandEntity::class);
    }

    /**
     * @return HasMany<EntityRelationship, $this>
     */
    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'source_entity_id');
    }

    /**
     * @return HasMany<EntityRelationship, $this>
     */
    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'target_entity_id');
    }

    /**
     * @param  Builder<Entity>  $query
     * @return Builder<Entity>
     */
    public function scopeForAccount(Builder $query, Account $account): Builder
    {
        return $query->where('account_id', $account->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
        ];
    }
}
