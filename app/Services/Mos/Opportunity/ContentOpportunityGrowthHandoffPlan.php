<?php

namespace App\Services\Mos\Opportunity;

class ContentOpportunityGrowthHandoffPlan
{
    /**
     * @param  array<int, string>  $canonicalOpportunityIds
     * @param  array<int, array<string, mixed>>  $growthAssetReferences
     * @param  array<int, array<string, mixed>>  $programmaticOpportunityReferences
     * @param  array<int, array<string, mixed>>  $autopilotQueueReferences
     * @param  array<int, string>  $duplicateExecutionRisks
     * @param  array<int, string>  $missingFields
     * @param  array<int, string>  $recommendedFutureReferenceStrategy
     */
    public function __construct(
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $canonicalOpportunityId,
        public readonly array $canonicalOpportunityIds,
        public readonly array $growthAssetReferences,
        public readonly array $programmaticOpportunityReferences,
        public readonly array $autopilotQueueReferences,
        public readonly bool $safe,
        public readonly array $duplicateExecutionRisks,
        public readonly array $missingFields,
        public readonly array $recommendedFutureReferenceStrategy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'canonical_opportunity_ids' => $this->canonicalOpportunityIds,
            'growth_asset_references' => $this->growthAssetReferences,
            'programmatic_opportunity_references' => $this->programmaticOpportunityReferences,
            'autopilot_queue_references' => $this->autopilotQueueReferences,
            'safe' => $this->safe,
            'duplicate_execution_risks' => $this->duplicateExecutionRisks,
            'missing_fields' => $this->missingFields,
            'recommended_future_reference_strategy' => $this->recommendedFutureReferenceStrategy,
        ];
    }
}
