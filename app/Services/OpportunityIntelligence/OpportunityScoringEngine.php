<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Models\OpportunitySignal;
use Illuminate\Support\Collection;

class OpportunityScoringEngine
{
    /**
     * @param Collection<int, OpportunitySignal> $signals
     * @return array<string,mixed>
     */
    public function score(OpportunityCategory $category, Collection $signals): array
    {
        $strength = (float) $signals->avg('signal_strength');
        $confidence = (float) $signals->avg('confidence');
        $sourceDiversity = min(100.0, $signals->pluck('source')->unique()->count() * 22.0);
        $freshness = $this->freshnessScore($signals);
        $categoryImpact = $this->categoryImpact($category);
        $urgency = min(100.0, ($strength * 0.48) + ($freshness * 0.28) + ($categoryImpact * 0.24));
        $impact = min(100.0, ($categoryImpact * 0.55) + ($strength * 0.3) + ($sourceDiversity * 0.15));
        $effort = $this->effortScore($category, $signals);
        $priority = min(100.0, max(0.0,
            ($impact * 0.38)
            + ($urgency * 0.26)
            + ($confidence * 0.22)
            + ($sourceDiversity * 0.1)
            - ($effort * 0.04)
        ));

        return [
            'priority_score' => round($priority, 2),
            'confidence_score' => round(min(100.0, ($confidence * 0.82) + ($sourceDiversity * 0.18)), 2),
            'impact_score' => round($impact, 2),
            'urgency_score' => round($urgency, 2),
            'effort_score' => round($effort, 2),
            'score_breakdown' => [
                'avg_signal_strength' => round($strength, 2),
                'avg_confidence' => round($confidence, 2),
                'source_diversity' => round($sourceDiversity, 2),
                'freshness' => round($freshness, 2),
                'category_impact' => round($categoryImpact, 2),
                'formula' => 'impact*.38 + urgency*.26 + confidence*.22 + source_diversity*.10 - effort*.04',
            ],
        ];
    }

    /**
     * @param Collection<int, OpportunitySignal> $signals
     */
    private function freshnessScore(Collection $signals): float
    {
        $latest = $signals->max('observed_at');
        if (! $latest) {
            return 35.0;
        }

        $days = now()->diffInDays($latest);

        return max(20.0, 100.0 - ($days * 4.0));
    }

    private function categoryImpact(OpportunityCategory $category): float
    {
        return match ($category) {
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY => 90.0,
            OpportunityCategory::CONTENT_GAP => 84.0,
            OpportunityCategory::COMPETITOR_MOVEMENT => 82.0,
            OpportunityCategory::TREND_OPPORTUNITY => 78.0,
            OpportunityCategory::BRAND_VISIBILITY => 76.0,
            OpportunityCategory::REFRESH_OPPORTUNITY => 72.0,
            OpportunityCategory::ENGAGEMENT_OPPORTUNITY => 68.0,
        };
    }

    /**
     * @param Collection<int, OpportunitySignal> $signals
     */
    private function effortScore(OpportunityCategory $category, Collection $signals): float
    {
        $hasContent = $signals->contains(fn (OpportunitySignal $signal): bool => filled($signal->content_id));

        return match ($category) {
            OpportunityCategory::REFRESH_OPPORTUNITY, OpportunityCategory::ENGAGEMENT_OPPORTUNITY => $hasContent ? 34.0 : 52.0,
            OpportunityCategory::CONTENT_GAP, OpportunityCategory::TREND_OPPORTUNITY => 62.0,
            OpportunityCategory::COMPETITOR_MOVEMENT => 58.0,
            OpportunityCategory::BRAND_VISIBILITY => 54.0,
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY => $hasContent ? 46.0 : 64.0,
        };
    }
}
