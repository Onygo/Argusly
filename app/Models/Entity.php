<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Services\DomainEventService;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'slug',
    'description',
    'aliases',
    'entity_type',
    'status',
    'metadata',
])]
class Entity extends Model
{
    use HasFactory;

    public const TYPES = [
        'company',
        'person',
        'product',
        'service',
        'location',
        'technology',
        'topic',
        'competitor',
        'creator',
        'journalist',
        'organization',
    ];

    public const STATUSES = ['draft', 'active', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Entity $entity): void {
            $entity->uuid ??= (string) Str::uuid();
            $entity->slug = $entity->slug ?: Str::slug($entity->name);
            $entity->status ??= 'active';
        });

        static::created(function (Entity $entity): void {
            if ($entity->account_id !== null) {
                app(DomainEventService::class)->recordForSubject('EntityCreated', $entity, null, [
                    'name' => $entity->name,
                    'entity_type' => $entity->entity_type,
                ], dispatch: false);
            }

            if ($entity->account_id !== null) {
                app(\App\Services\Graph\GraphProjectionService::class)->project($entity);
            }
        });

        static::saving(function (Entity $entity): void {
            $entity->slug = $entity->slug ?: Str::slug($entity->name);
            $entity->status ??= 'active';
            $entity->entity_type = Str::of($entity->entity_type)->snake()->lower()->toString();

            if (! in_array($entity->entity_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid entity type [{$entity->entity_type}].");
            }

            if (! in_array($entity->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid entity status [{$entity->status}].");
            }

            if ($entity->brand_id !== null) {
                $brand = Brand::query()->find($entity->brand_id);

                if (! $brand || $entity->account_id === null || $brand->account_id !== $entity->account_id) {
                    throw new InvalidArgumentException('Entity brand must belong to the same account.');
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
     * @return HasMany<EntityAlias, $this>
     */
    public function aliasRecords(): HasMany
    {
        return $this->hasMany(EntityAlias::class);
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
     * @return BelongsToMany<Mention, $this>
     */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(Mention::class, 'entity_mentions')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Topic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'entity_topics')->withTimestamps();
    }

    /**
     * @param  Builder<Entity>  $query
     * @return Builder<Entity>
     */
    public function scopeForAccount(Builder $query, Account $account): Builder
    {
        return $query->where(fn (Builder $scope) => $scope
            ->whereNull('account_id')
            ->orWhere('account_id', $account->id));
    }

    /**
     * @param  Builder<Entity>  $query
     * @return Builder<Entity>
     */
    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand): Builder
    {
        return $query->where(fn (Builder $accountScope) => $accountScope
            ->whereNull('account_id')
            ->orWhere('account_id', $account->id))
            ->when(
                $brand !== null,
                fn (Builder $brandScope) => $brandScope->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $brandScope) => $brandScope->whereNull('brand_id'),
            );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'metadata' => 'array',
        ];
    }
}
