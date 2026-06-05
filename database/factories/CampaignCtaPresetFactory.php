<?php

namespace Database\Factories;

use App\Models\CampaignCtaPreset;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignCtaPreset>
 */
class CampaignCtaPresetFactory extends Factory
{
    protected $model = CampaignCtaPreset::class;

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
            'intent' => 'conversion',
            'label' => $this->faker->words(3, true),
            'destination_url' => $this->faker->url(),
            'description' => $this->faker->sentence(),
            'rules' => [],
            'metadata' => [],
            'is_default' => false,
        ];
    }
}
