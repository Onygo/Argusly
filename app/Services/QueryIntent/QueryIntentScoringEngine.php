<?php

namespace App\Services\QueryIntent;

class QueryIntentScoringEngine
{
    /**
     * @return array{priority_score:float,breakdown:array<string,mixed>}
     */
    public function score(float $confidence, float $urgencyScore, float $impactScore, string $funnelStage): array
    {
        $stageBoost = match ($funnelStage) {
            'decision' => 10.0,
            'retention' => 7.0,
            'consideration' => 5.0,
            default => 0.0,
        };

        $priority = min(100.0, ($confidence * 0.24) + ($urgencyScore * 0.28) + ($impactScore * 0.42) + $stageBoost);

        return [
            'priority_score' => round($priority, 2),
            'breakdown' => [
                'confidence_weighted' => round($confidence * 0.24, 2),
                'urgency_weighted' => round($urgencyScore * 0.28, 2),
                'impact_weighted' => round($impactScore * 0.42, 2),
                'stage_boost' => $stageBoost,
            ],
        ];
    }
}
