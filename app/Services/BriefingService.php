<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BriefingService
{
    public const CHANNELS = ['website', 'blog', 'linkedin', 'email', 'search', 'paid', 'pr', 'sales'];

    /**
     * @return LengthAwarePaginator<int, Briefing>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, int $perPage = 15): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Briefing::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'campaign', 'creator', 'approver'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Briefing
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Briefing::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'campaign', 'creator', 'approver'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, ?Brand $brand, User $creator, array $attributes): Briefing
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');
        $campaign = $this->campaign($account, $scopeBrand, $attributes['campaign_id'] ?? null);

        return Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'campaign_id' => $campaign?->id,
            'title' => $attributes['title'],
            'objective' => $attributes['objective'] ?? null,
            'audience' => $attributes['audience'] ?? null,
            'tone_of_voice' => $attributes['tone_of_voice'] ?? null,
            'key_message' => $attributes['key_message'] ?? null,
            'channels' => array_values($attributes['channels'] ?? []),
            'languages' => array_values($attributes['languages'] ?? []),
            'status' => $attributes['status'] ?? 'draft',
            'created_by' => $creator->id,
            'metadata' => [
                'source' => 'manual',
                'generation_ready' => true,
            ],
        ]);
    }

    /**
     * @return Collection<int, Campaign>
     */
    public function campaigns(Account $account, ?Brand $brand = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Briefing brand must belong to the account.');
        }
    }

    private function scopeBrand(Account $account, ?Brand $brand, mixed $scope): ?Brand
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $scope === 'account' ? null : $brand;
    }

    private function campaign(Account $account, ?Brand $brand, mixed $campaignId): ?Campaign
    {
        if ($campaignId === null || $campaignId === '') {
            return null;
        }

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->findOrFail((int) $campaignId);
    }
}
