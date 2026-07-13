<?php

namespace App\Services\BrandGrowthPlanning\Analyzers;

use App\Enums\BrandGrowthFindingType;
use App\Services\BrandGrowthPlanning\BrandGrowthAnalyzerResult;

class VisibilityAnalyzer implements BrandGrowthAnalyzer
{
    public function analyze(array $context): BrandGrowthAnalyzerResult
    {
        $queryCount = (int) data_get($context, 'visibility.llm_tracking_queries', 0);
        $queries = collect(data_get($context, 'visibility.items', []));
        $signals = collect(data_get($context, 'signals.items', []));
        $highPrioritySignalCount = (int) data_get($context, 'signals.high_priority_count', 0);
        $observationCount = (int) data_get($context, 'marketing_observations.total', 0);
        $findings = [];
        $missing = [];

        if ($queryCount === 0) {
            $missing[] = 'No AI visibility tracking queries are configured.';
            $findings[] = [
                'type' => BrandGrowthFindingType::AI_VISIBILITY_GAP->value,
                'title' => 'AI visibility tracking is not ready for brand planning',
                'description' => 'The plan cannot compare brand presence, citations, or answer coverage across LLM tracking queries yet.',
                'rationale' => 'AI visibility is a key signal for whether the brand is visible and memorable in emerging discovery surfaces.',
                'impact_score' => 76,
                'urgency_score' => 62,
                'confidence_score' => 88,
                'recommended_action' => 'Configure LLM tracking queries for priority categories, buyer questions, and competitor comparison prompts.',
                'source_references' => [],
                'source_summary' => ['llm_tracking_queries' => 0],
            ];
        }

        if ($highPrioritySignalCount > 0) {
            $topSignal = $signals
                ->sortByDesc(fn (array $signal): float => (float) ($signal['priority_score'] ?? 0))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::SERP_OPPORTUNITY->value,
                'title' => 'High-priority market signals should shape strategic actions',
                'description' => 'Signal Intelligence contains high-priority detections that may indicate visibility, intent, or market-change opportunities.',
                'rationale' => 'Strategic planning should consume reviewed market signals, but execution should still pass through governed opportunities.',
                'impact_score' => 78,
                'urgency_score' => 72,
                'confidence_score' => 70,
                'affected_audience' => data_get($topSignal, 'entity'),
                'recommended_action' => 'Review the highest-priority detections and promote the strongest findings into the opportunity workflow.',
                'source_references' => [
                    'signal_detection_ids' => $signals->pluck('id')->filter()->take(15)->values()->all(),
                ],
                'source_summary' => [
                    'high_priority_signal_count' => $highPrioritySignalCount,
                    'open_opportunities' => (int) data_get($context, 'signals.open_opportunities', 0),
                    'top_signal_title' => data_get($topSignal, 'title'),
                ],
            ];
        }

        if ($queryCount > 0 && $queries->isNotEmpty()) {
            $queryTopics = $queries->pluck('topic')->filter()->unique()->values()->all();

            $findings[] = [
                'type' => BrandGrowthFindingType::AI_VISIBILITY_GAP->value,
                'title' => 'AI visibility queries need strategic interpretation',
                'description' => 'LLM tracking queries are configured, but the first Brand Growth Plan should still validate which topics map to priority audiences and proof assets.',
                'rationale' => 'Tracking queries become more useful when linked to approved audiences, authority areas, and evidence-backed response paths.',
                'impact_score' => 64,
                'urgency_score' => 48,
                'confidence_score' => 62,
                'recommended_action' => 'Map active AI visibility queries to approved audiences, authority priorities, and proof-led content opportunities.',
                'source_references' => [
                    'llm_tracking_query_ids' => $queries->pluck('id')->filter()->take(20)->values()->all(),
                ],
                'source_summary' => [
                    'llm_tracking_queries' => $queryCount,
                    'query_topics' => $queryTopics,
                ],
            ];
        }

        if ($observationCount === 0) {
            $missing[] = 'No connector-backed marketing observations are available.';
            $findings[] = [
                'type' => BrandGrowthFindingType::MEASUREMENT_GAP->value,
                'title' => 'Marketing performance context is missing from the plan',
                'description' => 'The plan cannot yet validate recommended audiences, channels, and assets against connector-backed performance observations.',
                'rationale' => 'Measurement context prevents the strategist from treating every plausible gap as equally important.',
                'impact_score' => 66,
                'urgency_score' => 56,
                'confidence_score' => 84,
                'recommended_action' => 'Connect analytics, Search Console, CRM, or campaign observations before approving performance-sensitive priorities.',
                'source_references' => [],
                'source_summary' => ['marketing_observations' => 0],
            ];
        }

        return new BrandGrowthAnalyzerResult(
            summary: 'Visibility reviewed LLM tracking, Signal Intelligence, and connector-backed observations.',
            findings: $findings,
            confidence: $queryCount > 0 || $highPrioritySignalCount > 0 ? 66 : 44,
            missingData: $missing,
            sourcesUsed: ['llm_tracking_queries', 'signal_intelligence', 'marketing_observations'],
            sourcesNotAvailable: $missing,
            recommendedActions: ['Use visibility and performance signals as strategic inputs, not autonomous execution triggers.'],
        );
    }
}
