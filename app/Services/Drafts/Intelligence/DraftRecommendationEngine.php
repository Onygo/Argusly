<?php

namespace App\Services\Drafts\Intelligence;

use App\Models\Draft;
use App\Models\DraftAnalysis;

class DraftRecommendationEngine
{
    public function __construct(
        private readonly DraftPriorityRankingService $ranking,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function generate(Draft $draft, DraftAnalysis $analysis): array
    {
        $signals = (array) ($analysis->signals_payload ?? []);
        $payload = $analysis->canonicalPayload();
        $sections = (array) data_get($payload, 'sections', []);

        $recommendations = array_merge(
            $this->seoRecommendations((array) ($signals['seo'] ?? []), (array) ($sections['seo'] ?? [])),
            $this->readabilityRecommendations((array) ($signals['readability'] ?? []), (array) ($sections['readability'] ?? [])),
            $this->ctaRecommendations((array) ($signals['cta'] ?? []), (array) ($sections['cta'] ?? [])),
            $this->headingRecommendations((array) ($signals['headings'] ?? []), (array) ($sections['structure'] ?? [])),
            $this->llmVisibilityRecommendations((array) ($signals['llm_visibility'] ?? []), (array) ($sections['llm_visibility'] ?? [])),
            $this->brandVoiceRecommendations((array) ($signals['brand_voice_fit'] ?? []), (array) ($sections['brand_voice_fit'] ?? [])),
            $this->conversionFitRecommendations((array) ($signals['conversion_fit'] ?? []), (array) ($sections['conversion_fit'] ?? [])),
            $this->trustEvidenceRecommendations((array) ($signals['trust_evidence'] ?? []), (array) ($sections['trust_evidence'] ?? [])),
            $this->publishReadinessRecommendations((array) ($sections['publish_readiness'] ?? [])),
        );

        return $this->ranking->order($recommendations);
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function seoRecommendations(array $signals, array $section): array
    {
        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 70 && ! ($signals['title_has_primary_keyword'] ?? false)) {
            $items[] = $this->recommendation(
                'seo',
                'Add the primary keyword to the title',
                'The title does not currently reinforce the primary keyword clearly enough.',
                'Title keyword alignment is one of the strongest on-page SEO signals and improves topic clarity immediately.',
                'Rewrite the title so it includes the primary keyword naturally.',
                'high',
                'low',
                'high',
                ['signal' => 'title_has_primary_keyword', 'value' => false],
            );
        }

        if ($score < 70 && ! ($signals['intro_has_primary_keyword'] ?? false)) {
            $items[] = $this->recommendation(
                'seo',
                'Add the primary keyword to the introduction',
                'The opening section does not reinforce the main topic early enough.',
                'Search relevance improves when the article establishes the core topic near the start.',
                'Use the primary keyword naturally in the first paragraph or opening section.',
                'high',
                'low',
                'high',
                ['signal' => 'intro_has_primary_keyword', 'value' => false],
            );
        }

        if (! ($signals['meta_title_present'] ?? false) || ! ($signals['meta_description_present'] ?? false)) {
            $items[] = $this->recommendation(
                'seo',
                'Complete missing SEO metadata',
                'SEO title or meta description coverage is incomplete.',
                'Missing metadata weakens search presentation and lowers click-through potential.',
                'Fill in the SEO title and meta description with concise keyword-aligned copy.',
                'medium',
                'low',
                'high',
                ['signal' => 'metadata_present', 'value' => false],
            );
        }

        if (($signals['keyword_stuffing_detected'] ?? false) === true) {
            $items[] = $this->recommendation(
                'seo',
                'Reduce keyword repetition',
                'The draft repeats the primary keyword often enough to risk sounding unnatural.',
                'Keyword stuffing can hurt both readability and search quality.',
                'Replace repeated exact-match keyword phrases with more natural variants and supporting terms.',
                'medium',
                'medium',
                'high',
                ['signal' => 'keyword_stuffing_detected', 'value' => true],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function readabilityRecommendations(array $signals, array $section): array
    {
        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 75 && ((int) ($signals['dense_block_count'] ?? 0)) >= 2) {
            $items[] = $this->recommendation(
                'readability',
                'Break up dense paragraphs',
                'The draft still contains multiple dense blocks that slow scanning.',
                'Readers are more likely to stay engaged when paragraphs are shorter and easier to skim.',
                'Split the densest paragraphs into shorter blocks and tighten transitions.',
                'high',
                'low',
                'high',
                ['signal' => 'dense_block_count', 'value' => $signals['dense_block_count'] ?? 0],
            );
        }

        if ($score < 75 && ((float) ($signals['average_sentence_words'] ?? 0)) > 24) {
            $items[] = $this->recommendation(
                'readability',
                'Shorten long sentences',
                'Average sentence length is still high enough to reduce clarity.',
                'Long sentences make complex ideas harder to process and usually weaken flow.',
                'Shorten the longest sentences, especially in the intro and conclusion.',
                'medium',
                'low',
                'high',
                ['signal' => 'average_sentence_words', 'value' => $signals['average_sentence_words'] ?? 0],
            );
        }

        if ($score < 75 && ! ($signals['scanability'] ?? false)) {
            $items[] = $this->recommendation(
                'readability',
                'Improve scanability with headings or lists',
                'The article still reads as a wall of text.',
                'Scanable formatting helps readers find key ideas faster and reduces drop-off.',
                'Add useful section breaks or lists where they improve comprehension.',
                'medium',
                'medium',
                'medium',
                ['signal' => 'scanability', 'value' => false],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function ctaRecommendations(array $signals, array $section): array
    {
        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 61 && array_key_exists('cta_present', $signals) && ! ($signals['cta_present'] ?? false)) {
            $items[] = $this->recommendation(
                'cta',
                'Add a clear CTA to the conclusion',
                'The article does not currently give the reader a clear next step.',
                'A missing CTA is one of the fastest ways to lose momentum after the article delivers value.',
                'Add one explicit next step near the end of the article that matches the funnel stage.',
                'high',
                'low',
                'high',
                ['signal' => 'cta_present', 'value' => false],
            );
        } elseif ($score < 61 && array_key_exists('cta_near_end', $signals) && ! ($signals['cta_near_end'] ?? false)) {
            $items[] = $this->recommendation(
                'cta',
                'Move the CTA closer to the conclusion',
                'A CTA is present, but it is not positioned strongly near the end of the article.',
                'Readers are more likely to act when the CTA appears right after the article builds context.',
                'Place the CTA in the closing section and keep the next step explicit.',
                'high',
                'low',
                'high',
                ['signal' => 'cta_near_end', 'value' => false],
            );
        } elseif ($score < 70 && ($signals['weak_generic_cta'] ?? false)) {
            $items[] = $this->recommendation(
                'cta',
                'Make the CTA more specific and actionable',
                'The CTA exists, but it still feels generic or low-commitment.',
                'Specific CTAs convert better because readers understand the next step and the outcome.',
                'Clarify what the reader should do next and what decision or artifact they should leave with.',
                'high',
                'low',
                'high',
                ['signal' => 'weak_generic_cta', 'value' => true],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function headingRecommendations(array $signals, array $section): array
    {
        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if (array_key_exists('h1_present', $signals) && ! ($signals['h1_present'] ?? false)) {
            $items[] = $this->recommendation(
                'headings',
                'Add a clear H1',
                'The article is missing a visible H1.',
                'A strong H1 anchors the page topic for both readers and search engines.',
                'Add one concise H1 that reflects the main article topic.',
                'high',
                'low',
                'high',
                ['signal' => 'h1_present', 'value' => false],
            );
        }

        if ($score < 75 && array_key_exists('generic_heading_count', $signals) && ((int) ($signals['generic_heading_count'] ?? 0)) > 0) {
            $items[] = $this->recommendation(
                'headings',
                'Rewrite generic headings',
                'Some headings are still generic and do not tell the reader what each section covers.',
                'Specific headings improve scanability and make the content structure easier to trust.',
                'Replace generic section headings with descriptive, topic-led alternatives.',
                'medium',
                'low',
                'high',
                ['signal' => 'generic_heading_count', 'value' => $signals['generic_heading_count'] ?? 0],
            );
        }

        if ($score < 75 && array_key_exists('hierarchy_consistent', $signals) && ! ($signals['hierarchy_consistent'] ?? true)) {
            $items[] = $this->recommendation(
                'headings',
                'Fix heading hierarchy jumps',
                'Heading levels are not currently consistent across the article.',
                'Hierarchy issues make the article harder to scan and weaken structural clarity.',
                'Adjust heading levels so the document moves through sections in a consistent order.',
                'medium',
                'low',
                'high',
                ['signal' => 'hierarchy_consistent', 'value' => false],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function llmVisibilityRecommendations(array $signals, array $section): array
    {
        if ($signals === [] && $section === []) {
            return [];
        }

        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 70 && ! ($signals['explicit_answer_presence'] ?? false)) {
            $items[] = $this->recommendation(
                'llm_visibility',
                'Make the core answer explicit in the introduction',
                'The draft still makes readers infer the main answer instead of stating it directly.',
                'AI systems extract content more reliably when the main answer is explicit near the start.',
                'Open the article with one concise paragraph that states the core answer clearly.',
                'high',
                'low',
                'high',
                ['signal' => 'explicit_answer_presence', 'value' => false],
            );
        }

        if ($score < 70 && ! ($signals['extractable_summary_block_present'] ?? false)) {
            $items[] = $this->recommendation(
                'llm_visibility',
                'Add a concise summary block near the start',
                'The article lacks a short section that AI systems can summarize or cite cleanly.',
                'Summary-ready passages improve how easily the article can appear in AI-generated overviews.',
                'Add a short summary or key takeaways block after the introduction.',
                'high',
                'low',
                'high',
                ['signal' => 'extractable_summary_block_present', 'value' => false],
            );
        }

        if ($score < 70 && ((float) ($signals['entity_clarity_ratio'] ?? 0)) < 0.65) {
            $items[] = $this->recommendation(
                'llm_visibility',
                'Replace vague references with named entities',
                'Important actors or systems are still implied instead of named directly.',
                'Explicit entities make the content easier for AI systems to interpret and reframe accurately.',
                'Name the relevant teams, systems, or examples instead of relying on vague references.',
                'medium',
                'low',
                'high',
                ['signal' => 'entity_clarity_ratio', 'value' => $signals['entity_clarity_ratio'] ?? 0],
            );
        }

        if ($score < 65 && ! ($signals['step_based_section_present'] ?? false) && ! ($signals['comparison_pattern_present'] ?? false)) {
            $items[] = $this->recommendation(
                'llm_visibility',
                'Add a step-based or comparison section',
                'The guidance is present, but it is not packaged in a format that is easy to extract.',
                'Step-based and comparison sections are easier for AI systems to summarize without losing the logic.',
                'Add a short step-by-step section or a comparison block where it fits the topic naturally.',
                'medium',
                'medium',
                'medium',
                ['signal' => 'step_or_comparison_gap', 'value' => true],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function brandVoiceRecommendations(array $signals, array $section): array
    {
        if ($signals === [] && $section === []) {
            return [];
        }

        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 70 && ($signals['guidance_available'] ?? false) && ((float) ($signals['preferred_terminology_coverage'] ?? 0.75)) < 0.45) {
            $items[] = $this->recommendation(
                'brand_voice_fit',
                'Use approved brand terminology more consistently',
                'The draft does not yet use enough of the preferred terminology from the available brand guidance.',
                'Terminology consistency makes the article feel more intentional and recognizably on-brand.',
                'Replace generic phrasing with the approved terms or positioning language that best fit the article.',
                'medium',
                'low',
                'high',
                ['signal' => 'preferred_terminology_coverage', 'value' => $signals['preferred_terminology_coverage'] ?? 0],
            );
        }

        if ($score < 70 && ((int) ($signals['disallowed_term_hits'] ?? 0)) > 0) {
            $items[] = $this->recommendation(
                'brand_voice_fit',
                'Remove discouraged phrasing',
                'The draft still uses terms or phrasing that conflict with the current brand guidance.',
                'Off-brand phrasing weakens trust and makes the article feel less polished.',
                'Rewrite the flagged phrases with more neutral or approved alternatives.',
                'high',
                'low',
                'high',
                ['signal' => 'disallowed_term_hits', 'value' => $signals['disallowed_term_hits'] ?? 0],
            );
        }

        if ($score < 70 && ((float) ($signals['audience_fit_ratio'] ?? 0.72)) < 0.65) {
            $items[] = $this->recommendation(
                'brand_voice_fit',
                'Align the tone with the target audience',
                'The article language does not fully match the audience sophistication the brief implies.',
                'Audience-fit language improves trust, relevance, and conversion potential.',
                'Adjust sentence style and terminology so the draft sounds right for the intended audience.',
                'medium',
                'medium',
                'medium',
                ['signal' => 'audience_fit_ratio', 'value' => $signals['audience_fit_ratio'] ?? 0],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function conversionFitRecommendations(array $signals, array $section): array
    {
        if ($signals === [] && $section === []) {
            return [];
        }

        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 70 && ((int) ($signals['next_step_clarity_score'] ?? 0)) < 65) {
            $items[] = $this->recommendation(
                'conversion_fit',
                'Clarify the next step after the main argument',
                'The article does not yet guide the reader to one obvious next move.',
                'A clear next step improves conversion potential even when the CTA itself exists.',
                'Tighten the closing action so the reader understands exactly what to do and what outcome to expect.',
                'high',
                'low',
                'high',
                ['signal' => 'next_step_clarity_score', 'value' => $signals['next_step_clarity_score'] ?? 0],
            );
        }

        if ($score < 70 && ((int) ($signals['decision_support_score'] ?? 0)) < 60) {
            $items[] = $this->recommendation(
                'conversion_fit',
                'Add more decision-support before the CTA',
                'The article asks for action before giving enough support for that decision.',
                'Readers convert more readily when the article builds enough trust and context before the ask.',
                'Add one practical section, example, comparison, or implementation cue that supports the next step.',
                'high',
                'medium',
                'medium',
                ['signal' => 'decision_support_score', 'value' => $signals['decision_support_score'] ?? 0],
            );
        }

        if ($score < 70 && ((int) ($signals['promise_alignment_score'] ?? 0)) < 60) {
            $items[] = $this->recommendation(
                'conversion_fit',
                'Match the CTA more closely to the article promise',
                'The closing action does not yet feel like the natural continuation of the article.',
                'When the promise and the next step align, the CTA feels more credible and easier to act on.',
                'Rewrite the closing action so it clearly follows from the value the article just delivered.',
                'medium',
                'low',
                'high',
                ['signal' => 'promise_alignment_score', 'value' => $signals['promise_alignment_score'] ?? 0],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function trustEvidenceRecommendations(array $signals, array $section): array
    {
        if ($signals === [] && $section === []) {
            return [];
        }

        $score = (int) ($section['score'] ?? 0);
        $items = [];

        if ($score < 70 && (((int) ($signals['hype_term_count'] ?? 0)) > 0 || ((int) ($signals['overclaim_count'] ?? 0)) > 0)) {
            $items[] = $this->recommendation(
                'trust_evidence',
                'Replace vague hype with concrete framing',
                'Some claims still sound too promotional or absolute to feel fully credible.',
                'Measured language improves trust and makes recommendations more believable.',
                'Swap hype or absolute claims for concrete outcomes, examples, or bounded wording.',
                'high',
                'low',
                'high',
                ['signal' => 'hype_or_overclaim', 'value' => true],
            );
        }

        if ($score < 70 && ((int) ($signals['concrete_claim_count'] ?? 0)) === 0) {
            $items[] = $this->recommendation(
                'trust_evidence',
                'Add one concrete example or measurable detail',
                'The argument stays too abstract and would benefit from one specific example or proof point.',
                'Specific details make recommendations easier to trust and easier to remember.',
                'Add one example, timeframe, operational detail, or measurable outcome in the weakest section.',
                'high',
                'medium',
                'high',
                ['signal' => 'concrete_claim_count', 'value' => 0],
            );
        }

        if ($score < 70 && ((int) ($signals['example_count'] ?? 0)) === 0 && ((int) ($signals['evidence_signal_count'] ?? 0)) === 0) {
            $items[] = $this->recommendation(
                'trust_evidence',
                'Ground the main claim with evidence-style support',
                'The draft currently makes recommendations without enough evidence-style framing.',
                'Even practical B2B content feels more trustworthy when the argument is grounded in examples or observed patterns.',
                'Add a short example, practical observation, or evidence-style phrase that supports the main recommendation.',
                'medium',
                'medium',
                'medium',
                ['signal' => 'evidence_gap', 'value' => true],
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private function publishReadinessRecommendations(array $section): array
    {
        if ($section === []) {
            return [];
        }

        $score = (int) ($section['score'] ?? 0);
        $blockingIssues = collect((array) ($section['blocking_issues'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $nextActions = collect((array) ($section['recommended_next_actions'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();

        if ($score >= 81 && $blockingIssues->isEmpty()) {
            return [];
        }

        $title = $blockingIssues->isNotEmpty()
            ? 'Resolve blocking issues before publishing'
            : 'Close the final publish-readiness gaps';

        $summary = $blockingIssues->isNotEmpty()
            ? 'The draft still has explicit issues that should be fixed before it is considered publish-ready.'
            : 'The draft is close, but a few editorial gaps still separate it from a publish-ready state.';

        $whyItMatters = 'Publish readiness helps prevent shipping a draft that still has obvious structural, trust, or conversion gaps.';
        $suggestedAction = (string) ($nextActions->first() ?: $blockingIssues->first() ?: 'Resolve the weakest editorial issue before publishing.');

        return [
            $this->recommendation(
                'publish_readiness',
                $title,
                $summary,
                $whyItMatters,
                $suggestedAction,
                'high',
                'low',
                'high',
                [
                    'signal' => 'blocking_issues',
                    'value' => $blockingIssues->all(),
                ],
            ),
        ];
    }

    /**
     * @param array<string,mixed> $contextPayload
     * @return array<string,mixed>
     */
    private function recommendation(
        string $metricKey,
        string $title,
        string $summary,
        string $whyItMatters,
        string $suggestedAction,
        string $impactLevel,
        string $effortLevel,
        string $confidenceLevel,
        array $contextPayload = [],
    ): array {
        return [
            'metric_key' => $metricKey,
            'title' => $title,
            'summary' => $summary,
            'why_it_matters' => $whyItMatters,
            'suggested_action' => $suggestedAction,
            'impact_level' => $impactLevel,
            'effort_level' => $effortLevel,
            'confidence_level' => $confidenceLevel,
            'context_payload' => $contextPayload,
        ];
    }
}
