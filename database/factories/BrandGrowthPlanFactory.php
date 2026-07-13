<?php

namespace Database\Factories;

use App\Enums\BrandGrowthPlanStatus;
use App\Models\BrandGrowthPlan;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandGrowthPlan>
 */
class BrandGrowthPlanFactory extends Factory
{
    protected $model = BrandGrowthPlan::class;

    public function definition(): array
    {
        $workspace = $this->workspace();

        return [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'status' => BrandGrowthPlanStatus::DRAFT,
            'version' => 1,
            'planning_horizon' => 'next_90_days',
            'business_objective' => 'Grow qualified demand in priority markets.',
            'brand_objective' => 'Make the brand more visible, credible, relevant, and memorable.',
            'generated_at' => now(),
            'source_data_cutoff_at' => now(),
            'confidence_score' => 68,
            'confidence_summary' => ['average_confidence' => 68],
            'assumptions' => [],
            'missing_information' => [],
            'context_snapshot' => [],
            'recommended_primary_audiences' => ['Marketing leaders'],
            'recommended_secondary_audiences' => [],
            'priority_industries' => ['B2B SaaS'],
            'buying_committee_roles' => ['Economic buyer'],
            'positioning_observations' => [],
            'messaging_priorities' => [],
            'authority_priorities' => [],
            'evidence_priorities' => [],
            'content_priorities' => [],
            'campaign_themes' => [],
            'channel_recommendations' => [],
            'kpi_recommendations' => [],
            'top_prioritized_actions' => [],
            'generated_by_metadata' => ['factory' => true],
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
        ]);
    }

    private function workspace(): Workspace
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'brand-growth-planning-factory-org'],
            ['name' => 'Brand Growth Planning Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        return Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Brand Growth Planning Factory Workspace'],
            ['display_name' => 'Brand Growth Planning Factory Workspace']
        );
    }
}
