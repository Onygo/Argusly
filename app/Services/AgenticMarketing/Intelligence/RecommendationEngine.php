<?php

namespace App\Services\AgenticMarketing\Intelligence;

class RecommendationEngine
{
    public function __construct(
        private readonly RiskEngine $risks,
        private readonly OpportunityEngine $opportunities,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, MarketingInsight>  $insights
     * @return array<int, MarketingRecommendation>
     */
    public function generate(array $context, array $insights): array
    {
        return collect()
            ->merge($this->risks->recommendations($context, $insights))
            ->merge($this->opportunities->recommendations($context, $insights))
            ->unique(fn (MarketingRecommendation $recommendation): string => $recommendation->key)
            ->sortByDesc(fn (MarketingRecommendation $recommendation): int => $recommendation->priority)
            ->values()
            ->all();
    }
}
