<?php

namespace Database\Factories;

use App\Enums\SignalEntityType;
use App\Models\Organization;
use App\Models\SignalEntity;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalEntity>
 */
class SignalEntityFactory extends Factory
{
    protected $model = SignalEntity::class;

    public function definition(): array
    {
        $workspace = $this->workspace();
        $type = $this->faker->randomElement(SignalEntityType::cases());
        $name = $this->faker->randomElement(['Argusly', 'CompetitorOS', 'AI visibility', 'Content operations']);

        return [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'entity_type' => $type,
            'entity_key' => strtolower(str_replace(' ', '-', $name)).'-'.$this->faker->unique()->numberBetween(100, 999),
            'entity_name' => $name,
            'first_seen_at' => now()->subDays(7),
            'last_seen_at' => now(),
            'mention_count' => $this->faker->numberBetween(1, 20),
            'signal_count' => $this->faker->numberBetween(1, 12),
            'metadata' => ['source' => 'factory'],
        ];
    }

    private function workspace(): Workspace
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'signal-factory-org'],
            ['name' => 'Signal Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        return Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Signal Factory Workspace'],
            ['display_name' => 'Signal Factory Workspace']
        );
    }
}
