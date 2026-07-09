<?php

namespace Database\Factories;

use App\Models\MarketingMetricDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingMetricDefinition>
 */
class MarketingMetricDefinitionFactory extends Factory
{
    protected $model = MarketingMetricDefinition::class;

    public function definition(): array
    {
        return [
            'metric_key' => 'metric_'.fake()->unique()->lexify('????'),
            'display_name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'value_type' => MarketingMetricDefinition::VALUE_TYPE_DECIMAL,
            'default_unit' => 'count',
            'aggregation' => MarketingMetricDefinition::AGGREGATION_SUM,
            'direction' => 'up',
            'is_active' => true,
            'metadata_json' => [],
        ];
    }
}
