<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MarketingCalendarItem;
use App\Models\MarketingTask;
use App\Models\PublishingAction;
use App\Models\SocialPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketingCalendarService
{
    /**
     * @param  array{brand_id?: mixed, campaign_id?: mixed, type?: string|null, status?: string|null, assigned_to?: mixed, mode?: string|null, starts?: mixed, ends?: mixed}  $filters
     * @return LengthAwarePaginator<int, MarketingCalendarItem>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 40): LengthAwarePaginator
    {
        return $this->filteredQuery($account, $brand, $filters)
            ->whereBetween('start_at', $this->range($filters['mode'] ?? 'month', $filters['starts'] ?? null, $filters['ends'] ?? null))
            ->orderBy('start_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{brand_id?: mixed, campaign_id?: mixed, type?: string|null, status?: string|null, assigned_to?: mixed, mode?: string|null, starts?: mixed, ends?: mixed}  $filters
     * @return Collection<int, MarketingCalendarItem>
     */
    public function itemsForTenant(Account $account, ?Brand $brand, array $filters = []): Collection
    {
        return $this->filteredQuery($account, $brand, $filters)
            ->whereBetween('start_at', $this->range($filters['mode'] ?? 'month', $filters['starts'] ?? null, $filters['ends'] ?? null))
            ->orderBy('start_at')
            ->get();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): MarketingCalendarItem
    {
        return $this->tenantQuery($account, $brand)
            ->with(['brand', 'campaign', 'assignee', 'related'])
            ->findOrFail($id);
    }

    public function syncSocialPost(SocialPost $post): ?MarketingCalendarItem
    {
        if ($post->scheduled_at === null) {
            return null;
        }

        return $this->upsertForRelated($post, [
            'account_id' => $post->account_id,
            'brand_id' => $post->brand_id,
            'campaign_id' => $post->campaign_id,
            'title' => 'Social post: '.str($post->post_text)->limit(72),
            'description' => $post->socialProfile?->display_name,
            'type' => 'social_post',
            'status' => $this->socialPostStatus($post),
            'start_at' => $post->scheduled_at,
            'end_at' => null,
            'assigned_to' => $post->created_by,
            'metadata' => [
                'provider' => $post->provider,
                'social_profile_id' => $post->social_profile_id,
                'language' => $post->language,
            ],
        ]);
    }

    public function syncPublishingAction(PublishingAction $action): ?MarketingCalendarItem
    {
        if ($action->scheduled_at === null) {
            return null;
        }

        $asset = $action->contentAsset;

        return $this->upsertForRelated($action, [
            'account_id' => $action->account_id,
            'brand_id' => $action->brand_id,
            'campaign_id' => $asset?->campaigns()->where('campaigns.brand_id', $action->brand_id)->value('campaigns.id'),
            'title' => 'Scheduled content: '.($asset?->title ?? 'Content asset'),
            'description' => $action->action,
            'type' => 'content_asset',
            'status' => $this->publishingActionStatus($action),
            'start_at' => $action->scheduled_at,
            'end_at' => null,
            'assigned_to' => $action->created_by,
            'metadata' => [
                'content_asset_id' => $action->content_asset_id,
                'publishing_action_id' => $action->id,
                'language' => $action->language,
                'locale' => $action->locale,
            ],
        ]);
    }

    public function syncCampaign(Campaign $campaign): ?MarketingCalendarItem
    {
        if ($campaign->start_date === null) {
            return null;
        }

        return $this->upsertForRelated($campaign, [
            'account_id' => $campaign->account_id,
            'brand_id' => $campaign->brand_id,
            'campaign_id' => $campaign->id,
            'title' => $campaign->name,
            'description' => $campaign->objective ?: $campaign->description,
            'type' => 'campaign_task',
            'status' => $this->campaignStatus($campaign),
            'start_at' => $campaign->start_date->startOfDay(),
            'end_at' => $campaign->end_date?->endOfDay(),
            'assigned_to' => null,
            'metadata' => [
                'campaign_type' => $campaign->metadata['campaign_type'] ?? null,
                'campaign_status' => $campaign->status,
            ],
        ]);
    }

    public function syncTask(MarketingTask $task): ?MarketingCalendarItem
    {
        if ($task->due_at === null) {
            MarketingCalendarItem::query()
                ->where('related_type', $task->getMorphClass())
                ->where('related_id', $task->id)
                ->delete();

            return null;
        }

        return $this->upsertForRelated($task, [
            'account_id' => $task->account_id,
            'brand_id' => $task->brand_id,
            'campaign_id' => $task->campaign_id,
            'title' => 'Task: '.$task->title,
            'description' => $task->description,
            'type' => 'marketing_task',
            'status' => $this->taskStatus($task),
            'start_at' => $task->due_at,
            'end_at' => null,
            'assigned_to' => $task->assigned_to,
            'metadata' => [
                'marketing_task_id' => $task->id,
                'priority' => $task->priority,
                'task_status' => $task->status,
                'objective_id' => $task->marketing_objective_id,
            ],
        ]);
    }

    public function syncApproval(Approval $approval): ?MarketingCalendarItem
    {
        if ($approval->requested_at === null) {
            return null;
        }

        return $this->upsertForRelated($approval, [
            'account_id' => $approval->account_id,
            'brand_id' => $approval->brand_id,
            'campaign_id' => null,
            'title' => 'Approval: '.class_basename($approval->subject_type),
            'description' => $approval->notes,
            'type' => 'approval',
            'status' => $this->approvalStatus($approval),
            'start_at' => $approval->requested_at,
            'end_at' => null,
            'assigned_to' => $approval->requested_by,
            'metadata' => [
                'approval_id' => $approval->id,
                'approval_status' => $approval->status,
                'subject_type' => $approval->subject_type,
                'subject_id' => $approval->subject_id,
            ],
        ]);
    }

    /**
     * @return Collection<int, MarketingCalendarItem>
     */
    public function upcoming(Account $account, ?Brand $brand, int $limit = 12): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->where('start_at', '>=', now()->startOfDay())
            ->with('campaign')
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<MarketingCalendarItem>
     */
    /**
     * @param  array{brand_id?: mixed, campaign_id?: mixed, type?: string|null, status?: string|null, assigned_to?: mixed}  $filters
     * @return Builder<MarketingCalendarItem>
     */
    private function filteredQuery(Account $account, ?Brand $brand, array $filters): Builder
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $campaignId) => $query->where('campaign_id', (int) $campaignId))
            ->when($filters['assigned_to'] ?? null, fn (Builder $query, mixed $assignedTo) => $query->where('assigned_to', (int) $assignedTo))
            ->with(['brand', 'campaign', 'assignee', 'related']);
    }

    /**
     * @return Builder<MarketingCalendarItem>
     */
    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Calendar brand must belong to the account.');
        }

        return MarketingCalendarItem::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
            );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertForRelated(Model $related, array $attributes): MarketingCalendarItem
    {
        return MarketingCalendarItem::query()->updateOrCreate(
            [
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->getKey(),
            ],
            $attributes,
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(string $mode, mixed $starts = null, mixed $ends = null): array
    {
        if ($starts || $ends) {
            return [
                $starts ? Carbon::parse($starts)->startOfDay() : now()->startOfMonth(),
                $ends ? Carbon::parse($ends)->endOfDay() : now()->endOfMonth(),
            ];
        }

        return $mode === 'week'
            ? [now()->startOfWeek(), now()->endOfWeek()]
            : [now()->startOfMonth(), now()->endOfMonth()];
    }

    private function socialPostStatus(SocialPost $post): string
    {
        return match ($post->status) {
            'published' => 'completed',
            'cancelled' => 'cancelled',
            'queued', 'publishing' => 'in_progress',
            default => 'scheduled',
        };
    }

    private function publishingActionStatus(PublishingAction $action): string
    {
        return match ($action->status) {
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'processing' => 'in_progress',
            default => 'scheduled',
        };
    }

    private function campaignStatus(Campaign $campaign): string
    {
        return match ($campaign->status) {
            'active' => 'in_progress',
            'completed', 'archived' => 'completed',
            'paused' => 'cancelled',
            default => 'planned',
        };
    }

    private function taskStatus(MarketingTask $task): string
    {
        return match ($task->status) {
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'in_progress', 'waiting_review' => 'in_progress',
            default => 'planned',
        };
    }

    private function approvalStatus(Approval $approval): string
    {
        return match ($approval->status) {
            'approved' => 'completed',
            'rejected', 'cancelled' => 'cancelled',
            default => 'in_progress',
        };
    }
}
