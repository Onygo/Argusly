<?php

namespace App\DTO\LinkIntelligence;

class LinkScore
{
    /**
     * @param array<int, string> $sharedEntities
     * @param array<int, string> $reasons
     */
    public function __construct(
        public readonly bool $isEligible,
        public readonly float $similarityScore,
        public readonly int $sharedPrimaryCount,
        public readonly int $sharedSecondaryCount,
        public readonly float $intentMatchScore,
        public readonly float $audienceOverlapScore,
        public readonly array $sharedEntities,
        public readonly array $reasons,
    ) {}
}
