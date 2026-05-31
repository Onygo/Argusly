<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\Topic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignService
{
    /**
     * @param  array{status?: string|null, type?: string|null}  $filters
     * @return LengthAwarePaginator<int, Campaign>
     */
    public function paginatedForTenant(Account $account, Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('metadata->campaign_type', $type))
            ->withCount(['contentAssets', 'topics', 'signals'])
            ->orderByRaw('start_date is null')
            ->latest('start_date')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, Brand $brand, array $attributes): Campaign
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $this->validateStatus($attributes['status'] ?? 'draft');

        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $attributes['name'],
            'slug' => $this->uniqueSlug($account, $brand, $attributes['slug'] ?? null, $attributes['name']),
            'description' => $attributes['description'] ?? null,
            'objective' => $attributes['objective'] ?? null,
            'status' => $attributes['status'] ?? 'draft',
            'start_date' => $attributes['start_date'] ?? null,
            'end_date' => $attributes['end_date'] ?? null,
            'metadata' => $this->metadata($attributes),
        ]);

        $this->syncRelations($campaign, $attributes);
        app(MarketingCalendarService::class)->syncCampaign($campaign->refresh());

        if ($campaign->status === 'active') {
            app(DomainEventService::class)->recordForSubject('CampaignActivated', $campaign, null, [
                'name' => $campaign->name,
                'objective' => $campaign->objective,
                'campaign_type' => $campaign->metadata['campaign_type'] ?? null,
            ], $campaign->updated_at);
        }

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Campaign $campaign, array $attributes): Campaign
    {
        $this->validateStatus($attributes['status']);
        $wasActive = $campaign->status === 'active';

        $campaign->update([
            'name' => $attributes['name'],
            'slug' => $this->uniqueSlug($campaign->account, $campaign->brand, $attributes['slug'] ?? null, $attributes['name'], $campaign),
            'description' => $attributes['description'] ?? null,
            'objective' => $attributes['objective'] ?? null,
            'status' => $attributes['status'],
            'start_date' => $attributes['start_date'] ?? null,
            'end_date' => $attributes['end_date'] ?? null,
            'metadata' => $this->metadata($attributes, $campaign->metadata ?? []),
        ]);

        $this->syncRelations($campaign, $attributes);

        $campaign = $campaign->refresh();
        app(MarketingCalendarService::class)->syncCampaign($campaign);

        if (! $wasActive && $campaign->status === 'active') {
            app(DomainEventService::class)->recordForSubject('CampaignActivated', $campaign, null, [
                'name' => $campaign->name,
                'objective' => $campaign->objective,
                'campaign_type' => $campaign->metadata['campaign_type'] ?? null,
            ], $campaign->updated_at);
        }

        return $campaign;
    }

    public function findForTenant(Account $account, Brand $brand, int $id): Campaign
    {
        return $this->tenantQuery($account, $brand)
            ->with([
                'brand',
                'contentAssets',
                'topics',
                'signals',
            ])
            ->withCount(['contentAssets', 'topics', 'signals'])
            ->findOrFail($id);
    }

    public function deleteForTenant(Account $account, Brand $brand, Campaign $campaign): void
    {
        if ($campaign->account_id !== $account->id || $campaign->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Campaign does not belong to this tenant context.');
        }

        $campaign->delete();
    }

    /**
     * @return Collection<int, ContentAsset>
     */
    public function availableAssets(Account $account, Brand $brand): Collection
    {
        return ContentAsset::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->orderBy('title')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Topic>
     */
    public function availableTopics(Account $account, Brand $brand): Collection
    {
        return Topic::query()
            ->where(function (Builder $scope) use ($account, $brand): void {
                $scope->whereNull('account_id')
                    ->orWhere(function (Builder $accountScope) use ($account, $brand): void {
                        $accountScope->where('account_id', $account->id)
                            ->where(fn (Builder $brandScope) => $brandScope
                                ->whereNull('brand_id')
                                ->orWhere('brand_id', $brand->id));
                    });
            })
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    public function availableSignals(Account $account, Brand $brand): Collection
    {
        return IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $scope) => $scope
                ->whereNull('brand_id')
                ->orWhere('brand_id', $brand->id))
            ->latest('detected_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array{scheduled: int, active: int, completed: int, total: int}
     */
    public function dashboardStats(Account $account, Brand $brand): array
    {
        $query = $this->tenantQuery($account, $brand);

        return [
            'scheduled' => (clone $query)->where('status', 'planned')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * @return Collection<int, array{date: string, label: string, description: string, type: string}>
     */
    public function timeline(Campaign $campaign): Collection
    {
        return collect([
            $campaign->start_date ? [
                'date' => $campaign->start_date->format('Y-m-d'),
                'label' => 'Campaign starts',
                'description' => $campaign->name,
                'type' => 'campaign',
            ] : null,
            $campaign->end_date ? [
                'date' => $campaign->end_date->format('Y-m-d'),
                'label' => 'Campaign ends',
                'description' => $campaign->name,
                'type' => 'campaign',
            ] : null,
            ...$campaign->contentAssets->map(fn (ContentAsset $asset) => [
                'date' => $asset->published_at?->format('Y-m-d') ?? $asset->created_at->format('Y-m-d'),
                'label' => $asset->published_at ? 'Content published' : 'Content created',
                'description' => $asset->title,
                'type' => 'content',
            ])->all(),
            ...$campaign->signals->map(fn (IntelligenceSignal $signal) => [
                'date' => $signal->detected_at?->format('Y-m-d') ?? $signal->created_at->format('Y-m-d'),
                'label' => 'Signal detected',
                'description' => $signal->title,
                'type' => 'signal',
            ])->all(),
        ])
            ->filter()
            ->sortBy('date')
            ->values();
    }

    /**
     * @return Builder<Campaign>
     */
    private function tenantQuery(Account $account, Brand $brand): Builder
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRelations(Campaign $campaign, array $attributes): void
    {
        $assetIds = $this->validAssetIds($campaign, $attributes['content_asset_ids'] ?? []);
        $topicIds = $this->validTopicIds($campaign, $attributes['topic_ids'] ?? []);
        $signalIds = $this->validSignalIds($campaign, $attributes['signal_ids'] ?? []);

        $campaign->contentAssets()->sync($assetIds);
        $campaign->topics()->sync($topicIds);
        $campaign->signals()->sync($signalIds);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function validAssetIds(Campaign $campaign, array $ids): array
    {
        return ContentAsset::query()
            ->where('account_id', $campaign->account_id)
            ->where('brand_id', $campaign->brand_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function validTopicIds(Campaign $campaign, array $ids): array
    {
        return Topic::query()
            ->whereIn('id', $ids)
            ->where(function (Builder $scope) use ($campaign): void {
                $scope->whereNull('account_id')
                    ->orWhere(function (Builder $accountScope) use ($campaign): void {
                        $accountScope->where('account_id', $campaign->account_id)
                            ->where(fn (Builder $brandScope) => $brandScope
                                ->whereNull('brand_id')
                                ->orWhere('brand_id', $campaign->brand_id));
                    });
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function validSignalIds(Campaign $campaign, array $ids): array
    {
        return IntelligenceSignal::query()
            ->where('account_id', $campaign->account_id)
            ->where(fn (Builder $scope) => $scope
                ->whereNull('brand_id')
                ->orWhere('brand_id', $campaign->brand_id))
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Campaign brand must belong to the campaign account.');
        }
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, Campaign::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid campaign status [{$status}].");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function metadata(array $attributes, array $existing = []): array
    {
        return [
            ...$existing,
            'campaign_type' => $attributes['campaign_type'] ?? ($existing['campaign_type'] ?? 'content'),
            'prepared_for' => [
                'social_campaigns',
                'influencer_campaigns',
                'content_campaigns',
                'pr_campaigns',
            ],
        ];
    }

    private function uniqueSlug(Account $account, Brand $brand, ?string $slug, string $name, ?Campaign $ignore = null): string
    {
        $base = Str::slug($slug ?: $name);
        $candidate = $base;
        $suffix = 2;

        while (Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('slug', $candidate)
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->id))
            ->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
