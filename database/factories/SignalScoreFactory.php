<?php

namespace Database\Factories;

use App\Enums\SignalScoreType;
use App\Models\SignalEntity;
use App\Models\SignalScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalScore>
 */
class SignalScoreFactory extends Factory
{
    protected $model = SignalScore::class;

    public function definition(): array
    {
        $entity = SignalEntity::factory()->create();
        $score = $this->faker->numberBetween(40, 90);
        $previous = $this->faker->numberBetween(30, 80);

        return [
            'organization_id' => $entity->organization_id,
            'workspace_id' => $entity->workspace_id,
            'client_site_id' => $entity->client_site_id,
            'scope_type' => 'entity',
            'scope_key' => $entity->entity_key,
            'score_type' => $this->faker->randomElement(SignalScoreType::cases()),
            'score' => $score,
            'previous_score' => $previous,
            'delta' => $score - $previous,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'breakdown' => ['source' => 'factory'],
            'computed_at' => now(),
        ];
    }
}
