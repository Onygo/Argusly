<?php

namespace App\Services\Mos\Opportunity;

class ContentOpportunityBriefHandoffPlan
{
    /**
     * @param  array<int, mixed>  $sourceEvidence
     * @param  array<int, mixed>  $recommendedActions
     * @param  array<string, mixed>  $targetContext
     * @param  array<string, mixed>  $legacyRequiredFields
     * @param  array<int, string>  $missingFields
     */
    public function __construct(
        public readonly ?string $canonicalOpportunityId,
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly string $proposedActionType,
        public readonly string $proposedSourceSignature,
        public readonly string $proposedCtaLabel,
        public readonly string $proposedCtaRoute,
        public readonly string $proposedSourceLink,
        public readonly ?string $recommendedBriefTitle,
        public readonly ?string $primaryKeyword,
        public readonly ?string $audience,
        public readonly ?string $funnelStage,
        public readonly ?string $intent,
        public readonly array $sourceEvidence,
        public readonly array $recommendedActions,
        public readonly array $targetContext,
        public readonly array $legacyRequiredFields,
        public readonly array $missingFields,
        public readonly bool $safe,
        public readonly string $safetyStatus,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'proposed_action_type' => $this->proposedActionType,
            'proposed_source_signature' => $this->proposedSourceSignature,
            'proposed_cta_label' => $this->proposedCtaLabel,
            'proposed_cta_route' => $this->proposedCtaRoute,
            'proposed_source_link' => $this->proposedSourceLink,
            'recommended_brief_title' => $this->recommendedBriefTitle,
            'primary_keyword' => $this->primaryKeyword,
            'audience' => $this->audience,
            'funnel_stage' => $this->funnelStage,
            'intent' => $this->intent,
            'source_evidence' => $this->sourceEvidence,
            'recommended_actions' => $this->recommendedActions,
            'target_context' => $this->targetContext,
            'legacy_required_fields' => $this->legacyRequiredFields,
            'missing_fields' => $this->missingFields,
            'safe' => $this->safe,
            'safety_status' => $this->safetyStatus,
            'reason' => $this->reason,
        ];
    }
}
