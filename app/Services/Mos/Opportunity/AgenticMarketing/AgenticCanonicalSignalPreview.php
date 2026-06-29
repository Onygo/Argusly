<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticCanonicalSignalPreview
{
    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly ?int $organizationId,
        public readonly ?string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly ?string $contentId,
        public readonly ?string $objectiveId,
        public readonly string $source,
        public readonly string $detectorKey,
        public readonly ?string $opportunityType,
        public readonly ?string $topic,
        public readonly ?string $category,
        public readonly float $signalStrength,
        public readonly float $confidence,
        public readonly float $priority,
        public readonly array $metrics,
        public readonly array $evidence,
        public readonly array $metadata,
        public readonly ?string $sourceModel,
        public readonly ?string $sourceId,
        public readonly string $dedupeKey,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'content_id' => $this->contentId,
            'objective_id' => $this->objectiveId,
            'source' => $this->source,
            'detector_key' => $this->detectorKey,
            'opportunity_type' => $this->opportunityType,
            'topic' => $this->topic,
            'category' => $this->category,
            'signal_strength' => $this->signalStrength,
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'metrics' => $this->metrics,
            'evidence' => $this->evidence,
            'metadata' => $this->metadata,
            'source_model' => $this->sourceModel,
            'source_id' => $this->sourceId,
            'dedupe_key' => $this->dedupeKey,
        ];
    }
}
