<?php

namespace App\Jobs\Campaigns;

use App\Enums\DistributionPlanStatus;
use App\Models\Campaign;
use App\Models\CampaignDistributionPlan;
use App\Models\DistributionChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PlanCampaignDistributionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly string $campaignId,
    ) {
        $this->onQueue((string) config('agentic_marketing.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'campaign-distribution-plan:'.$this->campaignId;
    }

    public function handle(): void
    {
        $campaign = Campaign::query()
            ->with(['contents', 'distributionPlans'])
            ->findOrFail($this->campaignId);

        $channelIds = collect($campaign->channel_mix ?? [])
            ->pluck('distribution_channel_id')
            ->filter()
            ->unique()
            ->values();

        if ($channelIds->isEmpty()) {
            $campaign->forceFill(['last_planned_at' => now()])->save();

            return;
        }

        $channels = DistributionChannel::query()
            ->where('workspace_id', $campaign->workspace_id)
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($campaign, $channels): void {
            foreach ($campaign->contents as $asset) {
                foreach ($channels as $channel) {
                    CampaignDistributionPlan::query()->firstOrCreate(
                        [
                            'campaign_id' => $campaign->id,
                            'campaign_content_id' => $asset->id,
                            'distribution_channel_id' => $channel->id,
                        ],
                        [
                            'asset_type' => $asset->asset_type?->value ?? $asset->asset_type,
                            'status' => $asset->scheduled_for ? DistributionPlanStatus::SCHEDULED->value : DistributionPlanStatus::DRAFT->value,
                            'scheduled_for' => $asset->scheduled_for,
                            'planning_notes' => [
                                'source' => 'campaign_distribution_planner',
                                'channel_type' => $channel->type?->value ?? $channel->type,
                            ],
                        ]
                    );
                }
            }

            $campaign->forceFill(['last_planned_at' => now()])->save();
        });
    }
}
