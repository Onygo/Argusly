<?php

namespace App\Services\QueryIntent;

class UrgencyClassifier
{
    /**
     * @return array{level:string,score:float,signals:array<string,mixed>}
     */
    public function classify(string $text, string $intent): array
    {
        $text = strtolower($text);
        $score = match ($intent) {
            'transactional', 'migration' => 70.0,
            'comparison', 'risk_evaluation' => 62.0,
            'implementation' => 55.0,
            default => 35.0,
        };
        $matches = [];

        foreach (['urgent', 'now', 'today', 'blocked', 'broken', 'failed', 'deadline', 'launch', 'incident'] as $term) {
            if (str_contains($text, $term)) {
                $score += 8.0;
                $matches[] = $term;
            }
        }

        $score = min(100.0, $score);

        return [
            'level' => match (true) {
                $score >= 85 => 'critical',
                $score >= 65 => 'high',
                $score >= 45 => 'medium',
                default => 'low',
            },
            'score' => round($score, 2),
            'signals' => ['urgency_terms' => $matches],
        ];
    }
}
