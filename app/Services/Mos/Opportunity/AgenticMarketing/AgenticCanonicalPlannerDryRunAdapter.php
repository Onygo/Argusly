<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;

class AgenticCanonicalPlannerDryRunAdapter
{
    public function __construct(
        private readonly AgenticMarketingActionPlanner $planner,
        private readonly AgenticOpportunityActionSignatureService $signatures,
    ) {}

    /**
     * @param  array<string,mixed>  $readiness
     * @return array<int,AgenticCanonicalPlannerDryRunAction>
     */
    public function proposeForReadyRow(AgenticMarketingOpportunity $opportunity, array $readiness): array
    {
        if (($readiness['readiness_status'] ?? null) !== AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY) {
            return [];
        }

        $canonicalOpportunityId = (string) ($readiness['linked_canonical_opportunity_id'] ?? '');
        if ($canonicalOpportunityId === '') {
            return [];
        }

        return collect($this->planner->previewPlannedActions($opportunity))
            ->map(function (array $plan) use ($opportunity, $canonicalOpportunityId): AgenticCanonicalPlannerDryRunAction {
                $actionType = (string) ($plan['action_type'] ?? '');
                $signature = collect((array) $this->signatures->forLegacyOpportunity($opportunity, $actionType))
                    ->except(['source'])
                    ->all();

                return new AgenticCanonicalPlannerDryRunAction(
                    objectiveId: (string) $opportunity->objective_id,
                    legacyOpportunityId: (string) $opportunity->id,
                    canonicalOpportunityId: $canonicalOpportunityId,
                    actionType: $actionType,
                    contentId: isset($plan['content_id']) ? (string) $plan['content_id'] : null,
                    estimatedCredits: (int) ($plan['estimated_credits'] ?? 0),
                    riskLevel: (string) ($plan['risk_level'] ?? 'unknown'),
                    approvalRequired: (bool) ($plan['approval_required'] ?? false),
                    prerequisites: (array) ($plan['prerequisites'] ?? []),
                    payload: array_replace_recursive((array) ($plan['payload'] ?? []), [
                        'planning' => [
                            'canonical_planner_experiment' => [
                                'mode' => 'dry_run_only',
                                'canonical_opportunity_id' => $canonicalOpportunityId,
                                'legacy_opportunity_id' => (string) $opportunity->id,
                            ],
                        ],
                    ]),
                    signature: $signature,
                    expectedNoOp: ! (bool) data_get($plan, 'prerequisites.met', false),
                );
            })
            ->values()
            ->all();
    }
}
