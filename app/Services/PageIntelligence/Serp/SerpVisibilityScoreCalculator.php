<?php

namespace App\Services\PageIntelligence\Serp;

final class SerpVisibilityScoreCalculator
{
    /**
     * @return array{score:float,breakdown:array<string,mixed>}
     */
    public function calculate(SerpObservationResult $result): array
    {
        $position = $result->absolutePosition ?? $result->position;
        $positionScore = $this->positionScore($position);
        $resultTypeMultiplier = $this->resultTypeMultiplier($result->resultType);
        $clickPotential = $result->clickPotential ?? $this->estimatedClickPotential($position);
        $volumeMultiplier = $this->volumeMultiplier($result->searchVolume);
        $intentMultiplier = $this->intentMultiplier($result->keywordIntent);
        $featureMultiplier = $this->featureMultiplier($result->serpFeatures);
        $competitorPenalty = $this->competitorPenalty($result->competitorPresence);

        $score = $positionScore
            * $resultTypeMultiplier
            * (0.75 + (min(1.0, max(0.0, $clickPotential)) * 0.25))
            * $volumeMultiplier
            * $intentMultiplier
            * $featureMultiplier
            * $competitorPenalty
            * 100;

        $score = round(min(100.0, max(0.0, $score)), 4);

        return [
            'score' => $score,
            'breakdown' => [
                'position' => [
                    'absolute_position' => $position,
                    'score' => round($positionScore, 4),
                ],
                'result_type' => [
                    'value' => $result->resultType,
                    'multiplier' => $resultTypeMultiplier,
                ],
                'click_potential' => [
                    'value' => round($clickPotential, 4),
                    'provided' => $result->clickPotential !== null,
                ],
                'search_volume' => [
                    'value' => $result->searchVolume,
                    'multiplier' => $volumeMultiplier,
                ],
                'keyword_intent' => [
                    'value' => $result->keywordIntent,
                    'multiplier' => $intentMultiplier,
                ],
                'serp_features' => [
                    'count' => count($result->serpFeatures),
                    'multiplier' => $featureMultiplier,
                ],
                'competitor_presence' => [
                    'count' => count($result->competitorPresence),
                    'multiplier' => $competitorPenalty,
                ],
                'model' => [
                    'key' => 'argusly_serp_visibility_mvp',
                    'version' => '2026-07-03',
                ],
            ],
        ];
    }

    private function positionScore(?int $position): float
    {
        if ($position === null || $position < 1 || $position > 100) {
            return 0.0;
        }

        return match (true) {
            $position === 1 => 1.0,
            $position === 2 => 0.85,
            $position === 3 => 0.75,
            $position <= 5 => 0.6,
            $position <= 10 => 0.42,
            $position <= 20 => 0.18,
            $position <= 50 => 0.06,
            default => 0.02,
        };
    }

    private function resultTypeMultiplier(string $resultType): float
    {
        return match (strtolower($resultType)) {
            'featured_snippet', 'knowledge_panel' => 1.2,
            'ai_overview', 'geo_citation', 'citation' => 1.12,
            'video', 'image_pack', 'news' => 0.9,
            'people_also_ask' => 0.65,
            'ad', 'paid' => 0.5,
            default => 1.0,
        };
    }

    private function estimatedClickPotential(?int $position): float
    {
        if ($position === null || $position < 1) {
            return 0.0;
        }

        return match (true) {
            $position === 1 => 0.32,
            $position === 2 => 0.18,
            $position === 3 => 0.11,
            $position <= 5 => 0.07,
            $position <= 10 => 0.03,
            default => 0.01,
        };
    }

    private function volumeMultiplier(?int $searchVolume): float
    {
        if ($searchVolume === null || $searchVolume <= 0) {
            return 1.0;
        }

        return round(min(1.35, 1 + (log10($searchVolume + 1) / 20)), 4);
    }

    private function intentMultiplier(?string $intent): float
    {
        return match (strtolower((string) $intent)) {
            'transactional', 'commercial' => 1.15,
            'navigational' => 1.05,
            'informational' => 1.0,
            default => 1.0,
        };
    }

    /**
     * @param array<int|string,mixed> $features
     */
    private function featureMultiplier(array $features): float
    {
        if ($features === []) {
            return 1.0;
        }

        return min(1.15, 1 + (count($features) * 0.03));
    }

    /**
     * @param array<int|string,mixed> $competitors
     */
    private function competitorPenalty(array $competitors): float
    {
        if ($competitors === []) {
            return 1.0;
        }

        return max(0.7, 1 - (count($competitors) * 0.04));
    }
}
