<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MarketingCalendarItem;
use App\Models\PublishingAction;
use App\Models\SocialPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MarketingCalendarService
{
    /**
     * @param  array{type?: string|null, status?: string|null, mode?: string|null, starts?: mixed, ends?: mixed}  $filters
     * @return LengthAwarePaginator<int, MarketingCalendarItem>
     */
    public function paginatedForTenant(Account $account, Brand $brand, array $filters = [], int $perPage = 40): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->whereBetween('start_at', $this->range($filters['mode'] ?? 'month', $filters['starts'] ?? null, $filters['ends'] ?? null))
            ->with(['campaign', 'assignee'])
            ->orderBy('start_at')
            ->paginate($perPage)
            ->withQueryString();
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

    /**
     * @return Collection<int, MarketingCalendarItem>
     */
    public function upcoming(Account $account, Brand $brand, int $limit = 12): Collection
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
    private function tenantQuery(Account $account, Brand $brand): Builder
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Calendar brand must belong to the account.');
        }

        return MarketingCalendarItem::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id);
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
}
