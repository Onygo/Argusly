<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketingTaskService
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const RELATED_TYPES = [
        'content_asset' => ContentAsset::class,
        'social_post' => SocialPost::class,
        'recommendation' => Recommendation::class,
        'approval' => Approval::class,
        'campaign' => Campaign::class,
    ];

    /**
     * @return LengthAwarePaginator<int, MarketingTask>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, int $perPage = 20): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return MarketingTask::query()
            ->forTenant($account, $brand)
            ->with(['assignee', 'creator', 'campaign', 'objective', 'related'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, ?Brand $brand, User $creator, array $attributes): MarketingTask
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');
        $related = $this->related($account, $scopeBrand, $attributes['related_type'] ?? null, $attributes['related_id'] ?? null);
        $campaign = $this->campaign($account, $scopeBrand, $attributes['campaign_id'] ?? null, $related);
        $objective = $this->objective($account, $scopeBrand, $attributes['marketing_objective_id'] ?? null);

        $task = MarketingTask::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'campaign_id' => $campaign?->id,
            'marketing_objective_id' => $objective?->id,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'backlog',
            'priority' => $attributes['priority'] ?? 'medium',
            'assigned_to' => $attributes['assigned_to'] ?? null,
            'created_by' => $creator->id,
            'due_at' => $attributes['due_at'] ?? null,
            'completed_at' => ($attributes['status'] ?? null) === 'completed' ? now() : null,
            'metadata' => [
                'source' => 'manual',
            ],
        ]);

        app(MarketingCalendarService::class)->syncTask($task->refresh());

        return $task;
    }

    public function createFromRecommendation(Account $account, ?Brand $brand, Recommendation $recommendation, User $creator, array $attributes = []): MarketingTask
    {
        if ($recommendation->account_id !== $account->id) {
            throw new InvalidArgumentException('Recommendation must belong to the same account.');
        }

        if ($brand !== null && $recommendation->brand_id !== null && $recommendation->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Recommendation must belong to the current brand scope.');
        }

        $task = MarketingTask::query()->create([
            'account_id' => $recommendation->account_id,
            'brand_id' => $recommendation->brand_id,
            'campaign_id' => $attributes['campaign_id'] ?? null,
            'marketing_objective_id' => $attributes['marketing_objective_id'] ?? null,
            'related_type' => $recommendation->getMorphClass(),
            'related_id' => $recommendation->id,
            'title' => $attributes['title'] ?? $recommendation->recommended_action ?? $recommendation->title,
            'description' => $attributes['description'] ?? $recommendation->summary,
            'status' => $attributes['status'] ?? 'todo',
            'priority' => $attributes['priority'] ?? $this->priorityFromRecommendation($recommendation),
            'assigned_to' => $attributes['assigned_to'] ?? null,
            'created_by' => $creator->id,
            'due_at' => $attributes['due_at'] ?? null,
            'metadata' => [
                'source' => 'recommendation',
                'recommendation_id' => $recommendation->id,
                'impact_score' => $recommendation->impact_score,
                'confidence_score' => $recommendation->confidence_score,
            ],
        ]);

        app(MarketingCalendarService::class)->syncTask($task->refresh());

        return $task;
    }

    /**
     * @return Collection<int, User>
     */
    public function assignableUsers(Account $account, ?Brand $brand = null): Collection
    {
        return User::query()
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('account_id', $account->id)
                ->where('status', 'active'))
            ->when($brand !== null, fn (Builder $query) => $query->whereHas('brandMemberships', fn (Builder $brandQuery) => $brandQuery
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('status', 'active')))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, class-string<Model>>
     */
    public function relatedTypes(): array
    {
        return self::RELATED_TYPES;
    }

    /**
     * @return array{total: int, open: int, review: int, completed: int, urgent: int}
     */
    public function stats(Account $account, ?Brand $brand = null): array
    {
        $query = MarketingTask::query()->forTenant($account, $brand);

        return [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'review' => (clone $query)->where('status', 'waiting_review')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'urgent' => (clone $query)->where('priority', 'urgent')->count(),
        ];
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Marketing task brand must belong to the account.');
        }
    }

    private function scopeBrand(Account $account, ?Brand $brand, mixed $scope): ?Brand
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $scope === 'account' ? null : $brand;
    }

    private function related(Account $account, ?Brand $brand, mixed $type, mixed $id): ?Model
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        $class = self::RELATED_TYPES[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unsupported marketing task related type.');
        }

        $related = $class::query()->where('account_id', $account->id)->findOrFail((int) $id);
        $relatedBrandId = $related->getAttribute('brand_id');

        if ($relatedBrandId !== null && ($brand === null || (int) $relatedBrandId !== (int) $brand->id)) {
            throw new InvalidArgumentException('Related record must belong to the task brand scope.');
        }

        return $related;
    }

    private function campaign(Account $account, ?Brand $brand, mixed $campaignId, ?Model $related): ?Campaign
    {
        if ($related instanceof Campaign) {
            return $related;
        }

        if ($campaignId === null || $campaignId === '') {
            return null;
        }

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where('brand_id', $brand->id))
            ->findOrFail((int) $campaignId);
    }

    private function objective(Account $account, ?Brand $brand, mixed $objectiveId): ?MarketingObjective
    {
        if ($objectiveId === null || $objectiveId === '') {
            return null;
        }

        return MarketingObjective::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand?->id)
            ->findOrFail((int) $objectiveId);
    }

    private function priorityFromRecommendation(Recommendation $recommendation): string
    {
        return match (true) {
            $recommendation->impact_score >= 85 => 'urgent',
            $recommendation->impact_score >= 70 => 'high',
            $recommendation->impact_score >= 40 => 'medium',
            default => 'low',
        };
    }
}
