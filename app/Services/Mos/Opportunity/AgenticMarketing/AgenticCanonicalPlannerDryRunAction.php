<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

final readonly class AgenticCanonicalPlannerDryRunAction
{
    /**
     * @param  array<string,mixed>  $prerequisites
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $signature
     */
    public function __construct(
        public string $objectiveId,
        public string $legacyOpportunityId,
        public string $canonicalOpportunityId,
        public string $actionType,
        public ?string $contentId,
        public int $estimatedCredits,
        public string $riskLevel,
        public bool $approvalRequired,
        public array $prerequisites,
        public array $payload,
        public ?array $signature,
        public bool $expectedNoOp,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'objective_id' => $this->objectiveId,
            'legacy_opportunity_id' => $this->legacyOpportunityId,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'action_type' => $this->actionType,
            'content_id' => $this->contentId,
            'estimated_credits' => $this->estimatedCredits,
            'risk_level' => $this->riskLevel,
            'approval_required' => $this->approvalRequired,
            'prerequisites' => $this->prerequisites,
            'payload' => $this->payload,
            'signature' => $this->signature,
            'expected_noop' => $this->expectedNoOp,
        ];
    }
}
