<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Recommendation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecommendationService
{
    /**
     * @return Collection<int, Recommendation>
     */
    public function recentForTenant(Account $account, ?Brand $brand = null, int $limit = 5): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->open()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Recommendation>
     */
    public function forSignal(Account $account, ?Brand $brand, int $signalId): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->where('signal_id', $signalId)
            ->latest('created_at')
            ->get();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Recommendation
    {
        return $this->tenantQuery($account, $brand)->whereKey($id)->firstOrFail();
    }

    /**
     * @param  array{status?: string|null, brand_id?: string|null}  $filters
     * @return LengthAwarePaginator<int, Recommendation>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(($filters['brand_id'] ?? null) === 'account', fn (Builder $query) => $query->whereNull('brand_id'))
            ->when(($filters['brand_id'] ?? null) !== null && ($filters['brand_id'] ?? '') !== 'account' && ($filters['brand_id'] ?? '') !== '', fn (Builder $query) => $query->where('brand_id', (int) $filters['brand_id']))
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{open: int, reviewed: int, accepted: int, completed: int, archived: int}
     */
    public function statisticsForTenant(Account $account, ?Brand $brand = null): array
    {
        $query = $this->tenantQuery($account, $brand);

        return [
            'open' => (clone $query)->open()->count(),
            'reviewed' => (clone $query)->where('status', 'reviewed')->count(),
            'accepted' => (clone $query)->where('status', 'accepted')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'archived' => (clone $query)->where('status', 'archived')->count(),
        ];
    }

    /**
     * @return Collection<int, Brand>
     */
    public function tenantBrands(Account $account): Collection
    {
        return Brand::query()->where('account_id', $account->id)->orderBy('name')->get();
    }

    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        return Recommendation::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with(['brand', 'signal', 'evidenceItems.source']);
    }
}
