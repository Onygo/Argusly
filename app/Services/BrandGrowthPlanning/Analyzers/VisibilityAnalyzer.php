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
        $serpItems = collect(data_get($context, 'page_intelligence.serp.items', []));
        $geoItems = collect(data_get($context, 'page_intelligence.geo.items', []));
        $serpTotal = (int) data_get($context, 'page_intelligence.serp.total', 0);
        $geoTotal = (int) data_get($context, 'page_intelligence.geo.total', 0);
        $lowSerpVisibility = (int) data_get($context, 'page_intelligence.serp.low_visibility_count', 0);
        $geoCompetitorCitations = (int) data_get($context, 'page_intelligence.geo.competitors_cited_count', 0);
        $geoClientCitations = (int) data_get($context, 'page_intelligence.geo.client_cited_count', 0);
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

        if ($serpTotal > 0 && $lowSerpVisibility > 0) {
            $weakSerp = $serpItems
                ->sortBy(fn (array $item): float => (float) ($item['visibility_score'] ?? 100))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::SERP_OPPORTUNITY->value,
                'title' => 'Observed SERP visibility is weak for priority page queries',
                'description' => 'Page Intelligence contains SERP observations with low visibility scores, indicating owned pages may not be prominent enough for tracked queries.',
                'rationale' => 'Weak SERP visibility limits discoverability before buyers reach AI or owned-site experiences.',
                'impact_score' => 78,
                'urgency_score' => 68,
                'confidence_score' => 74,
                'affected_funnel_stage' => 'discovery',
                'monitored_page_id' => data_get($weakSerp, 'monitored_page_id'),
                'recommended_action' => 'Review low-visibility SERP observations and prioritize authority, relevance, and internal-link improvements for the weakest query/page pairs.',
                'source_references' => [
                    'page_serp_observation_ids' => $serpItems->pluck('id')->filter()->take(10)->values()->all(),
                    'monitored_page_ids' => $serpItems->pluck('monitored_page_id')->filter()->take(10)->values()->all(),
                ],
                'source_summary' => [
                    'serp_observations' => $serpTotal,
                    'low_visibility_count' => $lowSerpVisibility,
                    'weakest_query' => data_get($weakSerp, 'query'),
                    'weakest_visibility_score' => data_get($weakSerp, 'visibility_score'),
                ],
            ];
        }

        if ($geoTotal > 0 && $geoCompetitorCitations > 0 && $geoClientCitations === 0) {
            $competitiveGeo = $geoItems
                ->filter(fn (array $item): bool => (bool) ($item['competitors_cited'] ?? false))
                ->sortBy(fn (array $item): float => (float) ($item['geo_visibility_score'] ?? 100))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::AI_VISIBILITY_GAP->value,
                'title' => 'AI answers cite competitors without citing the brand',
                'description' => 'GEO observations show competitor citations while the client brand is not cited in sampled answer-engine results.',
                'rationale' => 'Competitor citations without brand citations point to an authority and evidence gap in AI-mediated discovery.',
                'impact_score' => 86,
                'urgency_score' => 78,
                'confidence_score' => 78,
                'affected_funnel_stage' => 'discovery',
                'recommended_action' => 'Create or strengthen cited proof assets for the affected AI visibility query, then monitor whether client citations appear in subsequent answer-engine observations.',
                'source_references' => [
                    'page_geo_observation_ids' => $geoItems->pluck('id')->filter()->take(10)->values()->all(),
                    'llm_tracking_query_ids' => $geoItems->pluck('llm_tracking_query_id')->filter()->take(10)->values()->all(),
                    'monitored_page_ids' => $geoItems->pluck('monitored_page_id')->filter()->take(10)->values()->all(),
                ],
                'source_summary' => [
                    'geo_observations' => $geoTotal,
                    'competitor_citations' => $geoCompetitorCitations,
                    'client_citations' => $geoClientCitations,
                    'example_query' => data_get($competitiveGeo, 'query'),
                    'answer_engine' => data_get($competitiveGeo, 'answer_engine'),
                    'visibility_score' => data_get($competitiveGeo, 'geo_visibility_score'),
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
            summary: 'Visibility reviewed LLM tracking, Signal Intelligence, Page Intelligence observations, and connector-backed observations.',
            findings: $findings,
            confidence: $queryCount > 0 || $highPrioritySignalCount > 0 || $serpTotal > 0 || $geoTotal > 0 ? 70 : 44,
            missingData: $missing,
            sourcesUsed: ['llm_tracking_queries', 'signal_intelligence', 'page_intelligence', 'marketing_observations'],
            sourcesNotAvailable: $missing,
            recommendedActions: ['Use visibility and performance signals as strategic inputs, not autonomous execution triggers.'],
        );
    }
}
