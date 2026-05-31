<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Topic;
use App\Models\TopicCluster;
use App\Models\TopicRelationship;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TopicIntelligenceService
{
    /**
     * @param  array{status?: string|null, scope?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Topic>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(($filters['scope'] ?? null) === 'brand', fn (Builder $query) => $brand
                ? $query->where('brand_id', $brand->id)
                : $query->whereRaw('1 = 0'))
            ->when(($filters['scope'] ?? null) === 'account', fn (Builder $query) => $query->whereNull('brand_id')->where('account_id', $account->id))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->withCount(['childRelationships', 'parentRelationships', 'clusters'])
            ->orderByRaw('brand_id is null')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, status?: string|null, brand_scoped?: mixed, priority?: int|null, importance_score?: numeric-string|float|int|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function create(Account $account, ?Brand $brand, array $attributes): Topic
    {
        $brandScoped = $brand !== null && (bool) ($attributes['brand_scoped'] ?? true);
        $topicBrand = $brandScoped ? $brand : null;
        $status = $attributes['status'] ?? 'active';

        if (! in_array($status, Topic::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid topic status [{$status}].");
        }

        if ($topicBrand !== null) {
            $this->ensureBrandBelongsToAccount($account, $topicBrand);
        }

        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $topicBrand?->id,
            'name' => $attributes['name'],
            'slug' => ($attributes['slug'] ?? null) ?: Str::slug($attributes['name']),
            'description' => $attributes['description'] ?? null,
            'status' => $status,
            'metadata' => $attributes['metadata'] ?? ['prepared_for' => $this->preparedFor()],
        ]);

        if ($brand !== null) {
            $this->syncBrandTopic($topic, $brand, $attributes);
        }

        return $topic;
    }

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, status?: string|null, priority?: int|null, importance_score?: numeric-string|float|int|null}  $attributes
     */
    public function update(Topic $topic, ?Brand $brand, array $attributes): Topic
    {
        if (! in_array($attributes['status'], Topic::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid topic status [{$attributes['status']}].");
        }

        $topic->update([
            'name' => $attributes['name'],
            'slug' => ($attributes['slug'] ?? null) ?: Str::slug($attributes['name']),
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'],
        ]);

        if ($brand !== null) {
            $this->syncBrandTopic($topic, $brand, $attributes);
        }

        return $topic;
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Topic
    {
        return $this->tenantQuery($account, $brand)
            ->whereKey($id)
            ->with([
                'brand',
                'brands',
                'clusters',
                'childRelationships.childTopic',
                'parentRelationships.parentTopic',
            ])
            ->firstOrFail();
    }

    public function deleteForTenant(Account $account, ?Brand $brand, Topic $topic): void
    {
        $this->assertTopicInTenant($account, $brand, $topic);
        $topic->delete();
    }

    /**
     * @return Collection<int, TopicCluster>
     */
    public function clustersForTenant(Account $account, Brand $brand): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return TopicCluster::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->withCount('topics')
            ->orderBy('name')
            ->get();
    }

    public function findClusterForTenant(Account $account, Brand $brand, int $id): TopicCluster
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return TopicCluster::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with('topics')
            ->withCount('topics')
            ->findOrFail($id);
    }

    /**
     * @param  array{name: string, description?: string|null, topic_ids?: array<int, int>}  $attributes
     */
    public function createCluster(Account $account, Brand $brand, array $attributes): TopicCluster
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $cluster = TopicCluster::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
        ]);

        $this->syncClusterTopics($cluster, $account, $brand, $attributes['topic_ids'] ?? []);

        return $cluster;
    }

    /**
     * @param  array{name: string, description?: string|null, topic_ids?: array<int, int>}  $attributes
     */
    public function updateCluster(TopicCluster $cluster, Account $account, Brand $brand, array $attributes): TopicCluster
    {
        if ($cluster->account_id !== $account->id || $cluster->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Topic cluster does not belong to this tenant context.');
        }

        $cluster->update([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
        ]);

        $this->syncClusterTopics($cluster, $account, $brand, $attributes['topic_ids'] ?? []);

        return $cluster;
    }

    /**
     * @param  array{parent_topic_id: int, child_topic_id: int, relationship_type: string}  $attributes
     */
    public function createRelationship(Account $account, ?Brand $brand, array $attributes): TopicRelationship
    {
        if (! in_array($attributes['relationship_type'], TopicRelationship::TYPES, true)) {
            throw new InvalidArgumentException("Invalid topic relationship type [{$attributes['relationship_type']}].");
        }

        $parent = $this->findForTenant($account, $brand, $attributes['parent_topic_id']);
        $child = $this->findForTenant($account, $brand, $attributes['child_topic_id']);

        return TopicRelationship::query()->firstOrCreate([
            'parent_topic_id' => $parent->id,
            'child_topic_id' => $child->id,
            'relationship_type' => $attributes['relationship_type'],
        ]);
    }

    /**
     * @return Collection<int, Topic>
     */
    public function topTopics(Account $account, ?Brand $brand, int $limit = 6): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->active()
            ->withCount(['clusters', 'childRelationships', 'parentRelationships'])
            ->when($brand !== null, fn (Builder $query) => $query->with([
                'brands' => fn ($brandQuery) => $brandQuery->where('brands.id', $brand->id),
            ]))
            ->get()
            ->sortByDesc(function (Topic $topic) use ($brand): float {
                $brandWeight = $brand ? (float) ($topic->brands->first()?->pivot?->importance_score ?? 0) : 0;

                return $brandWeight + ($topic->clusters_count * 5) + $topic->child_relationships_count + $topic->parent_relationships_count;
            })
            ->take($limit)
            ->values();
    }

    /**
     * @return Builder<Topic>
     */
    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        return Topic::query()
            ->where(function (Builder $scope) use ($account, $brand): void {
                $scope->whereNull('account_id')
                    ->orWhere(function (Builder $accountScope) use ($account, $brand): void {
                        $accountScope->where('account_id', $account->id)
                            ->where(function (Builder $brandScope) use ($brand): void {
                                $brandScope->whereNull('brand_id')
                                    ->when($brand !== null, fn (Builder $query) => $query->orWhere('brand_id', $brand->id));
                            });
                    });
            });
    }

    /**
     * @param  array{priority?: int|null, importance_score?: numeric-string|float|int|null}  $attributes
     */
    private function syncBrandTopic(Topic $topic, Brand $brand, array $attributes): void
    {
        if ($topic->account_id !== null && $topic->account_id !== $brand->account_id) {
            throw new InvalidArgumentException('Brand topic must belong to the same account.');
        }

        $topic->brands()->syncWithoutDetaching([
            $brand->id => [
                'priority' => max(0, (int) ($attributes['priority'] ?? 0)),
                'importance_score' => $attributes['importance_score'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $topicIds
     */
    private function syncClusterTopics(TopicCluster $cluster, Account $account, Brand $brand, array $topicIds): void
    {
        $allowedTopicIds = $this->tenantQuery($account, $brand)
            ->whereIn('id', $topicIds)
            ->pluck('id')
            ->all();

        $sync = [];
        foreach (array_values(array_unique($allowedTopicIds)) as $position => $topicId) {
            $sync[$topicId] = ['position' => $position + 1];
        }

        $cluster->topics()->sync($sync);
    }

    private function assertTopicInTenant(Account $account, ?Brand $brand, Topic $topic): void
    {
        $exists = $this->tenantQuery($account, $brand)->whereKey($topic->id)->exists();

        if (! $exists || $topic->account_id === null) {
            throw new InvalidArgumentException('Topic does not belong to this tenant context.');
        }
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Topic brand must belong to the topic account.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function preparedFor(): array
    {
        return [
            'content_assets',
            'visibility_checks',
            'competitors',
            'mentions',
            'recommendations',
            'agents',
        ];
    }
}
