<?php

namespace Database\Factories;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Models\Campaign;
use App\Models\CampaignContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignContent>
 */
class CampaignContentFactory extends Factory
{
    protected $model = CampaignContent::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'content_id' => null,
            'source_content_id' => null,
            'asset_type' => CampaignContentAssetType::ARTICLE,
            'status' => 'planned',
            'approval_status' => CampaignApprovalStatus::NOT_REQUIRED,
            'sequence_order' => $this->faker->numberBetween(1, 20),
            'working_title' => $this->faker->sentence(5),
            'target_locale' => 'en',
            'scheduled_for' => null,
            'brief' => [],
            'channel_requirements' => [],
            'ai_generation_context' => [],
            'optimization_notes' => [],
            'internal_linking_targets' => [],
            'metadata' => [],
        ];
    }
}
