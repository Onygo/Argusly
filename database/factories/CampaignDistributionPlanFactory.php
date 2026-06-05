<?php

namespace Database\Factories;

use App\Enums\CampaignContentAssetType;
use App\Enums\DistributionPlanStatus;
use App\Models\Campaign;
use App\Models\CampaignDistributionPlan;
use App\Models\DistributionChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignDistributionPlan>
 */
class CampaignDistributionPlanFactory extends Factory
{
    protected $model = CampaignDistributionPlan::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'campaign_content_id' => null,
            'distribution_channel_id' => DistributionChannel::factory(),
            'asset_type' => CampaignContentAssetType::LINKEDIN_POST,
            'status' => DistributionPlanStatus::DRAFT,
            'scheduled_for' => null,
            'payload' => [],
            'planning_notes' => [],
            'result' => [],
            'last_error' => null,
        ];
    }
}
