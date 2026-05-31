<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\MarketingCalendarItem;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\MarketingWorkspace;
use App\Models\Recommendation;
use App\Models\SocialPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketingOsService
{
    /**
     * @return LengthAwarePaginator<int, MarketingWorkspace>
     */
    public function paginatedWorkspaces(Account $account, ?Brand $brand = null, int $perPage = 10): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return MarketingWorkspace::query()
            ->forTenant($account, $brand)
            ->with('brand')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, MarketingObjective>
     */
    public function paginatedObjectives(Account $account, ?Brand $brand = null, int $perPage = 12): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return MarketingObjective::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'campaign'])
            ->orderByRaw('end_date is null')
            ->orderBy('end_date')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createWorkspace(Account $account, ?Brand $brand, array $attributes): MarketingWorkspace
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');

        return MarketingWorkspace::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'settings' => [
                'planning_style' => 'dmt',
                'operating_layer' => 'marketing_os',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createObjective(Account $account, ?Brand $brand, array $attributes): MarketingObjective
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');
        $campaign = $this->campaign($account, $brand, $attributes['campaign_id'] ?? null);

        return MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'campaign_id' => $campaign?->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'type' => $attributes['type'],
            'status' => $attributes['status'] ?? 'active',
            'target_value' => $attributes['target_value'] ?? null,
            'current_value' => $attributes['current_value'] ?? null,
            'unit' => $attributes['unit'] ?? null,
            'start_date' => $attributes['start_date'] ?? null,
            'end_date' => $attributes['end_date'] ?? null,
            'metadata' => [
                'planning_style' => 'dmt',
                'source' => 'manual',
            ],
        ]);
    }

    /**
     * @return Collection<int, Campaign>
     */
    public function availableCampaigns(Account $account, ?Brand $brand = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->open()
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, ?Brand $brand = null, ?Campaign $campaign = null): array
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $this->ensureCampaignBelongsToScope($account, $brand, $campaign);

        $campaigns = Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->where('status', 'active')
            ->withCount(['contentAssets', 'boardItems'])
            ->orderByRaw('end_date is null')
            ->orderBy('end_date')
            ->limit(8)
            ->get();

        $taskScope = $this->scopedQuery(MarketingTask::query(), $account, $brand)
            ->when($campaign !== null, fn (Builder $query) => $query->where('campaign_id', $campaign->id))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['campaign', 'assignee', 'brand']);

        $calendar = $this->scopedQuery(MarketingCalendarItem::query(), $account, $brand)
            ->when($campaign !== null, fn (Builder $query) => $query->where('campaign_id', $campaign->id))
            ->where('start_at', '>=', now()->startOfDay())
            ->with(['campaign', 'assignee', 'brand'])
            ->orderBy('start_at')
            ->limit(10)
            ->get();

        $objectives = $this->scopedQuery(MarketingObjective::query(), $account, $brand)
            ->when($campaign !== null, fn (Builder $query) => $query->where('campaign_id', $campaign->id))
            ->with(['campaign', 'brand'])
            ->whereIn('status', ['active', 'paused'])
            ->orderByRaw('end_date is null')
            ->orderBy('end_date')
            ->limit(8)
            ->get();

        app(AgentManager::class)->ensureDefaultAgents();

        return [
            'activeCampaigns' => $campaigns,
            'upcomingTasks' => (clone $taskScope)->where(fn (Builder $query) => $query->whereNull('due_at')->orWhere('due_at', '>=', now()))->orderByRaw('due_at is null')->orderBy('due_at')->limit(8)->get(),
            'overdueTasks' => (clone $taskScope)->whereNotNull('due_at')->where('due_at', '<', now())->orderBy('due_at')->limit(8)->get(),
            'calendarPreview' => $calendar,
            'objectives' => $objectives,
            'pendingApprovals' => $this->scopedQuery(Approval::query(), $account, $brand)
                ->where('status', 'pending')
                ->with(['brand', 'subject', 'requester'])
                ->latest('requested_at')
                ->limit(8)
                ->get(),
            'latestRecommendations' => $this->scopedQuery(Recommendation::query(), $account, $brand)
                ->with(['brand', 'signal'])
                ->latest('created_at')
                ->limit(8)
                ->get(),
            'activeAgents' => Agent::query()
                ->whereIn('key', ['content', 'social', 'campaign', 'visibility', 'monitoring'])
                ->runnable()
                ->withCount(['tasks' => fn (Builder $query) => $query
                    ->where('account_id', $account->id)
                    ->when($brand !== null, fn (Builder $scope) => $scope->where('brand_id', $brand->id))])
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * @return array{workspaces: int, objectives: int, active_objectives: int, campaign_objectives: int, content_assets: int, social_posts: int, calendar_items: int, recommendations: int, approvals: int, agents: int}
     */
    public function stats(Account $account, ?Brand $brand = null): array
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $objectives = MarketingObjective::query()->forTenant($account, $brand);

        return [
            'workspaces' => MarketingWorkspace::query()->forTenant($account, $brand)->count(),
            'objectives' => (clone $objectives)->count(),
            'active_objectives' => (clone $objectives)->where('status', 'active')->count(),
            'campaign_objectives' => (clone $objectives)->whereNotNull('campaign_id')->count(),
            'content_assets' => $this->tenantCount(ContentAsset::query(), $account, $brand),
            'social_posts' => $this->tenantCount(SocialPost::query(), $account, $brand),
            'calendar_items' => $this->tenantCount(MarketingCalendarItem::query(), $account, $brand),
            'recommendations' => $this->tenantCount(Recommendation::query(), $account, $brand),
            'approvals' => $this->tenantCount(Approval::query(), $account, $brand),
            'agents' => Agent::query()->runnable()->count(),
        ];
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Marketing OS brand must belong to the account.');
        }
    }

    private function ensureCampaignBelongsToScope(Account $account, ?Brand $brand, ?Campaign $campaign): void
    {
        if ($campaign === null) {
            return;
        }

        if ($campaign->account_id !== $account->id || ($brand !== null && $campaign->brand_id !== $brand->id)) {
            throw new InvalidArgumentException('Marketing OS campaign must belong to the selected tenant scope.');
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

    private function tenantCount(Builder $query, Account $account, ?Brand $brand): int
    {
        return $this->scopedQuery($query, $account, $brand)->count();
    }

    private function scopedQuery(Builder $query, Account $account, ?Brand $brand): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }
}
