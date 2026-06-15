<?php

namespace App\Services\GrowthAutopilot;

class GrowthAutopilotPrioritizationEngine
{
    /**
     * @param array<string,mixed> $context
     */
    public function score(int $actionPriority, int $expectedImpact, int $confidence, array $context = []): int
    {
        $score = (int) round(($actionPriority * 0.45) + ($expectedImpact * 0.35) + ($confidence * 0.20));

        if ((bool) ($context['approval_required'] ?? false)) {
            $score += 4;
        }

        if ((bool) ($context['prepared_assets'] ?? false)) {
            $score += 6;
        }

        if ((bool) ($context['competitor_pressure'] ?? false)) {
            $score += 8;
        }

        return max(1, min(100, $score));
    }

    public function label(int $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
