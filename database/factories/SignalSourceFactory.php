<?php

namespace Database\Factories;

use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Models\Organization;
use App\Models\SignalSource;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalSource>
 */
class SignalSourceFactory extends Factory
{
    protected $model = SignalSource::class;

    public function definition(): array
    {
        $workspace = $this->workspace();
        $type = $this->faker->randomElement(SignalSourceType::cases());

        return [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'type' => $type,
            'name' => 'Signal source '.$this->faker->unique()->numberBetween(100, 999),
            'status' => SignalStatus::NEW,
            'config' => ['example' => true],
            'last_seen_at' => now()->subHour(),
            'last_processed_at' => now()->subMinutes(30),
            'failure_count' => 0,
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
