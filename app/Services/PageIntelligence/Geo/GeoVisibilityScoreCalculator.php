<?php

namespace App\Services\PageIntelligence\Geo;

use App\Models\LlmTrackingQueryRun;

final class GeoVisibilityScoreCalculator
{
    /**
     * @param array<string,mixed> $context
     * @return array{score:float,topic_ownership_score:float,consistency_score:float,breakdown:array<string,mixed>}
     */
    public function calculate(LlmTrackingQueryRun $run, array $context = []): array
    {
        $clientCited = (bool) ($context['client_cited'] ?? false);
        $competitorsCited = (bool) ($context['competitors_cited'] ?? false);
        $citationCount = max(0, (int) ($context['citation_count'] ?? 0));
        $citationPosition = $context['citation_position'] ?? null;
        $brandMentioned = (bool) ($run->brand_mentioned ?? false);
        $competitorPressure = max(0.0, min(1.0, (float) ($run->competitor_pressure_score ?? (1 - (float) ($run->competitive_score ?? 0.5)))));
        $aiVisibility = max(0.0, min(1.0, (float) ($run->ai_visibility_score ?? 0)));
        $sentiment = $this->sentimentScore((string) ($run->sentiment_label ?? 'neutral'), $run->sentiment_score);
        $positionScore = $citationPosition !== null ? $this->citationPositionScore((int) $citationPosition) : (float) ($run->position_score ?? 0);
        $citationScore = $clientCited ? min(1.0, 0.55 + ($citationCount * 0.15)) : 0.0;
        $competitorPenalty = $competitorsCited ? max(0.65, 1 - ($competitorPressure * 0.25)) : 1.0;
        $topicOwnershipScore = max(0.0, min(1.0, (
            ($brandMentioned ? 0.35 : 0.0)
            + ($clientCited ? 0.35 : 0.0)
            + ((float) ($run->competitive_score ?? 0.5) * 0.2)
            + ($positionScore * 0.1)
        )));
        $consistencyScore = max(0.0, min(1.0, (
            ((float) ($run->model_confidence_score ?? 0.75) * 0.35)
            + ((float) ($run->citation_diversity_score ?? 0.5) * 0.25)
            + ($aiVisibility * 0.25)
            + ($brandMentioned ? 0.15 : 0.0)
        )));

        $score = (
            ($topicOwnershipScore * 0.28)
            + ($consistencyScore * 0.18)
            + ($citationScore * 0.22)
            + ($positionScore * 0.12)
            + ($sentiment * 0.08)
            + ($aiVisibility * 0.12)
        ) * $competitorPenalty;

        $score = round(max(0.0, min(100.0, $score * 100)), 4);

        return [
            'score' => $score,
            'topic_ownership_score' => round($topicOwnershipScore, 4),
            'consistency_score' => round($consistencyScore, 4),
            'breakdown' => [
                'model' => [
                    'key' => 'argusly_geo_visibility_mvp',
                    'version' => '2026-07-03',
                ],
                'weights' => [
                    'topic_ownership' => 0.28,
                    'consistency' => 0.18,
                    'citation' => 0.22,
                    'position' => 0.12,
                    'sentiment' => 0.08,
                    'ai_visibility' => 0.12,
                ],
                'topic_ownership_score' => round($topicOwnershipScore, 4),
                'consistency_score' => round($consistencyScore, 4),
                'citation_score' => round($citationScore, 4),
                'citation_position_score' => round($positionScore, 4),
                'sentiment_score' => round($sentiment, 4),
                'ai_visibility_score' => $aiVisibility,
                'competitor_penalty' => round($competitorPenalty, 4),
                'inputs' => [
                    'client_cited' => $clientCited,
                    'competitors_cited' => $competitorsCited,
                    'brand_mentioned' => $brandMentioned,
                    'citation_count' => $citationCount,
                    'citation_position' => $citationPosition,
                    'sentiment' => $run->sentiment_label,
                ],
            ],
        ];
    }

    private function citationPositionScore(int $position): float
    {
        return match (true) {
            $position <= 0 => 0.0,
            $position === 1 => 1.0,
            $position === 2 => 0.82,
            $position === 3 => 0.68,
            $position <= 5 => 0.5,
            default => 0.25,
        };
    }

    private function sentimentScore(string $label, mixed $score): float
    {
        if (is_numeric($score)) {
            return max(0.0, min(1.0, (float) $score));
        }

        return match (strtolower($label)) {
            'positive' => 0.85,
            'negative' => 0.25,
            default => 0.6,
        };
    }
}
