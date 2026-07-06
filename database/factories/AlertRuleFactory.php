<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\MonitoredSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    public function definition(): array
    {
        $source = MonitoredSource::factory()->create();

        return [
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $source->client_site_id,
            'name' => 'Page alert '.$this->faker->unique()->numberBetween(100, 999),
            'trigger' => AlertRule::TRIGGER_NEW_BRAND_PAGE,
            'conditions_json' => ['window_minutes' => 1440],
            'cooldown_minutes' => 60,
            'severity' => 'medium',
            'is_active' => true,
            'metadata_json' => ['factory' => true],
        ];
    }
}
