<?php

namespace Database\Factories;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

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

        $name = $this->faker->unique()->words(3, true);

        return [
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'objective' => $this->faker->sentence(),
            'status' => CampaignStatus::DRAFT,
            'approval_status' => CampaignApprovalStatus::NOT_REQUIRED,
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addMonth()->toDateString(),
            'audience' => [],
            'goals' => [],
            'kpis' => [],
            'channel_mix' => [],
            'ai_planning_context' => [],
            'optimization_signals' => [],
            'internal_linking_strategy' => [],
            'metadata' => [],
        ];
    }
}
