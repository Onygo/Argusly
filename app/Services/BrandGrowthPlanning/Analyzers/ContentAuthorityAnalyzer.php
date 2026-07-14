<?php

namespace App\Services\BrandGrowthPlanning\Analyzers;

use App\Enums\BrandGrowthFindingType;
use App\Services\BrandGrowthPlanning\BrandGrowthAnalyzerResult;

class ContentAuthorityAnalyzer implements BrandGrowthAnalyzer
{
    public function analyze(array $context): BrandGrowthAnalyzerResult
    {
        $contentTotal = (int) data_get($context, 'content.total', 0);
        $sampled = (int) data_get($context, 'content.sampled', 0);
        $caseStudies = (int) data_get($context, 'content.case_study_count', 0);
        $benchmarks = (int) data_get($context, 'content.benchmark_count', 0);
        $roi = (int) data_get($context, 'content.roi_count', 0);
        $comparisons = (int) data_get($context, 'content.comparison_count', 0);
        $withPersona = (int) data_get($context, 'content.with_persona', 0);
        $contentIds = collect(data_get($context, 'content.items', []))->pluck('id')->filter()->take(20)->values()->all();
        $pageIds = collect(data_get($context, 'pages.items', []))->pluck('id')->filter()->take(20)->values()->all();
        $brandMatches = collect(data_get($context, 'page_intelligence.relationships.brand_matches', []));
        $weakBrandMatches = (int) data_get($context, 'page_intelligence.relationships.weak_brand_match_count', 0);
        $findings = [];
        $missing = [];

        if ($contentTotal === 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::CONTENT_GAP->value,
                'title' => 'No owned content inventory is available for strategic planning',
                'description' => 'The plan cannot compare owned content coverage against audiences, proof needs, and decision stages yet.',
                'rationale' => 'Content is not the north-star objective, but owned assets are still important evidence and execution surfaces for brand growth.',
                'impact_score' => 82,
                'urgency_score' => 74,
                'confidence_score' => 86,
                'recommended_action' => 'Connect or activate website content inventory before approving content-led strategy.',
                'source_references' => ['monitored_page_ids' => $pageIds],
                'source_summary' => ['content_total' => $contentTotal, 'observed_pages_total' => (int) data_get($context, 'pages.total', 0)],
            ];
            $missing[] = 'Owned content records are unavailable.';
        }

