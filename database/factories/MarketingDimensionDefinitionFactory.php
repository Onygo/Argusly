<?php

namespace Database\Factories;

use App\Models\MarketingDimensionDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingDimensionDefinition>
 */
class MarketingDimensionDefinitionFactory extends Factory
{
    protected $model = MarketingDimensionDefinition::class;

    public function definition(): array
    {
        return [
            'dimension_key' => 'dimension_'.fake()->unique()->lexify('????'),
            'display_name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'value_type' => MarketingDimensionDefinition::VALUE_TYPE_STRING,
            'is_active' => true,
            'metadata_json' => [],
        ];
    }
}
