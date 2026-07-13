<?php

namespace Database\Factories;

use App\Enums\BrandGrowthAudienceProposalType;
use App\Enums\BrandGrowthAudienceSourceType;
use App\Enums\BrandGrowthPlanReviewState;
use App\Models\BrandGrowthAudienceProposal;
use App\Models\BrandGrowthPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandGrowthAudienceProposal>
 */
class BrandGrowthAudienceProposalFactory extends Factory
{
    protected $model = BrandGrowthAudienceProposal::class;

    public function definition(): array
    {
        $plan = BrandGrowthPlan::factory()->create();

        return [
            'organization_id' => $plan->organization_id,
            'workspace_id' => $plan->workspace_id,
            'brand_growth_plan_id' => $plan->id,
            'proposal_type' => BrandGrowthAudienceProposalType::AUDIENCE,
            'source_type' => BrandGrowthAudienceSourceType::INFERRED,
            'review_state' => BrandGrowthPlanReviewState::PENDING,
            'name' => 'B2B SaaS marketing leaders',
            'role' => 'Head of Marketing',
            'industry' => 'B2B SaaS',
            'goals' => ['Increase qualified demand'],
            'pain_points' => ['Weak AI visibility'],
            'kpis' => ['Pipeline influenced'],
            'buying_committee_role' => 'Economic buyer',
            'confidence_score' => 72,
            'source_references' => [],
            'metadata_json' => ['factory' => true],
            'dedupe_hash' => hash('sha256', (string) $this->faker->uuid()),
        ];
    }

    public function forPlan(BrandGrowthPlan $plan): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $plan->organization_id,
            'workspace_id' => $plan->workspace_id,
            'brand_growth_plan_id' => $plan->id,
        ]);
    }
}