        if ($sampled > 0 && $caseStudies === 0 && $benchmarks === 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::EVIDENCE_GAP->value,
                'title' => 'Owned content lacks visible proof assets',
                'description' => 'Sampled content does not show case studies, customer stories, testimonials, benchmarks, original research, or data-led reports.',
                'rationale' => 'Proof assets make the brand more credible and harder to substitute, especially for skeptical or late-stage buyers.',
                'impact_score' => 84,
                'urgency_score' => 70,
                'confidence_score' => 74,
                'affected_funnel_stage' => 'decision',
                'recommended_action' => 'Prioritize one proof-led asset such as a customer story, benchmark, or research-backed article.',
                'source_references' => ['content_ids' => $contentIds],
                'source_summary' => [
                    'sampled_content' => $sampled,
                    'case_study_count' => $caseStudies,
                    'benchmark_or_research_count' => $benchmarks,
                ],
            ];
        }

        if ($sampled > 0 && $roi === 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::AUTHORITY_GAP->value,
                'title' => 'Decision-stage measurement and ROI content is thin',
                'description' => 'Sampled content does not surface ROI, business-case, measurement, or metrics-led assets.',
                'rationale' => 'Decision-makers often need evidence that the problem is measurable and worth prioritizing.',
                'impact_score' => 72,
                'urgency_score' => 58,
                'confidence_score' => 68,
                'affected_funnel_stage' => 'decision',
                'recommended_action' => 'Create a decision-stage asset that explains business impact, measurement, and expected outcomes.',
                'source_references' => ['content_ids' => $contentIds],
                'source_summary' => ['sampled_content' => $sampled, 'roi_or_measurement_count' => $roi],
            ];
        }

        if ($sampled > 0 && $comparisons === 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::POSITIONING_GAP->value,
                'title' => 'Competitive or alternative-framing content is missing',
                'description' => 'Sampled content does not show comparison, alternative, or versus-style pages.',
                'rationale' => 'Comparison content helps clarify positioning when buyers are already evaluating options.',
                'impact_score' => 66,
                'urgency_score' => 52,
                'confidence_score' => 64,
                'affected_funnel_stage' => 'consideration',
                'recommended_action' => 'Prepare one comparison or alternative-framing brief after reviewing competitor evidence.',
                'source_references' => ['content_ids' => $contentIds],
                'source_summary' => ['sampled_content' => $sampled, 'comparison_count' => $comparisons],
            ];
        }

        if ($sampled > 0 && $withPersona === 0 && (int) data_get($context, 'personas.approved_count', 0) > 0) {
            $findings[] = [
                'type' => BrandGrowthFindingType::PERSONA_GAP->value,
                'title' => 'Content inventory is not tied to approved personas',
                'description' => 'Approved personas exist, but sampled content is not linked to buyer personas.',
                'rationale' => 'Persona linkage helps execution agents consume approved strategy instead of inventing targeting later.',
                'impact_score' => 62,
                'urgency_score' => 50,
                'confidence_score' => 70,
                'recommended_action' => 'Map priority content assets to approved personas before generating audience-led briefs.',
                'source_references' => ['content_ids' => $contentIds],
                'source_summary' => ['sampled_content' => $sampled, 'persona_linked_content' => $withPersona],
            ];
        }

        if ((int) data_get($context, 'pages.total', 0) > 0 && $weakBrandMatches > 0) {
            $weakestMatch = $brandMatches
                ->sortBy(fn (array $match): float => (float) ($match['match_score'] ?? 1))
                ->first();

            $findings[] = [
                'type' => BrandGrowthFindingType::POSITIONING_GAP->value,
                'title' => 'Observed pages show weak brand-to-page alignment',
                'description' => 'Page Intelligence brand matching found monitored pages with weak brand match scores.',
                'rationale' => 'Weak brand alignment makes it harder for buyers and answer engines to associate the page with the intended brand promise.',
                'impact_score' => 74,
                'urgency_score' => 60,
                'confidence_score' => 74,
                'monitored_page_id' => data_get($weakestMatch, 'monitored_page_id'),
                'recommended_action' => 'Review the weakest brand-match pages and reinforce naming, proof points, positioning language, and internal context.',
                'source_references' => [
                    'page_brand_match_ids' => $brandMatches->pluck('id')->filter()->take(10)->values()->all(),
                    'monitored_page_ids' => $brandMatches->pluck('monitored_page_id')->filter()->take(10)->values()->all(),
                ],
                'source_summary' => [
                    'weak_brand_match_count' => $weakBrandMatches,
                    'weakest_brand_name' => data_get($weakestMatch, 'brand_name'),
                    'weakest_match_type' => data_get($weakestMatch, 'match_type'),
                    'weakest_match_score' => data_get($weakestMatch, 'match_score'),
                ],
            ];
        }

        if ((int) data_get($context, 'pages.extracted_total', 0) === 0) {
            $missing[] = 'Observed pages have not been extracted for Page Intelligence content analysis.';
        }

        return new BrandGrowthAnalyzerResult(
            summary: 'Content and Authority reviewed inventory, proof assets, comparison coverage, brand-page alignment, and decision-stage signals.',
            findings: $findings,
            confidence: $contentTotal > 0 || $brandMatches->isNotEmpty() ? 72 : 45,
            missingData: $missing,
            sourcesUsed: ['content_inventory', 'page_intelligence', 'page_brand_matches'],
            sourcesNotAvailable: $missing,
            recommendedActions: ['Prioritize evidence-led and decision-stage assets before scaling content volume.'],
        );
    }
}
