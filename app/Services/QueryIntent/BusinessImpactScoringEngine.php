<?php

namespace App\Services\QueryIntent;

class BusinessImpactScoringEngine
{
    /**
     * @return array{level:string,score:float,breakdown:array<string,mixed>}
     */
    public function score(string $intent, string $funnelStage, string $buyerRole, string $text): array
    {
        $text = strtolower($text);
        $intentScore = match ($intent) {
            'transactional', 'migration' => 82.0,
            'comparison', 'risk_evaluation' => 76.0,
            'commercial', 'implementation' => 66.0,
            'navigational' => 45.0,
            default => 38.0,
        };
        $stageScore = match ($funnelStage) {
            'decision' => 82.0,
            'consideration' => 66.0,
            'retention' => 72.0,
            default => 42.0,
        };
        $roleScore = $buyerRole === 'enterprise_buyers' ? 82.0 : ($buyerRole === 'founders' ? 72.0 : 58.0);
        $modifier = 0.0;
        foreach (['revenue', 'pipeline', 'pricing', 'enterprise', 'compliance', 'migration', 'demo'] as $term) {
            if (str_contains($text, $term)) {
                $modifier += 3.0;
            }
        }

        $score = min(100.0, ($intentScore * 0.38) + ($stageScore * 0.32) + ($roleScore * 0.2) + $modifier);

        return [
            'level' => match (true) {
                $score >= 82 => 'strategic',
                $score >= 65 => 'high',
                $score >= 45 => 'medium',
                default => 'low',
            },
            'score' => round($score, 2),
            'breakdown' => [
                'intent_score' => $intentScore,
                'stage_score' => $stageScore,
                'role_score' => $roleScore,
                'modifier' => $modifier,
            ],
        ];
    }
}
