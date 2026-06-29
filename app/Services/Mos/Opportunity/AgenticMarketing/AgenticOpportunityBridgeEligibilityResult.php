<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticOpportunityBridgeEligibilityResult
{
    /**
     * @param  array<int,string>  $existingLinkedCanonicalOpportunityIds
     * @param  array<int,string>  $dedupeMatchedCanonicalOpportunityCandidates
     * @param  array<int,string>  $blockedReasons
     */
    public function __construct(
        public readonly string $legacyAgenticOpportunityId,
        public readonly ?string $objectiveId,
        public readonly ?string $workspaceId,
        public readonly ?string $siteId,
        public readonly ?string $contentId,
        public readonly string $detectorKey,
        public readonly ?string $agenticType,
        public readonly string $status,
        public readonly AgenticDetectorClassification $phase3bDetectorClassification,
        public readonly AgenticCanonicalMappingResult $mappingResult,
        public readonly array $existingLinkedCanonicalOpportunityIds,
        public readonly array $dedupeMatchedCanonicalOpportunityCandidates,
        public readonly bool $duplicateBridgeRisk,
        public readonly bool $duplicateStrategicOpportunityRisk,
        public readonly int $openAgenticActionsCount,
        public readonly int $executionPipelineCount,
        public readonly int $growthAssetCount,
        public readonly int $programmaticOpportunityCount,
        public readonly int $campaignClusterMaterializationCount,
        public readonly bool $signalEligibility,
        public readonly bool $canonicalOpportunityEligibility,
        public readonly string $executionBlockerStatus,
        public readonly array $blockedReasons,
        public readonly string $eligibilityStatus,
        public readonly string $recommendedFutureAction,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'legacy_agentic_opportunity_id' => $this->legacyAgenticOpportunityId,
            'objective_id' => $this->objectiveId,
            'workspace_id' => $this->workspaceId,
            'site_id' => $this->siteId,
            'content_id' => $this->contentId,
            'detector_key' => $this->detectorKey,
            'agentic_type' => $this->agenticType,
            'status' => $this->status,
            'phase_3b_detector_classification' => $this->phase3bDetectorClassification->value,
            'mapping_result' => $this->mappingResult->toArray(),
            'existing_linked_canonical_opportunity_ids' => $this->existingLinkedCanonicalOpportunityIds,
            'dedupe_matched_canonical_opportunity_candidates' => $this->dedupeMatchedCanonicalOpportunityCandidates,
            'duplicate_bridge_risk' => $this->duplicateBridgeRisk,
            'duplicate_strategic_opportunity_risk' => $this->duplicateStrategicOpportunityRisk,
            'open_agentic_actions_count' => $this->openAgenticActionsCount,
            'execution_pipeline_count' => $this->executionPipelineCount,
            'growth_asset_count' => $this->growthAssetCount,
            'programmatic_opportunity_count' => $this->programmaticOpportunityCount,
            'campaign_cluster_materialization_count' => $this->campaignClusterMaterializationCount,
            'signal_eligibility' => $this->signalEligibility,
            'canonical_opportunity_eligibility' => $this->canonicalOpportunityEligibility,
            'execution_blocker_status' => $this->executionBlockerStatus,
            'blocked_reasons' => $this->blockedReasons,
            'eligibility_status' => $this->eligibilityStatus,
            'recommended_future_action' => $this->recommendedFutureAction,
        ];
    }
}
