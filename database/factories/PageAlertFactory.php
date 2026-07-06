<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\PageAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageAlert>
 */
class PageAlertFactory extends Factory
{
    protected $model = PageAlert::class;

    public function definition(): array
    {
        $rule = AlertRule::factory()->create();

        $alertKey = hash('sha256', $rule->id.'|factory|'.$this->faker->uuid());

        return [
            'organization_id' => $rule->organization_id,
            'workspace_id' => $rule->workspace_id,
            'client_site_id' => $rule->client_site_id,
            'alert_rule_id' => $rule->id,
            'trigger' => $rule->trigger,
            'severity' => $rule->severity,
            'status' => PageAlert::STATUS_FIRED,
            'title' => 'Page Intelligence alert',
            'summary' => 'A Page Intelligence alert fired.',
            'alert_key' => $alertKey,
            'dedupe_hash' => $alertKey,
            'evidence_json' => ['factory' => true],
            'metrics_json' => ['score' => 80],
            'metadata_json' => ['factory' => true],
            'fired_at' => now(),
        ];
    }
}
