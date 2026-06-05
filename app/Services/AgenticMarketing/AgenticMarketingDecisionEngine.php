<?php

namespace App\Services\AgenticMarketing;

use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentLifecycleStatus;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;

class AgenticMarketingDecisionEngine
{
    public function score(DetectedOpportunity $opportunity): DetectedOpportunity
    {
        $explanation = $this->explain($opportunity);
        $payload = $opportunity->payload;
        $payload['score_explanation'] = $explanation;

        return new DetectedOpportunity(
            title: $opportunity->title,
            type: $opportunity->type,
            priorityScore: (int) $explanation['priority_score'],
            payload: $payload,
            contentId: $opportunity->contentId,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function explain(DetectedOpportunity $opportunity): array
    {
        $signals = (array) data_get($opportunity->payload, 'signals', []);

        $impact = $this->impactScore($opportunity->type, $signals);
        $effort = $this->effortScore($opportunity->type, $signals);
        $confidence = $this->confidenceScore($opportunity->type, $signals);
        $risk = $this->riskScore($opportunity->type, $signals);

        $priority = (int) round(
            ($impact * 0.45)
            + ($confidence * 0.25)
            + ((100 - $effort) * 0.20)
            + ((100 - $risk) * 0.10)
        );

        return [
            'version' => 'deterministic_v1',
            'impact_score' => $impact,
            'effort_score' => $effort,
            'confidence_score' => $confidence,
            'risk_score' => $risk,
            'priority_score' => max(1, min(100, $priority)),
            'formula' => 'priority = impact*0.45 + confidence*0.25 + (100-effort)*0.20 + (100-risk)*0.10',
            'summary' => $this->summary($opportunity->type, $signals),
            'reasons' => $this->reasons($opportunity->type, $signals),
            'inputs' => $this->storedSignalInputs($signals),
        ];
    }

    private function impactScore(AgenticMarketingOpportunityType $type, array $signals): int
    {
        $score = match ($type) {
            AgenticMarketingOpportunityType::SeoIndexability => 68,
            AgenticMarketingOpportunityType::AiVisibility => 64,
            AgenticMarketingOpportunityType::Refresh => 62,
            AgenticMarketingOpportunityType::NewArticle => 60,
            AgenticMarketingOpportunityType::ContentNetwork => 58,
            AgenticMarketingOpportunityType::AnswerCoverage => 56,
            AgenticMarketingOpportunityType::InternalLinks => 52,
            AgenticMarketingOpportunityType::LocaleExpansion => 50,
            default => 50,
        };

        $score += $this->decayLift($signals);
        $score += min(18, count((array) ($signals['issues'] ?? [])) * 4);
        $score += min(18, count((array) ($signals['missing_locales'] ?? [])) * 7);
        $score += min(16, (int) ($signals['suggested_link_count'] ?? 0) * 4);
        $score += $this->weaknessLift((int) ($signals['ai_visibility_score'] ?? 0), threshold: 60, max: 18);
        $score += $this->weaknessLift((int) ($signals['answer_block_score'] ?? 0), threshold: 65, max: 14);
        $score += $this->weaknessLift((int) ($signals['health_score'] ?? 0), threshold: 80, max: 18);
        $score += $this->weaknessLift((int) round((float) ($signals['cluster_score'] ?? 0)), threshold: 60, max: 14);

        return $this->clamp($score);
    }

    private function effortScore(AgenticMarketingOpportunityType $type, array $signals): int
    {
        $score = match ($type) {
            AgenticMarketingOpportunityType::InternalLinks => 26,
            AgenticMarketingOpportunityType::AnswerCoverage => 34,
            AgenticMarketingOpportunityType::SeoIndexability => 42,
            AgenticMarketingOpportunityType::AiVisibility => 46,
            AgenticMarketingOpportunityType::Refresh => 52,
            AgenticMarketingOpportunityType::LocaleExpansion => 62,
            AgenticMarketingOpportunityType::ContentNetwork => 66,
            AgenticMarketingOpportunityType::NewArticle => 74,
            default => 50,
        };

        $score += min(16, max(0, count((array) ($signals['missing_locales'] ?? [])) - 1) * 6);
        $score += (($signals['gap_type'] ?? null) === 'missing_pillar') ? 10 : 0;
        $score -= min(10, count((array) ($signals['issues'] ?? [])) * 2);

        return $this->clamp($score);
    }

    private function confidenceScore(AgenticMarketingOpportunityType $type, array $signals): int
    {
        $score = 44;
        $knownInputs = 0;

        foreach ($this->storedSignalInputs($signals) as $value) {
            if ($value !== null && $value !== '' && $value !== [] && $value !== 0) {
                $knownInputs++;
            }
        }

        $score += min(32, $knownInputs * 5);
        $score += in_array($type, [
            AgenticMarketingOpportunityType::InternalLinks,
            AgenticMarketingOpportunityType::SeoIndexability,
            AgenticMarketingOpportunityType::LocaleExpansion,
        ], true) ? 10 : 0;

        return $this->clamp($score);
    }

    private function riskScore(AgenticMarketingOpportunityType $type, array $signals): int
    {
        $score = match ($type) {
            AgenticMarketingOpportunityType::InternalLinks => 18,
            AgenticMarketingOpportunityType::AnswerCoverage => 22,
            AgenticMarketingOpportunityType::SeoIndexability => 30,
            AgenticMarketingOpportunityType::Refresh => 38,
            AgenticMarketingOpportunityType::AiVisibility => 38,
            AgenticMarketingOpportunityType::LocaleExpansion => 44,
            AgenticMarketingOpportunityType::ContentNetwork => 48,
            AgenticMarketingOpportunityType::NewArticle => 56,
            default => 40,
        };

        $score += in_array('robots_noindex', (array) ($signals['issues'] ?? []), true) ? 8 : 0;
        $score += (($signals['gap_type'] ?? null) === 'missing_pillar') ? 8 : 0;
        $score -= (($signals['suggested_link_count'] ?? 0) > 0) ? 6 : 0;

        return $this->clamp($score);
    }

    /**
     * @return array<int,string>
     */
    private function reasons(AgenticMarketingOpportunityType $type, array $signals): array
    {
        $reasons = [];

        if (($signals['lifecycle_stage'] ?? null) === ContentLifecycleStatus::REFRESH_NEEDED->value) {
            $reasons[] = 'Lifecycle status says this content needs a refresh.';
        }
        if (in_array((string) ($signals['decay_risk_level'] ?? ''), [ContentDecayRiskLevel::HIGH->value, ContentDecayRiskLevel::CRITICAL->value], true)) {
            $reasons[] = 'Stored content intelligence shows elevated decay risk.';
        }
        if (($signals['suggested_link_count'] ?? 0) > 0) {
            $reasons[] = sprintf('%d internal link gap(s) are already suggested.', (int) $signals['suggested_link_count']);
        }
        if (($missing = count((array) ($signals['missing_locales'] ?? []))) > 0) {
            $reasons[] = sprintf('%d target locale variant(s) are missing.', $missing);
        }
        if (((int) ($signals['answer_block_generation_persisted_count'] ?? 1)) === 0 || ((int) ($signals['answer_block_score'] ?? 100)) < 65) {
            $reasons[] = 'Structured answer coverage is weak or missing.';
        }
        if (((int) ($signals['ai_visibility_score'] ?? 100)) < 60) {
            $reasons[] = 'AI visibility is below the target threshold.';
        }
        if (($signals['llm_tracking_signal'] ?? null) === 'missing_brand_mentions') {
            $reasons[] = 'LLM tracking shows the target brand is missing from this answer.';
        }
        if (($signals['llm_tracking_signal'] ?? null) === 'competitor_dominance') {
            $reasons[] = 'LLM tracking shows competitors are more visible than the target brand.';
        }
        if (($signals['llm_tracking_signal'] ?? null) === 'missing_owned_citations') {
            $reasons[] = 'LLM tracking found no citations to owned target URLs.';
        }
        if (($signals['llm_tracking_signal'] ?? null) === 'missing_answer_blocks_for_important_query') {
            $reasons[] = 'An important tracked query points to content without strong answer block coverage.';
        }
        if (count((array) ($signals['issues'] ?? [])) > 0) {
            $reasons[] = 'SEO/indexability checks found stored issue signals.';
        }
        if (($signals['gap_type'] ?? null) !== null || ((float) ($signals['cluster_score'] ?? 100)) < 60) {
            $reasons[] = 'Content network analysis indicates a topic coverage gap.';
        }

        return $reasons !== [] ? array_values(array_unique($reasons)) : [
            'This opportunity ranks well against the current deterministic scoring model.',
        ];
    }

    private function summary(AgenticMarketingOpportunityType $type, array $signals): string
    {
        return match ($type) {
            AgenticMarketingOpportunityType::Refresh => 'Recommended because lifecycle and decay signals indicate refresh upside.',
            AgenticMarketingOpportunityType::InternalLinks => 'Recommended because stored link intelligence found relevant internal link gaps.',
            AgenticMarketingOpportunityType::LocaleExpansion => 'Recommended because the objective targets locales that are missing from this content family.',
            AgenticMarketingOpportunityType::AnswerCoverage => 'Recommended because structured answer coverage is incomplete.',
            AgenticMarketingOpportunityType::SeoIndexability => 'Recommended because SEO/indexability issue signals reduce discoverability.',
            AgenticMarketingOpportunityType::NewArticle,
            AgenticMarketingOpportunityType::ContentNetwork => 'Recommended because content network analysis found a topic coverage gap.',
            AgenticMarketingOpportunityType::AiVisibility => 'Recommended because stored AI visibility metrics are weak.',
            default => 'Recommended by deterministic stored-signal scoring.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function storedSignalInputs(array $signals): array
    {
        return [
            'decay_risk_level' => $signals['decay_risk_level'] ?? null,
            'freshness_score' => $signals['freshness_score'] ?? null,
            'content_health_score' => $signals['content_health_score'] ?? null,
            'optimization_opportunity_score' => $signals['optimization_opportunity_score'] ?? null,
            'lifecycle_stage' => $signals['lifecycle_stage'] ?? null,
            'suggested_link_count' => $signals['suggested_link_count'] ?? null,
            'missing_locales' => $signals['missing_locales'] ?? null,
            'answer_block_score' => $signals['answer_block_score'] ?? null,
            'answer_block_generation_persisted_count' => $signals['answer_block_generation_persisted_count'] ?? null,
            'ai_visibility_score' => $signals['ai_visibility_score'] ?? null,
            'llm_tracking_signal' => $signals['llm_tracking_signal'] ?? null,
            'query_id' => $signals['query_id'] ?? null,
            'query_set_id' => $signals['query_set_id'] ?? null,
            'query_priority' => $signals['query_priority'] ?? null,
            'citation_score' => $signals['citation_score'] ?? null,
            'competitor_share_score' => $signals['competitor_share_score'] ?? null,
            'cluster_score' => $signals['cluster_score'] ?? null,
            'gap_type' => $signals['gap_type'] ?? null,
            'seo_issues' => $signals['issues'] ?? null,
            'seo_health_score' => $signals['health_score'] ?? null,
        ];
    }

    private function decayLift(array $signals): int
    {
        return match ((string) ($signals['decay_risk_level'] ?? '')) {
            ContentDecayRiskLevel::CRITICAL->value => 18,
            ContentDecayRiskLevel::HIGH->value => 12,
            ContentDecayRiskLevel::MEDIUM->value => 6,
            default => 0,
        };
    }

    private function weaknessLift(int $score, int $threshold, int $max): int
    {
        if ($score <= 0 || $score >= $threshold) {
            return 0;
        }

        return min($max, (int) round(($threshold - $score) / max(1, $threshold) * $max));
    }

    private function clamp(int|float $score): int
    {
        return max(1, min(100, (int) round($score)));
    }
}
