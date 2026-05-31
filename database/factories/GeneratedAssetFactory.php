<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\GeneratedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedAsset>
 */
class GeneratedAssetFactory extends Factory
{
    protected $model = GeneratedAsset::class;

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
            'type' => fake()->randomElement(GeneratedAsset::TYPES),
            'status' => fake()->randomElement(GeneratedAsset::STATUSES),
            'prompt' => fake()->sentence(12),
            'input_payload' => ['demo' => true],
            'output_payload' => null,
            'title' => fake()->sentence(5),
            'body' => fake()->paragraphs(2, true),
            'language' => 'en',
            'locale' => 'en_US',
            'model' => 'static-foundation-v1',
            'provider' => 'argusly_fake',
            'cost_credits' => 0,
            'created_by' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function forContentAsset(ContentAsset $contentAsset): static
    {
        return $this->state(fn () => [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
        ]);
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
            'content_asset_id' => null,
        ]);
    }
}
