<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Recommendation;
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
     * @return array{open: int, accepted: int, completed: int}
     */
    public function statisticsForTenant(Account $account, ?Brand $brand = null): array
    {
        $query = $this->tenantQuery($account, $brand);

        return [
            'open' => (clone $query)->open()->count(),
            'accepted' => (clone $query)->where('status', 'accepted')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
        ];
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
