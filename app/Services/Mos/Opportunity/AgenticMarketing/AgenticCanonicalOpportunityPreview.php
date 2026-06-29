<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticCanonicalOpportunityPreview
{
    /**
     * @param  array<int,string>  $recommendedActions
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $sourceSignalSummary
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $summary,
        public readonly ?string $category,
        public readonly ?string $type,
        public readonly ?int $organizationId,
        public readonly ?string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly ?string $contentId,
        public readonly ?string $objectiveId,
        public readonly float $priority,
        public readonly float $confidence,
        public readonly float $impact,
        public readonly float $effort,
        public readonly ?float $businessValue,
        public readonly array $recommendedActions,
        public readonly array $evidence,
        public readonly array $sourceSignalSummary,
        public readonly array $metadata,
        public readonly string $dedupeKey,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'category' => $this->category,
            'type' => $this->type,
            'organization_id' => $this->organizationId,
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'content_id' => $this->contentId,
            'objective_id' => $this->objectiveId,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'impact' => $this->impact,
            'effort' => $this->effort,
            'business_value' => $this->businessValue,
            'recommended_actions' => $this->recommendedActions,
            'evidence' => $this->evidence,
            'source_signal_summary' => $this->sourceSignalSummary,
            'metadata' => $this->metadata,
            'dedupe_key' => $this->dedupeKey,
        ];
    }
}
