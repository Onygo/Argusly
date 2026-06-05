<?php

namespace App\Services\ContentOpportunityEngine;

class ContentOpportunityCandidate
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $topic,
        public readonly string $reasoning,
        public readonly string $angle,
        public readonly array $sourceSignals = [],
        public readonly array $relatedEntities = [],
        public readonly ?string $targetAudience = null,
        public readonly ?string $funnelStage = null,
        public readonly ?string $searchIntent = null,
        public readonly ?string $suggestedCta = null,
        public readonly ?string $suggestedSchema = null,
    ) {}
}
