<?php

namespace Database\Factories;

use App\Models\ContentAsset;
use App\Models\ContentAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentAudit>
 */
class ContentAuditFactory extends Factory
{
    protected $model = ContentAudit::class;

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
            'status' => 'completed',
            'score' => fake()->numberBetween(40, 95),
            'seo_score' => fake()->numberBetween(40, 95),
            'ai_visibility_score' => fake()->numberBetween(40, 95),
            'readability_score' => fake()->numberBetween(40, 95),
            'entity_score' => fake()->numberBetween(40, 95),
            'answer_score' => fake()->numberBetween(40, 95),
            'issues' => [],
            'recommendations' => ['Review content structure.'],
            'summary' => 'Placeholder content audit completed.',
            'audited_at' => now(),
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
