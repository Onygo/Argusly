<?php

namespace Database\Factories;

use App\Enums\DistributionChannelType;
use App\Models\DistributionChannel;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DistributionChannel>
 */
class DistributionChannelFactory extends Factory
{
    protected $model = DistributionChannel::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'factory-org'],
            ['name' => 'Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace'],
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace']
        );

        return [
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => $this->faker->unique()->words(2, true),
            'type' => DistributionChannelType::LINKEDIN,
            'provider' => 'linkedin',
            'status' => DistributionChannel::STATUS_ACTIVE,
            'environment' => 'production',
            'capabilities' => [],
            'planning_rules' => [],
            'credentials_ref' => [],
            'metadata' => [],
        ];
    }
}
