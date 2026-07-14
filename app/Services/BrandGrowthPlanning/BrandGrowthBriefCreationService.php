<?php

namespace App\Services\BrandGrowthPlanning;

use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use App\Models\Brief;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use App\Services\OpportunityIntelligence\ExecutionPlanBriefService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use RuntimeException;

class BrandGrowthBriefCreationService
{
    public function __construct(
        private readonly ExecutionPlanBriefService $briefs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createForApprovedExecutionRecommendations(BrandGrowthPlan $plan, User $user): array
    {
        $executionPlans = $this->executionPlansForPlan($plan);

        $created = 0;
        $existing = 0;
        $ineligible = 0;
        $skipped = 0;
        $briefIds = [];

        foreach ($executionPlans as $executionPlan) {
            $status = (string) $executionPlan->status;

            if (! in_array($status, [OpportunityExecutionPlan::STATUS_APPROVED, OpportunityExecutionPlan::STATUS_PLANNED], true)) {
                $ineligible++;

                continue;
            }

            $briefIdBefore = (string) data_get($executionPlan->metadata, 'brief_id', '');

            try {
                $brief = $this->briefs->createBrief($executionPlan, $user);
            } catch (AuthorizationException | RuntimeException) {
                $skipped++;

                continue;
            }

            if ($brief instanceof Brief) {
                $briefIds[] = (string) $brief->id;
            }

            $briefIdBefore !== '' && $briefIdBefore === (string) $brief->id ? $existing++ : $created++;
        }

        return [
            'execution_recommendations' => $executionPlans->count(),
            'briefs_created' => $created,
            'briefs_existing' => $existing,
            'execution_recommendations_needing_approval' => $ineligible,
            'skipped_execution_recommendations' => $skipped,
            'brief_ids' => array_values(array_unique($briefIds)),
        ];
    }

    /**
     * @return Collection<int, OpportunityExecutionPlan>
     */
    public function executionPlansForPlan(BrandGrowthPlan $plan): Collection
    {
        $plan->loadMissing(['findings.opportunity.activeExecutionPlans']);

        return $plan->findings
            ->filter(fn (BrandGrowthPlanFinding $finding): bool => $finding->opportunity instanceof Opportunity)
            ->flatMap(fn (BrandGrowthPlanFinding $finding): Collection => $finding->opportunity->activeExecutionPlans)
            ->filter(fn (OpportunityExecutionPlan $executionPlan): bool => (string) data_get($executionPlan->metadata, 'brand_growth_planning.brand_growth_plan_id') === (string) $plan->id
                || (string) data_get($executionPlan->source_evidence, 'brand_growth_plan.id') === (string) $plan->id)
            ->unique(fn (OpportunityExecutionPlan $executionPlan): string => (string) $executionPlan->id)
            ->values();
    }
}
