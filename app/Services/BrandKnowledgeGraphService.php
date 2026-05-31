<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\BrandEntity;
use App\Models\Entity;
use App\Models\EntityRelationship;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BrandKnowledgeGraphService
{
    /**
     * @param  array{name: string, description?: string|null, aliases?: array<int, string>|string|null, entity_type: string}  $attributes
     */
    public function createEntity(Account $account, array $attributes): Entity
    {
        $entityType = $attributes['entity_type'] ?? null;

        if (! in_array($entityType, Entity::TYPES, true)) {
            throw new InvalidArgumentException("Invalid entity type [{$entityType}].");
        }

        return Entity::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'name' => trim($attributes['name']),
                'entity_type' => $entityType,
            ],
            [
                'description' => $attributes['description'] ?? null,
                'aliases' => $this->aliases($attributes['aliases'] ?? null),
            ],
        );
    }

    /**
     * @param  array{name: string, description?: string|null, aliases?: array<int, string>|string|null, entity_type: string}  $attributes
     */
    public function createForBrand(Account $account, Brand $brand, array $attributes): BrandEntity
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $entity = $this->createEntity($account, $attributes);

        return $this->attachToBrand($account, $brand, $entity);
    }

    public function attachToBrand(Account $account, Brand $brand, Entity $entity): BrandEntity
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $this->ensureEntityBelongsToAccount($account, $entity);

        return BrandEntity::query()->firstOrCreate([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'entity_id' => $entity->id,
        ]);
    }

    public function relate(Account $account, Brand $brand, Entity|int $source, Entity|int $target, string $relationshipType): EntityRelationship
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $source = $source instanceof Entity ? $source : Entity::query()->where('account_id', $account->id)->findOrFail($source);
        $target = $target instanceof Entity ? $target : Entity::query()->where('account_id', $account->id)->findOrFail($target);

        $this->ensureEntityBelongsToAccount($account, $source);
        $this->ensureEntityBelongsToAccount($account, $target);

        if (! in_array($relationshipType, EntityRelationship::TYPES, true)) {
            throw new InvalidArgumentException("Invalid relationship type [{$relationshipType}].");
        }

        return EntityRelationship::query()->firstOrCreate([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relationship_type' => $relationshipType,
        ]);
    }

    /**
     * @return array{brandEntities: Collection<int, BrandEntity>, relationships: Collection<int, EntityRelationship>, typeCounts: Collection<string, int>, futureUseCases: array<int, array{label: string, status: string}>}
     */
    public function graphForBrand(Account $account, Brand $brand): array
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $brandEntities = BrandEntity::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with('entity')
            ->get()
            ->sortBy(fn (BrandEntity $brandEntity) => [$brandEntity->entity->entity_type, $brandEntity->entity->name])
            ->values();

        $entityIds = $brandEntities->pluck('entity_id')->all();

        $relationships = EntityRelationship::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->with(['sourceEntity', 'targetEntity'])
            ->latest()
            ->get();

        return [
            'brandEntities' => $brandEntities,
            'relationships' => $relationships,
            'typeCounts' => $brandEntities->groupBy(fn (BrandEntity $brandEntity) => $brandEntity->entity->entity_type)->map->count(),
            'futureUseCases' => [
                ['label' => 'AI visibility scoring', 'status' => 'planned'],
                ['label' => 'Entity coverage', 'status' => 'planned'],
                ['label' => 'Topic authority', 'status' => 'planned'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function aliases(array|string|null $aliases): array
    {
        if (is_string($aliases)) {
            $aliases = explode(',', $aliases);
        }

        return collect($aliases ?? [])
            ->map(fn (string $alias) => trim($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Knowledge graph brand must belong to the account.');
        }
    }

    private function ensureEntityBelongsToAccount(Account $account, Entity $entity): void
    {
        if ($entity->account_id !== $account->id) {
            throw new InvalidArgumentException('Knowledge graph entity must belong to the account.');
        }
    }
}
