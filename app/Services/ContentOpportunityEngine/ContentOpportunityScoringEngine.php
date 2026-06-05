<?php

namespace App\Services\ContentOpportunityEngine;

use App\DTO\QueryIntent\QueryIntentClassificationData;

class ContentOpportunityScoringEngine
{
    /**
     * @return array<string,mixed>
     */
    public function score(ContentOpportunityCandidate $candidate, QueryIntentClassificationData $intent): array
    {
        $typeImpact = match ($candidate->type) {
            'bofu_page', 'comparison_page', 'feature_page' => 88.0,
            'campaign_cluster' => 84.0,
            'industry_page', 'use_case_page', 'implementation_guide' => 76.0,
            'answer_block_opportunity', 'faq_opportunity' => 68.0,
            'refresh_opportunity' => 62.0,
            default => 58.0,
        };
        $sourceBoost = $this->sourceBoost($candidate->sourceSignals);
        $urgency = min(100.0, max($intent->urgencyScore, 42.0 + $sourceBoost));
        $businessValue = min(100.0, ($intent->businessImpactScore * 0.62) + ($typeImpact * 0.3) + ($sourceBoost * 0.08));
        $confidence = min(96.0, max(48.0, $intent->intentConfidence + ($sourceBoost / 4)));
        $priority = round(($businessValue * 0.42) + ($urgency * 0.28) + ($confidence * 0.2) + ($typeImpact * 0.1), 2);

        return [
            'expected_impact' => match (true) {
                $businessValue >= 82 => 'strategic',
                $businessValue >= 68 => 'high',
                $businessValue >= 45 => 'medium',
                default => 'low',
            },
            'confidence_score' => round($confidence, 2),
            'urgency_score' => round($urgency, 2),
            'business_value_score' => round($businessValue, 2),
            'priority_score' => min(100.0, $priority),
            'score_breakdown' => [
                'type_impact' => $typeImpact,
                'source_boost' => $sourceBoost,
                'intent_priority' => $intent->priorityScore,
            ],
        ];
    }

    private function sourceBoost(array $signals): float
    {
        return match ((string) ($signals['source'] ?? '')) {
            'competitor_intelligence' => 22.0,
            'competitor_topic_signal' => 18.0,
            'company_intelligence' => 12.0,
            'content_inventory' => 10.0,
            default => 6.0,
        };
    }
}
