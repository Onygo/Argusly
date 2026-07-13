<?php

namespace App\Services\BrandGrowthPlanning;

class BrandGrowthAnalyzerResult
{
    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $audienceProposals
     * @param  array<int, string>  $assumptions
     * @param  array<int, string>  $missingData
     * @param  array<int, string>  $sourcesUsed
     * @param  array<int, string>  $sourcesNotAvailable
     * @param  array<int, string>  $recommendedActions
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $findings = [],
        public readonly array $audienceProposals = [],
        public readonly float $confidence = 0,
        public readonly array $assumptions = [],
        public readonly array $missingData = [],
        public readonly array $sourcesUsed = [],
        public readonly array $sourcesNotAvailable = [],
        public readonly array $recommendedActions = [],
    ) {
    }
}
