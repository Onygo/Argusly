<?php

namespace App\Services\Drafts\Intelligence;

class DraftMetricScorer
{
    public function __construct(
        private readonly DraftIntelligenceRubricRegistry $rubrics,
        private readonly LlmVisibilityScorer $llmVisibilityScorer,
        private readonly BrandVoiceFitScorer $brandVoiceFitScorer,
        private readonly ConversionFitScorer $conversionFitScorer,
        private readonly TrustEvidenceScorer $trustEvidenceScorer,
        private readonly PublishReadinessEvaluator $publishReadinessEvaluator,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSections
     * @return array<string,mixed>
     */
    public function score(array $snapshot, array $signals, array $llmSections = []): array
    {
        $seo = $this->scoreSeo((array) ($signals['seo'] ?? []), (array) ($llmSections['seo'] ?? []));
        $readability = $this->scoreReadability((array) ($signals['readability'] ?? []), (array) ($llmSections['readability'] ?? []));
        $cta = $this->scoreCta((array) ($signals['cta'] ?? []), (array) ($llmSections['cta'] ?? []));
        $headings = $this->scoreHeadings((array) ($signals['headings'] ?? []), (array) ($llmSections['structure'] ?? ($llmSections['headings'] ?? [])));
        $entities = $this->scoreEntities((array) ($signals['entities'] ?? []), (array) ($llmSections['entities'] ?? []));
        $llmVisibility = $this->llmVisibilityScorer->score((array) ($signals['llm_visibility'] ?? []), (array) ($llmSections['llm_visibility'] ?? []));
        $brandVoiceFit = $this->brandVoiceFitScorer->score((array) ($signals['brand_voice_fit'] ?? []), (array) ($llmSections['brand_voice_fit'] ?? []));
        $conversionFit = $this->conversionFitScorer->score((array) ($signals['conversion_fit'] ?? []), (array) ($llmSections['conversion_fit'] ?? []));
        $trustEvidence = $this->trustEvidenceScorer->score((array) ($signals['trust_evidence'] ?? []), (array) ($llmSections['trust_evidence'] ?? []));
        $publishReadiness = $this->publishReadinessEvaluator->evaluate(
            $snapshot,
            $signals,
            [
                'seo' => $seo,
                'readability' => $readability,
                'cta' => $cta,
                'structure' => $headings,
                'llm_visibility' => $llmVisibility,
                'brand_voice_fit' => $brandVoiceFit,
                'conversion_fit' => $conversionFit,
                'trust_evidence' => $trustEvidence,
                'entities' => $entities,
            ],
            (array) ($llmSections['publish_readiness'] ?? []),
        );

        $topImprovements = $this->topImprovements([
            'seo' => $seo,
            'readability' => $readability,
            'cta' => $cta,
            'structure' => $headings,
            'llm_visibility' => $llmVisibility,
            'brand_voice_fit' => $brandVoiceFit,
            'conversion_fit' => $conversionFit,
            'trust_evidence' => $trustEvidence,
            'publish_readiness' => $publishReadiness,
        ]);

        return [
            'summary' => [
                'headline' => $this->summaryHeadline([
                    $seo['score'],
                    $readability['score'],
                    $cta['score'],
                    $headings['score'],
                    $llmVisibility['score'],
                    $brandVoiceFit['score'],
                    $conversionFit['score'],
                    $trustEvidence['score'],
                    $publishReadiness['score'],
                ]),
                'overall_explanation' => $this->overallExplanation([$seo, $readability, $cta, $headings, $llmVisibility, $brandVoiceFit, $conversionFit, $trustEvidence, $publishReadiness]),
            ],
            'sections' => [
                'seo' => $seo,
                'readability' => $readability,
                'cta' => $cta,
                'structure' => $headings,
                'llm_visibility' => $llmVisibility,
                'brand_voice_fit' => $brandVoiceFit,
                'conversion_fit' => $conversionFit,
                'trust_evidence' => $trustEvidence,
                'publish_readiness' => $publishReadiness,
                'entities' => $entities,
            ],
            'keyword_coverage' => $this->keywordCoverage((array) ($signals['seo'] ?? [])),
            'entity_coverage' => [
                'score' => $entities['score'],
                'detected_entities' => (array) ($signals['entities']['detected'] ?? []),
                'missing_entities' => (array) ($signals['entities']['missing'] ?? []),
                'explanation' => $entities['explanation'],
            ],
            'top_improvements' => $topImprovements,
        ];
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    private function scoreSeo(array $signals, array $llmSection): array
    {
        $score = 0;
        $score += ($signals['title_has_primary_keyword'] ?? false) ? 18 : 0;
        $score += ($signals['intro_has_primary_keyword'] ?? false) ? 14 : 0;
        $score += min(14, ((int) ($signals['headings_with_primary_keyword'] ?? 0)) * 7);
        $relatedTermTotal = max(1, (int) ($signals['related_term_total'] ?? 0));
        $score += (int) round(min(1, ((int) ($signals['related_terms_present'] ?? 0)) / $relatedTermTotal) * 14);
        $score += ($signals['meta_title_present'] ?? false) ? 10 : 0;
        $score += ($signals['meta_description_present'] ?? false) ? 10 : 0;
        $score += ! ($signals['keyword_stuffing_detected'] ?? false) ? 10 : 0;
        $score += ($signals['internal_link_present'] ?? false) ? 10 : 0;

        $finalScore = $this->blend($score, $llmSection['score'] ?? null);
        $band = $this->rubrics->bandForScore($finalScore, 'seo');

        $strengths = [];
        $gaps = [];

        ($signals['title_has_primary_keyword'] ?? false)
            ? $strengths[] = 'the primary keyword appears in the title'
            : $gaps[] = 'the title does not reinforce the primary keyword';
        ($signals['intro_has_primary_keyword'] ?? false)
            ? $strengths[] = 'the intro supports search intent'
            : $gaps[] = 'the intro does not reinforce the primary topic early';
        ($signals['meta_title_present'] ?? false) && ($signals['meta_description_present'] ?? false)
            ? $strengths[] = 'metadata is present'
            : $gaps[] = 'metadata coverage is incomplete';
        ($signals['keyword_stuffing_detected'] ?? false)
            ? $gaps[] = 'repetition suggests keyword stuffing'
            : $strengths[] = 'keyword use stays natural';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $this->normalizeScore($llmSection['score'] ?? null),
            'explanation' => $this->composeExplanation('SEO', $finalScore, $strengths, $gaps, 'seo'),
            'improvements' => array_values(array_filter([
                ! ($signals['title_has_primary_keyword'] ?? false) ? 'Place the primary keyword naturally in the title.' : null,
                ! ($signals['intro_has_primary_keyword'] ?? false) ? 'Introduce the primary keyword earlier in the opening paragraph.' : null,
                ! ($signals['meta_title_present'] ?? false) || ! ($signals['meta_description_present'] ?? false) ? 'Complete the SEO title and meta description.' : null,
                ($signals['keyword_stuffing_detected'] ?? false) ? 'Reduce repeated keyword phrasing to keep the copy natural.' : null,
            ])) ?: ['Keep keyword placement natural while tightening metadata support.'],
        ];
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    private function scoreReadability(array $signals, array $llmSection): array
    {
        $sentenceAverage = (float) ($signals['average_sentence_words'] ?? 0);
        $paragraphAverage = (float) ($signals['average_paragraph_words'] ?? 0);
        $denseBlocks = (int) ($signals['dense_block_count'] ?? 0);
        $score = 0;
        $score += $sentenceAverage >= 10 && $sentenceAverage <= 24 ? 22 : max(0, 22 - (int) abs($sentenceAverage - 17));
        $score += $paragraphAverage >= 20 && $paragraphAverage <= 90 ? 20 : max(0, 20 - (int) abs($paragraphAverage - 55) / 2);
        $score += ((int) ($signals['heading_count'] ?? 0)) > 0 ? 14 : 0;
        $score += ($signals['list_present'] ?? false) ? 10 : 0;
        $score += ($signals['scanability'] ?? false) ? 14 : 0;
        $score += max(0, 12 - ($denseBlocks * 4));
        $score += min(8, (int) round(((float) ($signals['transition_ratio'] ?? 0)) * 20));

        $finalScore = $this->blend($score, $llmSection['score'] ?? null);
        $band = $this->rubrics->bandForScore($finalScore, 'readability');

        $strengths = [];
        $gaps = [];

        $sentenceAverage <= 24 ? $strengths[] = 'sentence length stays reasonably controlled' : $gaps[] = 'sentences run long';
        $denseBlocks === 0 ? $strengths[] = 'paragraphs are scannable' : $gaps[] = 'dense blocks slow the reader down';
        ($signals['heading_count'] ?? 0) > 0 ? $strengths[] = 'section breaks support scanning' : $gaps[] = 'the article needs clearer visual scanning cues';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $this->normalizeScore($llmSection['score'] ?? null),
            'explanation' => $this->composeExplanation('Readability', $finalScore, $strengths, $gaps, 'readability'),
            'improvements' => array_values(array_filter([
                $sentenceAverage > 24 ? 'Shorten long sentences so the article reads more cleanly.' : null,
                $denseBlocks > 0 ? 'Break up dense paragraphs into shorter blocks.' : null,
                ! ($signals['scanability'] ?? false) ? 'Add headings or lists where they improve scanning.' : null,
            ])) ?: ['Keep the flow tight and the section pacing easy to scan.'],
        ];
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    private function scoreCta(array $signals, array $llmSection): array
    {
        $score = (int) ($signals['score'] ?? 0);
        $finalScore = $score;
        $band = $this->rubrics->bandForScore($finalScore, 'cta');

        return [
            'score' => $finalScore,
            'band_label' => (string) ($signals['band_label'] ?? $band['label']),
            'deterministic_score' => $score,
            'llm_score' => $this->normalizeScore($llmSection['score'] ?? null),
            'explanation' => (string) ($signals['explanation'] ?? 'The CTA needs a clearer next step.'),
            'improvements' => (array) ($signals['improvements'] ?? ['Add a clearer next step near the end of the draft.']),
        ];
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    private function scoreHeadings(array $signals, array $llmSection): array
    {
        $score = 0;
        $score += ($signals['h1_present'] ?? false) ? 18 : 0;
        $score += ((int) ($signals['h1_count'] ?? 0)) === 1 ? 12 : 0;
        $score += ($signals['hierarchy_consistent'] ?? false) ? 20 : max(0, 20 - (((int) ($signals['hierarchy_issue_count'] ?? 0)) * 8));
        $score += min(15, (int) round(((float) ($signals['section_coverage'] ?? 0)) * 6));
        $score += min(20, (int) round(((float) ($signals['descriptive_heading_ratio'] ?? 0)) * 20));
        $score += max(0, 15 - (((int) ($signals['duplicate_heading_count'] ?? 0)) * 8));

        $genericPenalty = ((int) ($signals['generic_heading_count'] ?? 0)) * 5;
        $score = max(0, $score - $genericPenalty);

        $finalScore = $this->blend($score, $llmSection['score'] ?? null);
        $band = $this->rubrics->bandForScore($finalScore, 'headings');

        $strengths = [];
        $gaps = [];

        ($signals['h1_present'] ?? false) ? $strengths[] = 'the article has a visible H1' : $gaps[] = 'the article needs a clear H1';
        ($signals['hierarchy_consistent'] ?? false) ? $strengths[] = 'heading levels stay consistent' : $gaps[] = 'heading levels jump inconsistently';
        ((int) ($signals['generic_heading_count'] ?? 0)) === 0 ? $strengths[] = 'headings are reasonably specific' : $gaps[] = 'some headings stay generic';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $this->normalizeScore($llmSection['score'] ?? null),
            'explanation' => $this->composeExplanation('Headings', $finalScore, $strengths, $gaps, 'headings'),
            'improvements' => array_values(array_filter([
                ! ($signals['h1_present'] ?? false) ? 'Add one clear H1 that matches the article topic.' : null,
                ! ($signals['hierarchy_consistent'] ?? false) ? 'Fix heading level jumps so the hierarchy reads consistently.' : null,
                ((int) ($signals['generic_heading_count'] ?? 0)) > 0 ? 'Replace generic headings with more descriptive section labels.' : null,
                ((int) ($signals['duplicate_heading_count'] ?? 0)) > 0 ? 'Remove duplicated heading labels.' : null,
            ])) ?: ['Keep headings specific, descriptive, and structurally consistent.'],
        ];
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    private function scoreEntities(array $signals, array $llmSection): array
    {
        $ratio = (float) ($signals['coverage_ratio'] ?? 0);
        $score = (int) round($ratio * 100);
        $finalScore = $this->blend($score, $llmSection['score'] ?? null);
        $band = $this->rubrics->bandForScore($finalScore, 'entities');

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $this->normalizeScore($llmSection['score'] ?? null),
            'explanation' => $ratio >= 0.65
                ? 'The draft covers most expected entities and supporting references.'
                : 'The draft misses several expected supporting entities or topic references.',
            'improvements' => $ratio >= 0.65
                ? ['Keep supporting examples aligned with the brief.']
                : ['Add the missing entities or supporting references that the brief expects.'],
        ];
    }

    /**
     * @param array<string,mixed> $seoSignals
     * @return array<string,mixed>
     */
    private function keywordCoverage(array $seoSignals): array
    {
        $relatedTermTotal = max(1, (int) ($seoSignals['related_term_total'] ?? 0));
        $covered = min($relatedTermTotal, (int) ($seoSignals['related_terms_present'] ?? 0));
        $score = (int) round(($covered / $relatedTermTotal) * 100);

        return [
            'score' => $score,
            'covered_terms' => (array) ($seoSignals['matched_related_terms'] ?? []),
            'missing_terms' => (array) ($seoSignals['missing_related_terms'] ?? []),
            'explanation' => $covered === $relatedTermTotal
                ? 'Secondary keyword coverage is strong across the draft.'
                : 'Some related terms from the brief are still missing or underused.',
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $sections
     * @return array<int,string>
     */
    private function topImprovements(array $sections): array
    {
        return collect($sections)
            ->sortBy(fn (array $section): int => (int) ($section['score'] ?? 0))
            ->flatMap(fn (array $section): array => (array) ($section['improvements'] ?? []))
            ->filter(fn (string $item): bool => trim($item) !== '')
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param array<int,int> $scores
     */
    private function summaryHeadline(array $scores): string
    {
        $average = (int) round(collect($scores)->avg() ?: 0);

        return match (true) {
            $average >= 81 => 'Strong draft with polished foundations',
            $average >= 61 => 'Solid draft with targeted improvements available',
            $average >= 41 => 'Promising draft with several quality gaps',
            default => 'Draft needs significant improvement before publishing',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     */
    private function overallExplanation(array $sections): string
    {
        $lowest = collect($sections)
            ->sortBy(fn (array $section): int => (int) ($section['score'] ?? 0))
            ->take(2)
            ->map(fn (array $section): string => strtolower((string) preg_replace('/^([A-Za-z]+).*/', '$1', (string) ($section['explanation'] ?? ''))))
            ->filter()
            ->values()
            ->all();

        return $lowest !== []
            ? 'The scan uses deterministic content signals as the baseline, then applies limited calibration for nuance. The weakest areas currently need the most attention.'
            : 'The scan uses deterministic content signals as the baseline for stable, explainable scores.';
    }

    /**
     * @param array<int,string> $strengths
     * @param array<int,string> $gaps
     */
    private function composeExplanation(string $label, int $score, array $strengths, array $gaps, ?string $metric = null): string
    {
        $band = $this->rubrics->bandForScore($score, $metric);
        $strengthText = $strengths !== [] ? implode(', ', array_slice($strengths, 0, 2)) : 'few clear strengths are present';
        $gapText = $gaps !== [] ? implode(', ', array_slice($gaps, 0, 2)) : 'major gaps are limited';

        return sprintf(
            '%s falls in the %s band because %s, while %s.',
            $label,
            $band['label'],
            $strengthText,
            $gapText
        );
    }

    private function blend(int $baselineScore, mixed $llmScore): int
    {
        $llmScore = $this->normalizeScore($llmScore);
        if ($llmScore === null) {
            return max(0, min(100, $baselineScore));
        }

        $blended = (int) round(($baselineScore * 0.85) + ($llmScore * 0.15));

        return max(0, min(100, $blended));
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
