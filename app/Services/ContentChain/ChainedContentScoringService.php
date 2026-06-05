<?php

namespace App\Services\ContentChain;

class ChainedContentScoringService
{
    /**
     * @param array<string,mixed> $signals
     * @return array{score:float,breakdown:array<string,float>}
     */
    public function scoreSource(array $signals): array
    {
        $weights = (array) config('content_chain.suggestions.scoring.weights', []);
        $ceiling = max(1, (int) config('content_chain.suggestions.scoring.page_views_ceiling', 1000));
        $recencyWindow = max(1, (int) config('content_chain.suggestions.scoring.recency_window_days', 120));

        $pageViews = min(100.0, (((int) ($signals['page_views'] ?? 0)) / $ceiling) * 100);
        $engagementRate = max(0.0, min(100.0, (float) ($signals['engagement_rate'] ?? 0.0)));
        $qualityScore = max(0.0, min(100.0, (float) ($signals['quality_score'] ?? 0.0)));
        $chainGap = max(0.0, min(100.0, (float) ($signals['chain_gap_score'] ?? 0.0)));
        $topicalGap = max(0.0, min(100.0, (float) ($signals['topical_gap_score'] ?? 0.0)));
        $manualPriority = $this->manualPriorityScore((string) ($signals['manual_priority'] ?? 'medium'));

        $recencyDays = max(0, (int) ($signals['recency_days'] ?? $recencyWindow));
        $recencyScore = max(0.0, min(100.0, 100 - (($recencyDays / $recencyWindow) * 100)));

        $breakdown = [
            'quality_score' => round($qualityScore, 2),
            'page_views' => round($pageViews, 2),
            'engagement_rate' => round($engagementRate, 2),
            'recency' => round($recencyScore, 2),
            'chain_gap' => round($chainGap, 2),
            'manual_priority' => round($manualPriority, 2),
            'topical_gap' => round($topicalGap, 2),
        ];

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($breakdown as $key => $value) {
            $weight = (float) ($weights[$key] ?? 0.0);
            $weightedSum += $value * $weight;
            $weightTotal += $weight;
        }

        $score = $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;

        return [
            'score' => round(max(0.0, min(100.0, $score)), 2),
            'breakdown' => $breakdown,
        ];
    }

    public function manualPriorityScore(string $priority): float
    {
        return match (strtolower(trim($priority))) {
            'critical' => 100.0,
            'high' => 82.0,
            'low' => 28.0,
            default => 55.0,
        };
    }
}
