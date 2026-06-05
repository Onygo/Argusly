<?php

namespace Database\Factories;

use App\Models\AgenticMarketingWorkflowRule;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgenticMarketingWorkflowRule>
 */
class AgenticMarketingWorkflowRuleFactory extends Factory
{
    protected $model = AgenticMarketingWorkflowRule::class;

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
            'name' => 'Governed '.$this->faker->words(2, true).' workflow',
            'trigger_type' => 'signal_monitor',
            'status' => AgenticMarketingWorkflowRule::STATUS_ACTIVE,
            'minimum_confidence_score' => 70,
            'maximum_actions_per_run' => 10,
            'generate_campaign_proposals' => true,
            'generate_content_drafts' => true,
            'schedule_distribution_drafts' => true,
            'auto_queue_approved_actions' => false,
            'requires_human_approval' => true,
            'allowed_action_types' => [],
            'signal_filters' => [],
            'policy' => ['never_auto_publish_by_default' => true],
        ];
    }
}
