<?php

namespace Database\Factories;

use App\Enums\BrandGrowthFindingType;
use App\Enums\BrandGrowthPlanReviewState;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandGrowthPlanFinding>
 */
class BrandGrowthPlanFindingFactory extends Factory
{
    protected $model = BrandGrowthPlanFinding::class;

    public function definition(): array
    {
        $plan = BrandGrowthPlan::factory()->create();

        return [
            'organization_id' => $plan->organization_id,
            'workspace_id' => $plan->workspace_id,
            'brand_growth_plan_id' => $plan->id,
            'type' => BrandGrowthFindingType::CONTENT_GAP,
            'status' => BrandGrowthPlanFinding::STATUS_ACTIVE,
            'review_state' => BrandGrowthPlanReviewState::PENDING,
            'title' => 'Proof-led content is missing',
            'description' => 'Owned content does not show enough evidence-led assets.',
            'rationale' => 'Credibility improves when buyers can inspect proof.',
            'impact_score' => 80,
            'urgency_score' => 64,
            'confidence_score' => 72,
            'recommended_action' => 'Create one proof-led decision-stage asset.',
            'source_references' => [],
            'source_summary' => [],
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
