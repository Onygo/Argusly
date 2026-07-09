<?php

namespace App\Services\AgenticMarketing\Intelligence;

class RecommendationPriority
{
    public function score(float $impact, float $confidence, float $risk, float $opportunity): int
    {
        $weights = $this->weights();
        $weighted = ($this->clamp($impact) * $weights['impact'])
            + ($this->clamp($confidence * 100) * $weights['confidence'])
            + ($this->clamp($risk) * $weights['risk'])
            + ($this->clamp($opportunity) * $weights['opportunity']);

        return (int) round($this->clamp($weighted));
    }

    /**
     * @return array{impact:float,confidence:float,risk:float,opportunity:float}
     */
    private function weights(): array
    {
        $weights = collect((array) config('argusly.agentic_marketing_intelligence.priority_weights', []))
            ->filter(fn (mixed $weight): bool => is_numeric($weight))
            ->map(fn (mixed $weight): float => max(0.0, (float) $weight))
            ->all();
        $weights = array_replace([
            'impact' => 0.40,
            'confidence' => 0.25,
            'risk' => 0.20,
            'opportunity' => 0.15,
        ], $weights);
        $total = array_sum($weights);

        if ($total <= 0) {
            return ['impact' => 0.4, 'confidence' => 0.25, 'risk' => 0.2, 'opportunity' => 0.15];
        }

        return [
            'impact' => $weights['impact'] / $total,
            'confidence' => $weights['confidence'] / $total,
            'risk' => $weights['risk'] / $total,
            'opportunity' => $weights['opportunity'] / $total,
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
