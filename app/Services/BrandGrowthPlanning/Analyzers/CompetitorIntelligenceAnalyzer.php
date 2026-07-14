<?php

namespace App\Services\BrandGrowthPlanning\Analyzers;

use App\Enums\BrandGrowthFindingType;
use App\Services\BrandGrowthPlanning\BrandGrowthAnalyzerResult;

class CompetitorIntelligenceAnalyzer implements BrandGrowthAnalyzer
{
    public function analyze(array $context): BrandGrowthAnalyzerResult
    {
        $competitors = collect(data_get($context, 'competitors.items', []));
        $activeCount = (int) data_get($context, 'competitors.active_count', 0);
        $competitorIds = $competitors->pluck('id')->filter()->values()->all();
        $contentItems = (int) $competitors->sum('content_items_count');
        $topicSignals = (int) $competitors->sum('topic_signals_count');
        $opportunities = (int) $competitors->sum('content_opportunities_count');
        $pageCompetitorMatches = collect(data_get($context, 'page_intelligence.relationships.competitor_matches', []));
        $highPageCompetitorMatches = (int) data_get($context, 'page_intelligence.relationships.high_competitor_match_count', 0);
        $findings = [];
        $missing = [];

        if ($activeCount === 0) {
            $missing[] = 'No active competitors are configured.';
            $findings[] = [
                'type' => BrandGrowthFindingType::COMPETITOR_OPPORTUNITY->value,
                'title' => 'Competitor context is not ready for strategic comparison',
                'description' => 'The plan cannot identify competitor positioning patterns or response opportunities until competitors are configured.',
                'rationale' => 'Competitor context helps distinguish defensible brand gaps from generic content gaps.',
                'impact_score' => 68,
                'urgency_score' => 56,
                'confidence_score' => 86,
                'recommended_action' => 'Configure priority competitors before approving competitor-response strategy.',
                'source_references' => [],
                'source_summary' => ['active_competitors' => 0],
            ];
        }

        if ($activeCount > 0 && $contentItems === 0 && $topicSignals === 0 && $opportunities === 0) {
            $missing[] = 'Competitors are configured, but no competitor content signals are available yet.';
        }

        if ($activeCount > 0 && ($contentItems > 0 || $topicSignals > 0 || $opportunities > 0)) {
            $topCompetitor = $competitors
                ->sortByDesc(fn (array $competitor): int => (int) ($competitor['content_items_count'] ?? 0) + (int) ($competitor['topic_signals_count'] ?? 0) + (int) ($competitor['content_opportunities_count'] ?? 0))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::COMPETITOR_THREAT->value,
                'title' => 'Competitor evidence is available but not yet tied to strategy',
                'description' => 'Configured competitors have monitored content or topic evidence that can shape positioning and response opportunities.',
                'rationale' => 'Competitor signals should inform what Argusly recommends, but execution should still flow through reviewed opportunities.',
                'impact_score' => 76,
                'urgency_score' => $opportunities > 0 ? 72 : 58,
                'confidence_score' => 72,
                'affected_industry' => data_get($context, 'company_profile.industry'),
                'site_competitor_id' => data_get($topCompetitor, 'id'),
                'recommended_action' => 'Review competitor themes and promote only the strongest response opportunities into the opportunity workflow.',
                'source_references' => ['site_competitor_ids' => $competitorIds],
                'source_summary' => [
                    'active_competitors' => $activeCount,
                    'competitor_content_items' => $contentItems,
                    'competitor_topic_signals' => $topicSignals,
                    'competitor_opportunities' => $opportunities,
                ],
            ];
        }

        if ($activeCount > 0 && $highPageCompetitorMatches > 0) {
            $topMatch = $pageCompetitorMatches
                ->sortByDesc(fn (array $match): float => (float) ($match['match_score'] ?? 0))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::COMPETITOR_THREAT->value,
                'title' => 'Observed pages overlap strongly with competitor themes',
                'description' => 'Page Intelligence relationship matching found high-scoring competitor overlap on monitored pages.',
                'rationale' => 'High competitor overlap can indicate positioning sameness, content commoditization, or an opportunity to sharpen the brand narrative.',
                'impact_score' => 80,
                'urgency_score' => 70,
                'confidence_score' => 76,
                'affected_industry' => data_get($context, 'company_profile.industry'),
                'site_competitor_id' => data_get($topMatch, 'site_competitor_id'),
                'monitored_page_id' => data_get($topMatch, 'monitored_page_id'),
                'recommended_action' => 'Audit the highest-overlap page against the matched competitor and rewrite the positioning angle around proof, specificity, or a sharper audience promise.',
                'source_references' => [
                    'page_competitor_match_ids' => $pageCompetitorMatches->pluck('id')->filter()->take(10)->values()->all(),
                    'site_competitor_ids' => $pageCompetitorMatches->pluck('site_competitor_id')->filter()->take(10)->values()->all(),
                    'monitored_page_ids' => $pageCompetitorMatches->pluck('monitored_page_id')->filter()->take(10)->values()->all(),
                ],
                'source_summary' => [
                    'high_competitor_page_matches' => $highPageCompetitorMatches,
                    'top_competitor' => data_get($topMatch, 'competitor_name') ?: data_get($topMatch, 'competitor_domain'),
                    'top_match_type' => data_get($topMatch, 'match_type'),
                    'top_match_score' => data_get($topMatch, 'match_score'),
                ],
            ];
        }

        if ($activeCount > 0 && (int) data_get($context, 'content.comparison_count', 0) === 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::COMPETITOR_OPPORTUNITY->value,
                'title' => 'Competitor comparison coverage is absent from owned content',
                'description' => 'Competitors are configured, but sampled owned content does not include comparison or alternative-framing assets.',
                'rationale' => 'Comparison assets clarify why the brand should be remembered when buyers evaluate alternatives.',
                'impact_score' => 70,
                'urgency_score' => 54,
                'confidence_score' => 66,
                'recommended_action' => 'Prepare a comparison brief for the most strategically relevant competitor or alternative category.',
                'source_references' => [
                    'site_competitor_ids' => $competitorIds,
                    'content_ids' => collect(data_get($context, 'content.items', []))->pluck('id')->filter()->take(20)->values()->all(),
                ],
                'source_summary' => [
                    'active_competitors' => $activeCount,
                    'owned_comparison_content_count' => (int) data_get($context, 'content.comparison_count', 0),
                ],
            ];
        }

        return new BrandGrowthAnalyzerResult(
            summary: 'Competitor Intelligence reviewed configured competitors, competitor content evidence, and Page Intelligence relationship matches.',
            findings: $findings,
            confidence: $activeCount > 0 && $highPageCompetitorMatches > 0 ? 74 : ($activeCount > 0 ? 66 : 42),
            missingData: $missing,
            sourcesUsed: ['site_competitors', 'competitor_intelligence', 'page_intelligence_relationships'],
            sourcesNotAvailable: $missing,
            recommendedActions: ['Use competitor evidence as strategy input, then promote selected responses through Opportunities.'],
        );
    }
}
