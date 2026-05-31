<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntelligenceSignal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntelligenceSignal>
 */
class IntelligenceSignalFactory extends Factory
{
    protected $model = IntelligenceSignal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(IntelligenceSignal::TYPES);

        return [
            'account_id' => Account::query()->create([
                'name' => fake()->company(),
                'slug' => Str::slug(fake()->unique()->company()),
            ])->id,
            'brand_id' => null,
            'source' => fake()->randomElement(['demo.monitor', 'demo.integration', 'demo.audit']),
            'type' => $type,
            'title' => $this->titleFor($type),
            'summary' => fake()->sentence(18),
            'impact_score' => fake()->numberBetween(35, 95),
            'confidence_score' => fake()->numberBetween(60, 98),
            'status' => fake()->randomElement(IntelligenceSignal::STATUSES),
            'recommended_action' => fake()->sentence(12),
            'payload' => [
                'demo' => true,
                'surface' => fake()->randomElement(['search', 'social', 'content', 'technical']),
            ],
            'detected_at' => fake()->dateTimeBetween('-14 days'),
            'resolved_at' => null,
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
        ]);
    }

    private function titleFor(string $type): string
    {
        return match ($type) {
            'visibility_change' => 'Visibility changed across priority queries',
            'content_opportunity' => 'Content opportunity detected',
            'competitor_movement' => 'Competitor movement spotted',
            'social_opportunity' => 'Social opportunity available',
            'technical_issue' => 'Technical issue may affect discovery',
            'agent_recommendation' => 'Agent recommendation ready for review',
            'integration_event' => 'Integration event needs attention',
            default => 'Intelligence signal detected',
        };
    }
}
