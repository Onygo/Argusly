<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;

class OpportunitySignalPayload
{
    public function __construct(
        public readonly OpportunitySignalSource $source,
        public readonly ?OpportunityCategory $category,
        public readonly ?string $topic,
        public readonly ?string $entity,
        public readonly float $signalStrength,
        public readonly float $confidence,
        public readonly array $metrics = [],
        public readonly array $evidence = [],
        public readonly array $metadata = [],
        public readonly ?string $clientSiteId = null,
        public readonly ?string $contentId = null,
        public readonly ?string $contentClusterId = null,
        public readonly ?string $campaignId = null,
        public readonly ?\DateTimeInterface $observedAt = null,
    ) {}
}
