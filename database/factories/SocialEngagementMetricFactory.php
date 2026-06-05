<?php

namespace Database\Factories;

use App\Models\SocialEngagementMetric;
use App\Models\SocialPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialEngagementMetric>
 */
class SocialEngagementMetricFactory extends Factory
{
    protected $model = SocialEngagementMetric::class;

    public function definition(): array
    {
        $publication = SocialPublication::factory()->create();

        return [
            'workspace_id' => $publication->workspace_id,
            'social_account_id' => $publication->social_account_id,
            'social_publication_id' => $publication->id,
            'platform' => $publication->platform,
            'measured_at' => now(),
            'impressions' => $this->faker->numberBetween(100, 5000),
            'reach' => $this->faker->numberBetween(80, 4000),
            'likes' => $this->faker->numberBetween(0, 200),
            'comments' => $this->faker->numberBetween(0, 30),
            'shares' => $this->faker->numberBetween(0, 25),
            'clicks' => $this->faker->numberBetween(0, 100),
            'follows' => $this->faker->numberBetween(0, 20),
            'engagement_rate' => $this->faker->randomFloat(4, 0, 1),
            'raw_metrics' => [],
        ];
    }
}
