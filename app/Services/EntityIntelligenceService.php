<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EntityIntelligenceService
{
    /**
     * @param  array{search?: string|null, entity_type?: string|null, status?: string|null, scope?: string|null}  $filters
     * @return LengthAwarePaginator<int, Entity>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Entity::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'aliasRecords'])
            ->withCount(['outgoingRelationships', 'incomingRelationships', 'mentions', 'topics'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $scope) use ($search): void {
                    $scope->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('aliasRecords', fn (Builder $aliases) => $aliases->where('alias', 'like', "%{$search}%"));
                });
            })
            ->when($filters['entity_type'] ?? null, fn (Builder $query, string $type) => $query->where('entity_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['scope'] ?? null, function (Builder $query, string $scope): void {
                match ($scope) {
                    'global' => $query->whereNull('account_id')->whereNull('brand_id'),
                    'account' => $query->whereNotNull('account_id')->whereNull('brand_id'),
                    'brand' => $query->whereNotNull('brand_id'),
                    default => null,
                };
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $entityId): Entity
    {
        return Entity::query()
            ->forTenant($account, $brand)
            ->with([
                'brand',
                'aliasRecords',
                'outgoingRelationships.targetEntity',
                'incomingRelationships.sourceEntity',
                'mentions.source',
                'topics',
            ])
            ->withCount(['outgoingRelationships', 'incomingRelationships', 'mentions', 'topics'])
            ->findOrFail($entityId);
    }

    /**
     * @return Collection<int, Entity>
     */
    public function search(Account $account, ?Brand $brand, string $query, int $limit = 8): Collection
    {
        return Entity::query()
            ->forTenant($account, $brand)
            ->where(function (Builder $scope) use ($query): void {
                $scope->where('name', 'like', "%{$query}%")
                    ->orWhereHas('aliasRecords', fn (Builder $aliases) => $aliases->where('alias', 'like', "%{$query}%"));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
