<?php

namespace Database\Factories;

use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ContentAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnswerBlock>
 */
class AnswerBlockFactory extends Factory
{
    protected $model = AnswerBlock::class;

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
            'question' => fake()->sentence(8, true),
            'answer' => fake()->paragraph(3),
            'type' => fake()->randomElement(AnswerBlock::TYPES),
            'status' => fake()->randomElement(AnswerBlock::STATUSES),
            'language' => 'en',
            'position' => fake()->numberBetween(1, 10),
            'metadata' => ['demo' => true],
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
            'content_asset_id' => null,
        ]);
    }

    public function forContentAsset(ContentAsset $contentAsset): static
    {
        return $this->state(fn () => [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
        ]);
    }
}
