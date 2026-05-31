<?php

namespace Database\Factories;

use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentLifecycleScore>
 */
class ContentLifecycleScoreFactory extends Factory
{
    protected $model = ContentLifecycleScore::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contentAsset = ContentAsset::factory()->create();

        return [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'status' => fake()->randomElement(ContentLifecycleScore::STATUSES),
            'health_score' => fake()->numberBetween(35, 95),
            'freshness_score' => fake()->numberBetween(35, 95),
            'performance_score' => fake()->numberBetween(35, 95),
            'visibility_score' => fake()->numberBetween(35, 95),
            'refresh_priority' => fake()->numberBetween(1, 100),
            'reason' => 'Demo lifecycle score.',
            'signals' => ['demo' => true],
            'scored_at' => now(),
        ];
    }

    public function forContentAsset(ContentAsset $contentAsset): static
    {
        return $this->state(fn () => [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
        ]);
    }
}
