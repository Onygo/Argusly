<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthPlanReviewState;
use App\Models\BrandGrowthPlan;
use App\Models\User;

class BrandGrowthPlanPromotionService
{
    public function __construct(
        private readonly BrandGrowthFindingPromotionService $findings,
        private readonly BrandGrowthAudiencePromotionService $audiences,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function promoteApprovedItems(BrandGrowthPlan $plan, User $user): array
    {
        $plan->loadMissing(['findings.opportunity', 'audienceProposals.persona', 'workspace']);

        $approvedFindings = $plan->findings
            ->filter(fn ($finding): bool => ($finding->review_state?->value ?? $finding->review_state) === BrandGrowthPlanReviewState::APPROVED->value);
        $approvedAudiences = $plan->audienceProposals
            ->filter(fn ($proposal): bool => ($proposal->review_state?->value ?? $proposal->review_state) === BrandGrowthPlanReviewState::APPROVED->value);

        $findingsPromoted = 0;
        $findingsAlreadyPromoted = 0;
        $audiencesPromoted = 0;
        $audiencesAlreadyPromoted = 0;

        foreach ($approvedFindings as $finding) {
            if ($finding->opportunity_id) {
                $findingsAlreadyPromoted++;

                continue;
            }

            $this->findings->promote($finding, $user);
            $findingsPromoted++;
        }

        foreach ($approvedAudiences as $proposal) {
            if ($proposal->persona_id) {
                $audiencesAlreadyPromoted++;

                continue;
            }

            $this->audiences->promote($proposal, $user);
            $audiencesPromoted++;
        }

        return [
            'approved_findings' => $approvedFindings->count(),
            'findings_promoted' => $findingsPromoted,
            'findings_already_promoted' => $findingsAlreadyPromoted,
            'approved_audiences' => $approvedAudiences->count(),
            'audiences_promoted' => $audiencesPromoted,
            'audiences_already_promoted' => $audiencesAlreadyPromoted,
        ];
    }
}
