<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\CompetitorTopicSignal;
use App\Models\SiteCompetitor;
use App\Models\Workspace;

class CompetitorOpportunityScorer
{
    public function __construct(private readonly CompetitorIntelligenceDedupe $dedupe) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function score(Workspace $workspace, CompetitorTopicSignal $signal, ?SiteCompetitor $competitor = null): array
    {
        $status = (string) $signal->coverage_status;
        $topic = (string) $signal->topic;
        $formats = (array) ($signal->formats ?? []);
        $intentMix = (array) ($signal->intent_mix ?? []);
        $opportunities = [];

        if ($status === 'missing') {
            $opportunities[] = $this->make($workspace, 'missing_topic', $competitor, $topic, $signal, 'Create authoritative coverage for ' . $topic, 'Argusly has no matching owned content while competitors repeat this topic.');
        }

        if ($status === 'weak') {
            $opportunities[] = $this->make($workspace, 'weak_coverage', $competitor, $topic, $signal, 'Strengthen weak coverage for ' . $topic, 'Existing content exists, but quality or AEO coverage appears below attack threshold.');
        }

        if (($formats['comparison_page'] ?? 0) > 0 || ($intentMix['comparison'] ?? 0) > 0) {
            $opportunities[] = $this->make($workspace, 'comparison_page', $competitor, $topic, $signal, 'Build a comparison page around ' . $topic, 'Competitors use comparison intent that Argusly can answer with a controlled BOFU page.', 'comparison_page');
        }

        if (($intentMix['transactional'] ?? 0) > 0 && $status !== 'covered') {
            $opportunities[] = $this->make($workspace, 'missing_bofu_page', $competitor, $topic, $signal, 'Add BOFU page for ' . $topic, 'Competitors target transactional demand and owned coverage is missing or weak.', 'landing_page');
        }

        if (($formats['implementation_guide'] ?? 0) > 0 || ($intentMix['implementation'] ?? 0) > 0) {
            $opportunities[] = $this->make($workspace, 'implementation_guide', $competitor, $topic, $signal, 'Create an implementation guide for ' . $topic, 'Implementation content can win practical AEO visibility and internal linking depth.', 'implementation_guide');
        }

        if (($formats['use_case'] ?? 0) > 0 || ($intentMix['commercial_investigation'] ?? 0) > 0) {
            $opportunities[] = $this->make($workspace, 'use_case_page', $competitor, $topic, $signal, 'Create a use case page for ' . $topic, 'Competitors frame the topic as a buying scenario that can be attacked with proof and specificity.', 'use_case');
        }

        if ($status !== 'covered' && $this->needsAnswerBlock($signal)) {
            $opportunities[] = $this->make($workspace, 'answer_block_gap', $competitor, $topic, $signal, 'Add answer blocks for ' . $topic, 'Competitor content shows answer-oriented patterns and Argusly coverage is missing or weak.', 'answer_block');
        }

        return $opportunities;
    }

    private function make(
        Workspace $workspace,
        string $type,
        ?SiteCompetitor $competitor,
        string $topic,
        CompetitorTopicSignal $signal,
        string $title,
        string $reason,
        ?string $format = null
    ): array {
        $impact = match ($signal->coverage_status) {
            'missing' => 90.0,
            'weak' => 72.0,
            default => 48.0,
        };
        $confidence = min(95.0, 45.0 + ((int) $signal->competitor_content_count * 12.0));
        $effort = in_array($type, ['answer_block_gap', 'weak_coverage'], true) ? 35.0 : 58.0;
        $priority = round(($impact * 0.48) + ($confidence * 0.34) + ((100.0 - $effort) * 0.18), 2);

        return [
            'type' => $type,
            'title' => $title,
            'topic' => $topic,
            'query_intent' => $this->topKey((array) ($signal->intent_mix ?? [])),
            'funnel_stage' => in_array($type, ['comparison_page', 'missing_bofu_page'], true) ? 'bofu' : null,
            'recommended_format' => $format,
            'priority_score' => $priority,
            'confidence_score' => $confidence,
            'impact_score' => $impact,
            'effort_score' => $effort,
            'attackable_angle' => $this->attackableAngle($type, $topic, $competitor),
            'reason' => $reason,
            'competitor_evidence' => [
                'competitor_content_count' => $signal->competitor_content_count,
                'examples' => $signal->examples,
                'formats' => $signal->formats,
                'intent_mix' => $signal->intent_mix,
            ],
            'argusly_coverage' => [
                'status' => $signal->coverage_status,
                'content_count' => $signal->argusly_content_count,
                'overlap_score' => $signal->overlap_score,
            ],
            'normalized_payload' => [
                'source' => 'competitor_intelligence',
                'type' => $type,
                'topic' => $topic,
                'recommended_format' => $format,
                'priority_score' => $priority,
            ],
            'dedupe_hash' => $this->dedupe->opportunityHash((string) $workspace->id, $type, $competitor ? (string) $competitor->id : null, $topic),
        ];
    }

    private function needsAnswerBlock(CompetitorTopicSignal $signal): bool
    {
        $examples = (array) ($signal->examples ?? []);

        return collect($examples)->contains(fn (array $example): bool => (bool) ($example['has_answer_block_pattern'] ?? false));
    }

    private function attackableAngle(string $type, string $topic, ?SiteCompetitor $competitor): string
    {
        $name = $competitor?->name ?: 'competitors';

        return match ($type) {
            'comparison_page' => 'Position Argusly against ' . $name . ' with clearer implementation proof, AI visibility outcomes, and owned answer blocks.',
            'missing_bofu_page' => 'Create a focused BOFU page that connects ' . $topic . ' to pricing, proof points, objections, and conversion paths.',
            'implementation_guide' => 'Win practical intent with a step-by-step guide, schema-ready answers, and internal links to product workflows.',
            'use_case_page' => 'Turn the topic into a use-case narrative with ICP-specific pain, trigger, workflow, proof, and CTA.',
            default => 'Publish a stronger answer than ' . $name . ' by combining topical depth, AEO blocks, examples, and internal links.',
        };
    }

    private function topKey(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        arsort($values);

        return (string) array_key_first($values);
    }
}
