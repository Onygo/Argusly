<?php

namespace Database\Factories;

use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingObservation;
use App\Models\MarketingObservationDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingObservationDimension>
 */
class MarketingObservationDimensionFactory extends Factory
{
    protected $model = MarketingObservationDimension::class;

    public function definition(): array
    {
        $observation = MarketingObservation::query()->first() ?? MarketingObservation::factory()->create();
        $definition = MarketingDimensionDefinition::query()->first()
            ?? MarketingDimensionDefinition::factory()->create();
        $value = fake()->word();
        $normalized = mb_strtolower($value);

        return [
            'marketing_observation_id' => $observation->id,
            'marketing_dimension_definition_id' => $definition->id,
            'dimension_key' => $definition->dimension_key,
            'dimension_value' => $value,
            'dimension_value_normalized' => $normalized,
            'dimension_value_hash' => hash('sha256', $normalized),
            'metadata_json' => [],
        ];
    }
}
