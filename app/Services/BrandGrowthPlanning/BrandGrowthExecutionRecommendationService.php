<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\OpportunityStatus;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use App\Services\OpportunityIntelligence\OpportunityExecutionPlanBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class BrandGrowthExecutionRecommendationService
{
    public function __construct(
        private readonly OpportunityExecutionPlanBuilder $builder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createForApprovedPromotedFindings(BrandGrowthPlan $plan, User $user): array
    {
        $plan->loadMissing(['findings.opportunity.activeExecutionPlans', 'workspace']);

        $approvedFindings = $plan->findings
            ->filter(fn (BrandGrowthPlanFinding $finding): bool => ($finding->review_state?->value ?? $finding->review_state) === BrandGrowthPlanReviewState::APPROVED->value)
            ->values();
        $promotedFindings = $approvedFindings
            ->filter(fn (BrandGrowthPlanFinding $finding): bool => $finding->opportunity instanceof Opportunity)
            ->values();

        $created = 0;
        $existing = 0;
        $markedReviewing = 0;
        $skipped = 0;
        $createdPlanIds = [];
        $existingPlanIds = [];

        $promotedFindings
            ->groupBy(fn (BrandGrowthPlanFinding $finding): string => (string) $finding->opportunity_id)
            ->each(function (Collection $findings) use ($plan, $user, &$created, &$existing, &$markedReviewing, &$skipped, &$createdPlanIds, &$existingPlanIds): void {
                /** @var BrandGrowthPlanFinding|null $primaryFinding */
                $primaryFinding = $findings->first();
                $opportunity = $primaryFinding?->opportunity;

                if (! $opportunity instanceof Opportunity) {
                    $skipped++;

                    return;
                }

                $opportunity->loadMissing(['activeExecutionPlans', 'signals']);
                $existingPlan = $opportunity->activeExecutionPlans()->first();

                if ($existingPlan instanceof OpportunityExecutionPlan) {
                    $this->attachBrandGrowthSource($existingPlan, $plan, $findings, false);
                    $existing++;
                    $existingPlanIds[] = (string) $existingPlan->id;

                    return;
                }

                if ($this->markReviewingIfNeeded($opportunity, $plan, $findings, $user)) {
                    $markedReviewing++;
                }

                try {
                    $executionPlan = $this->builder->build($opportunity->refresh(), $user);
                } catch (AuthorizationException) {
                    $skipped++;

                    return;
                }

                $this->attachBrandGrowthSource($executionPlan, $plan, $findings, true);
                $created++;
                $createdPlanIds[] = (string) $executionPlan->id;
            });

        return [
            'approved_findings' => $approvedFindings->count(),
            'promoted_findings' => $promotedFindings->count(),
            'unique_promoted_opportunities' => $promotedFindings->pluck('opportunity_id')->filter()->unique()->count(),
            'missing_promoted_findings' => max(0, $approvedFindings->count() - $promotedFindings->count()),
            'execution_recommendations_created' => $created,
            'execution_recommendations_existing' => $existing,
            'opportunities_marked_reviewing' => $markedReviewing,
            'skipped_opportunities' => $skipped,
            'created_execution_plan_ids' => array_values(array_unique($createdPlanIds)),
            'existing_execution_plan_ids' => array_values(array_unique($existingPlanIds)),
        ];
    }

    private function markReviewingIfNeeded(Opportunity $opportunity, BrandGrowthPlan $plan, Collection $findings, User $user): bool
    {
        $status = (string) ($opportunity->status?->value ?? $opportunity->status);

        if ($status !== OpportunityStatus::OPEN->value) {
            return false;
        }

        $metadata = $opportunity->metadata ?? [];
        data_set($metadata, 'brand_growth_planning.reviewed_from_approved_finding', true);
        data_set($metadata, 'brand_growth_planning.brand_growth_plan_id', (string) $plan->id);
        data_set($metadata, 'brand_growth_planning.brand_growth_plan_finding_ids', $this->findingIds($findings));
        data_set($metadata, 'brand_growth_planning.reviewed_by_user_id', $user->id);
        data_set($metadata, 'brand_growth_planning.reviewed_at', now()->toIso8601String());

        $opportunity->forceFill([
            'status' => OpportunityStatus::REVIEWING->value,
            'metadata' => $metadata,
        ])->save();

        return true;
    }

    private function attachBrandGrowthSource(OpportunityExecutionPlan $executionPlan, BrandGrowthPlan $plan, Collection $findings, bool $createdFromPlan): OpportunityExecutionPlan
    {
        $metadata = $executionPlan->metadata ?? [];
        data_set($metadata, 'brand_growth_planning.source', 'brand_growth_plan');
        data_set($metadata, 'brand_growth_planning.brand_growth_plan_id', (string) $plan->id);
        data_set($metadata, 'brand_growth_planning.brand_growth_plan_version', $plan->version);
        data_set($metadata, 'brand_growth_planning.brand_growth_plan_finding_ids', $this->findingIds($findings));
        data_set($metadata, 'brand_growth_planning.created_from_brand_growth_plan', $createdFromPlan);

        $sourceEvidence = $executionPlan->source_evidence ?? [];
        data_set($sourceEvidence, 'brand_growth_plan', [
            'id' => (string) $plan->id,
            'version' => $plan->version,
            'business_objective' => $plan->business_objective,
            'brand_objective' => $plan->brand_objective,
            'findings' => $findings
                ->map(fn (BrandGrowthPlanFinding $finding): array => [
                    'id' => (string) $finding->id,
                    'type' => $finding->type?->value ?? $finding->type,
                    'title' => $finding->title,
                    'description' => $finding->description,
                    'rationale' => $finding->rationale,
                    'recommended_action' => $finding->recommended_action,
                    'affected_audience' => $finding->affected_audience,
                    'affected_industry' => $finding->affected_industry,
                    'affected_funnel_stage' => $finding->affected_funnel_stage,
                    'impact_score' => (float) $finding->impact_score,
                    'urgency_score' => (float) $finding->urgency_score,
                    'confidence_score' => (float) $finding->confidence_score,
                    'source_references' => $finding->source_references ?? [],
                    'source_summary' => $finding->source_summary ?? [],
                ])
                ->values()
                ->all(),
        ]);

        $executionPlan->forceFill([
            'metadata' => $metadata,
            'source_evidence' => $sourceEvidence,
        ])->save();

        return $executionPlan->refresh();
    }

    /**
     * @return array<int, string>
     */
    private function findingIds(Collection $findings): array
    {
        return $findings
            ->pluck('id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
