<?php

namespace Database\Factories;

use App\Models\CampaignToneProfile;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignToneProfile>
 */
class CampaignToneProfileFactory extends Factory
{
    protected $model = CampaignToneProfile::class;

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
            'locale' => 'en',
            'summary' => $this->faker->sentence(),
            'voice_attributes' => [],
            'rules' => [],
            'examples' => [],
            'is_default' => false,
        ];
    }
}
