<?php

namespace Database\Factories;

use App\Models\AgenticMarketingWorkflowOverride;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgenticMarketingWorkflowOverride>
 */
class AgenticMarketingWorkflowOverrideFactory extends Factory
{
    protected $model = AgenticMarketingWorkflowOverride::class;

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
            'workspace_id' => (string) $workspace->id,
            'override_type' => AgenticMarketingWorkflowOverride::TYPE_FORCE_APPROVAL,
            'reason' => 'Factory governance override.',
            'payload' => [],
            'is_active' => true,
            'expires_at' => null,
        ];
    }
}
