<?php

namespace App\Services\RecommendedActions;

class RecommendedActionScoring
{
    /**
     * @param array<string,mixed> $context
     */
    public function priority(float|int|null $baseScore, int $confidenceScore, int $impactScore, array $context = []): int
    {
        $score = (int) round(((float) ($baseScore ?? 50) * 0.45) + ($impactScore * 0.35) + ($confidenceScore * 0.20));

        if ((bool) ($context['approval_required'] ?? false)) {
            $score += 8;
        }

        if ((bool) ($context['urgent'] ?? false)) {
            $score += 10;
        }

        if ((bool) ($context['blocked'] ?? false)) {
            $score += 12;
        }

        return $this->clamp($score);
    }

    public function confidence(float|int|null $sourceConfidence, mixed $evidence = null): int
    {
        $score = (int) round((float) ($sourceConfidence ?? 55));

        if (is_array($evidence) && $evidence !== []) {
            $score += 8;
        }

        return $this->clamp($score);
    }

    public function expectedImpact(float|int|null $sourceImpact, mixed $expectedOutcome = null): int
    {
        $score = (int) round((float) ($sourceImpact ?? 55));

        if (is_array($expectedOutcome) && $expectedOutcome !== []) {
            $score += 6;
        }

        return $this->clamp($score);
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

    private function clamp(int $score): int
    {
        return max(1, min(100, $score));
    }
}
