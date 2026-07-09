<?php

namespace Database\Factories;

use App\Models\MarketingAttribution;
use App\Models\MarketingObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingAttribution>
 */
class MarketingAttributionFactory extends Factory
{
    protected $model = MarketingAttribution::class;

    public function definition(): array
    {
        $observation = MarketingObservation::query()->first() ?? MarketingObservation::factory()->create();

        return [
            'workspace_id' => $observation->workspace_id,
            'client_site_id' => $observation->client_site_id,
            'marketing_observation_id' => $observation->id,
            'attribution_type' => 'entity',
            'attributed_type' => 'canonical_resource',
            'attributed_id' => fake()->uuid(),
            'attribution_key' => 'resource',
            'attribution_value' => fake()->word(),
            'weight' => 1,
            'confidence_score' => 0.9,
            'model_key' => 'canonical-attribution-v1',
            'source_metadata_json' => [],
            'metadata_json' => [],
        ];
    }
}
