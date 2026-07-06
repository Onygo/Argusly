<?php

namespace Database\Factories;

use App\Models\MonitoredSource;
use App\Models\SerpQuerySet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerpQuerySet>
 */
class SerpQuerySetFactory extends Factory
{
    protected $model = SerpQuerySet::class;

    public function definition(): array
    {
        $source = MonitoredSource::factory()->create(['source_type' => 'serp']);

        return [
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $source->client_site_id,
            'name' => 'SERP query set '.$this->faker->unique()->numberBetween(100, 999),
            'description' => 'Factory SERP tracking query set.',
            'locale' => 'en_US',
            'country' => 'US',
            'device' => 'desktop',
            'search_engine' => 'google',
            'provider_key' => 'manual',
            'cadence' => 'manual',
            'status' => SerpQuerySet::STATUS_ACTIVE,
            'metadata_json' => ['factory' => true],
        ];
    }
}
