<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Models\Campaign;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPostVariant>
 */
class SocialPostVariantFactory extends Factory
{
    protected $model = SocialPostVariant::class;

    public function definition(): array
    {
        $campaign = Campaign::factory()->create();

        return [
            'organization_id' => $campaign->organization_id,
            'workspace_id' => $campaign->workspace_id,
            'campaign_id' => $campaign->id,
            'social_account_id' => SocialAccount::factory()->state([
                'organization_id' => $campaign->organization_id,
                'workspace_id' => $campaign->workspace_id,
            ]),
            'platform' => SocialPlatform::LINKEDIN,
            'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
            'status' => SocialPostVariantStatus::DRAFT,
            'variant_number' => 1,
            'hook' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'hashtags' => ['PublishLayer'],
            'mentions' => [],
            'media_refs' => [],
            'generation_prompt_context' => [],
            'generation_result' => [],
            'metadata' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => SocialPostVariantStatus::APPROVED,
            'approved_at' => now(),
        ]);
    }
}
