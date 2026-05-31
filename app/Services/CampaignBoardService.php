<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignStage;
use App\Models\ContentAsset;
use App\Models\MarketingTask;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CampaignBoardService
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const RELATED_TYPES = [
        'content_asset' => ContentAsset::class,
        'social_post' => SocialPost::class,
        'marketing_task' => MarketingTask::class,
        'recommendation' => Recommendation::class,
    ];

    /**
     * @return Collection<int, CampaignStage>
     */
    public function stages(Campaign $campaign): Collection
    {
        $this->ensureDefaultStages($campaign);

        return CampaignStage::query()
            ->where('campaign_id', $campaign->id)
            ->with(['items.assignee', 'items.related'])
            ->orderBy('position')
            ->get();
    }

    public function ensureDefaultStages(Campaign $campaign): void
    {
        foreach (CampaignStage::DEFAULT_STAGES as $position => $name) {
            CampaignStage::query()->firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'name' => $name,
                ],
                [
                    'account_id' => $campaign->account_id,
                    'brand_id' => $campaign->brand_id,
                    'position' => $position + 1,
                    'status' => 'active',
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createItem(Campaign $campaign, array $attributes): CampaignItem
    {
        $stage = $this->stage($campaign, $attributes['campaign_stage_id'] ?? null);
        $related = $this->related($campaign, $attributes['related_type'] ?? null, $attributes['related_id'] ?? null);

        return CampaignItem::query()->create([
            'account_id' => $campaign->account_id,
            'brand_id' => $campaign->brand_id,
            'campaign_id' => $campaign->id,
            'campaign_stage_id' => $stage?->id,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'position' => $this->nextPosition($campaign, $stage),
            'assigned_to' => $attributes['assigned_to'] ?? null,
            'due_at' => $attributes['due_at'] ?? null,
            'metadata' => [
                'source' => 'manual',
            ],
        ]);
    }

    public function moveItem(Campaign $campaign, CampaignItem $item, string $direction): CampaignItem
    {
        if ($item->campaign_id !== $campaign->id || $item->account_id !== $campaign->account_id || $item->brand_id !== $campaign->brand_id) {
            throw new InvalidArgumentException('Campaign board item does not belong to this campaign.');
        }

        $stages = CampaignStage::query()->where('campaign_id', $campaign->id)->orderBy('position')->pluck('id')->values();
        $currentIndex = $stages->search($item->campaign_stage_id);

        if ($currentIndex === false) {
            return $item;
        }

        $targetIndex = $direction === 'right'
            ? min($currentIndex + 1, $stages->count() - 1)
            : max($currentIndex - 1, 0);

        $targetStageId = $stages[$targetIndex] ?? $item->campaign_stage_id;

        $item->update([
            'campaign_stage_id' => $targetStageId,
            'position' => $this->nextPosition($campaign, CampaignStage::query()->find($targetStageId)),
        ]);

        return $item->refresh();
    }

    /**
     * @return Collection<int, User>
     */
    public function assignableUsers(Campaign $campaign): Collection
    {
        return User::query()
            ->whereHas('brandMemberships', fn (Builder $query) => $query
                ->where('account_id', $campaign->account_id)
                ->where('brand_id', $campaign->brand_id)
                ->where('status', 'active'))
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

    public function findForTenant(Account $account, Brand $brand, int $id): Campaign
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Campaign board brand must belong to account.');
        }

        return Campaign::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->findOrFail($id);
    }

    private function stage(Campaign $campaign, mixed $stageId): ?CampaignStage
    {
        if ($stageId === null || $stageId === '') {
            return null;
        }

        return CampaignStage::query()
            ->where('account_id', $campaign->account_id)
            ->where('brand_id', $campaign->brand_id)
            ->where('campaign_id', $campaign->id)
            ->findOrFail((int) $stageId);
    }

    private function related(Campaign $campaign, mixed $type, mixed $id): ?Model
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        $class = self::RELATED_TYPES[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('Unsupported campaign board item related type.');
        }

        /** @var Model $related */
        $related = $class::query()->where('account_id', $campaign->account_id)->findOrFail((int) $id);
        $relatedBrandId = $related->getAttribute('brand_id');

        if ($relatedBrandId !== null && (int) $relatedBrandId !== (int) $campaign->brand_id) {
            throw new InvalidArgumentException('Related record must belong to the campaign brand.');
        }

        return $related;
    }

    private function nextPosition(Campaign $campaign, ?CampaignStage $stage): int
    {
        return ((int) CampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('campaign_stage_id', $stage?->id)
            ->max('position')) + 1;
    }
}
