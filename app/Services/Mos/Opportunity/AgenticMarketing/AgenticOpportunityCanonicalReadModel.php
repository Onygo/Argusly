<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticOpportunityCanonicalReadModel
{
    /**
     * @param  array<int, mixed>  $recommendedActions
     * @param  array<int, mixed>  $evidence
     * @param  array<string, mixed>  $executionStateSummary
     * @param  array<string, mixed>  $sourceSignalSummary
     * @param  array<string, string>  $provenance
     * @param  array<string, bool>  $migrationReadiness
     * @param  array<int, string>  $blockedReasons
     * @param  array<string, mixed>  $legacyFields
     */
    public function __construct(
        public readonly string $legacyAgenticOpportunityId,
        public readonly ?string $canonicalOpportunityId,
        public readonly ?string $objectiveId,
        public readonly ?string $workspaceId,
        public readonly ?string $siteId,
        public readonly ?string $contentId,
        public readonly ?string $title,
        public readonly ?string $summary,
        public readonly ?string $category,
        public readonly ?string $status,
        public readonly ?float $priorityScore,
        public readonly ?float $confidenceScore,
        public readonly ?float $impactScore,
        public readonly ?float $effortScore,
        public readonly ?float $urgencyScore,
        public readonly array $recommendedActions,
        public readonly array $evidence,
        public readonly ?string $detectorKey,
        public readonly ?string $agenticType,
        public readonly array $executionStateSummary,
        public readonly array $sourceSignalSummary,
        public readonly array $provenance,
        public readonly array $migrationReadiness,
        public readonly array $blockedReasons,
        public readonly array $legacyFields = [],
    ) {}

    public function isCanonicalEnriched(): bool
    {
        return $this->canonicalOpportunityId !== null
            && in_array('canonical', $this->provenance, true);
    }

    public function hasFallbacks(): bool
    {
        return in_array('legacy', $this->provenance, true);
    }
}
