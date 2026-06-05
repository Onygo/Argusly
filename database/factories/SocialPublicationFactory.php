<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Enums\SocialPublicationStatus;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPublication>
 */
class SocialPublicationFactory extends Factory
{
    protected $model = SocialPublication::class;

    public function definition(): array
    {
        $variant = SocialPostVariant::factory()->approved()->create();

        return [
            'organization_id' => $variant->organization_id,
            'workspace_id' => $variant->workspace_id,
            'social_account_id' => SocialAccount::factory()->connected()->state([
                'organization_id' => $variant->organization_id,
                'workspace_id' => $variant->workspace_id,
            ]),
            'social_post_variant_id' => $variant->id,
            'campaign_id' => $variant->campaign_id,
            'platform' => SocialPlatform::LINKEDIN,
            'status' => SocialPublicationStatus::SCHEDULED,
            'scheduled_for' => now()->addDay(),
            'attempts' => 0,
            'payload_snapshot' => [],
            'response_snapshot' => [],
            'metadata' => [],
        ];
    }
}
