<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;

class DetectedOpportunity
{
    public function __construct(
        public readonly string $title,
        public readonly AgenticMarketingOpportunityType $type,
        public readonly int $priorityScore,
        public readonly array $payload,
        public readonly ?string $contentId = null,
    ) {
    }

    public function attributes(string $objectiveId): array
    {
        return [
            'objective_id' => $objectiveId,
            'content_id' => $this->contentId,
            'title' => $this->title,
            'type' => $this->type->value,
            'priority_score' => max(1, min(100, $this->priorityScore)),
            'status' => AgenticMarketingOpportunityStatus::Open->value,
            'payload' => $this->payload,
        ];
    }
}
