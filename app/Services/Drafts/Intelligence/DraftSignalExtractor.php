<?php

namespace App\Services\Drafts\Intelligence;

use App\Models\Draft;
use App\Services\Drafts\DraftCtaScoringService;
use Illuminate\Support\Str;

class DraftSignalExtractor
{
    public function __construct(
        private readonly DraftCtaScoringService $ctaScoring,
        private readonly LlmVisibilitySignalExtractor $llmVisibility,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function extract(Draft $draft, array $snapshot): array
    {
        $entitySignals = $this->extractEntitySignals($draft, $snapshot);
        $seoSignals = $this->extractSeoSignals($snapshot);
        $readabilitySignals = $this->extractReadabilitySignals($snapshot);
        $ctaSignals = $this->extractCtaSignals($snapshot);
        $headingSignals = $this->extractHeadingSignals($snapshot);
        $llmVisibilitySignals = $this->llmVisibility->extract($snapshot, $entitySignals);
        $brandVoiceSignals = $this->extractBrandVoiceSignals($snapshot);
        $trustEvidenceSignals = $this->extractTrustEvidenceSignals($snapshot);
        $conversionFitSignals = $this->extractConversionSignals($snapshot, $ctaSignals, $trustEvidenceSignals, $llmVisibilitySignals, $seoSignals);

        return [
            'seo' => $seoSignals,
            'readability' => $readabilitySignals,
            'cta' => $ctaSignals,
            'headings' => $headingSignals,
            'entities' => $entitySignals,
            'llm_visibility' => $llmVisibilitySignals,
            'brand_voice_fit' => $brandVoiceSignals,
            'conversion_fit' => $conversionFitSignals,
            'trust_evidence' => $trustEvidenceSignals,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractSeoSignals(array $snapshot): array
    {
        $primaryKeyword = $this->normalizeText((string) ($snapshot['primary_keyword'] ?? ''));
        $title = $this->normalizeText((string) ($snapshot['title'] ?? ''));
        $intro = $this->normalizeText((string) ($snapshot['intro'] ?? ''));
        $plainText = $this->normalizeText((string) ($snapshot['plain_text'] ?? ''));
        $metaTitle = trim((string) ($snapshot['seo_title'] ?? ''));
        $metaDescription = trim((string) ($snapshot['seo_meta_description'] ?? ''));
        $headings = collect((array) ($snapshot['headings'] ?? []))
            ->map(fn (array $heading): string => $this->normalizeText((string) ($heading['text'] ?? '')))
            ->all();

        $secondaryKeywords = collect((array) ($snapshot['secondary_keywords'] ?? []))
            ->map(fn (mixed $keyword): string => $this->normalizeText((string) $keyword))
            ->filter()
            ->values();

        $matchedRelatedTerms = $secondaryKeywords
            ->filter(fn (string $keyword): bool => $this->containsPhrase($plainText, $keyword))
            ->values();

        $primaryOccurrences = $primaryKeyword !== '' ? substr_count($plainText, $primaryKeyword) : 0;
        $wordCount = max(1, (int) ($snapshot['word_count'] ?? 0));
        $keywordDensity = $primaryOccurrences / $wordCount;

        return [
            'primary_keyword' => $primaryKeyword,
            'title_has_primary_keyword' => $primaryKeyword !== '' && $this->containsPhrase($title, $primaryKeyword),
            'intro_has_primary_keyword' => $primaryKeyword !== '' && $this->containsPhrase($intro, $primaryKeyword),
            'headings_with_primary_keyword' => $primaryKeyword === ''
                ? 0
                : collect($headings)->filter(fn (string $heading): bool => $this->containsPhrase($heading, $primaryKeyword))->count(),
            'related_terms_present' => $matchedRelatedTerms->count(),
            'related_term_total' => $secondaryKeywords->count(),
            'matched_related_terms' => $matchedRelatedTerms->all(),
            'missing_related_terms' => $secondaryKeywords->diff($matchedRelatedTerms)->values()->all(),
            'meta_title_present' => $metaTitle !== '',
            'meta_description_present' => $metaDescription !== '',
            'keyword_stuffing_detected' => $primaryOccurrences >= 5 && $keywordDensity > 0.035,
            'primary_keyword_occurrences' => $primaryOccurrences,
            'keyword_density' => round($keywordDensity, 4),
            'internal_link_present' => preg_match('/<a[^>]+href=("|\')(\/|#|https?:\/\/)/iu', (string) ($snapshot['content_html'] ?? '')) === 1,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractReadabilitySignals(array $snapshot): array
    {
        $paragraphs = collect((array) ($snapshot['paragraphs'] ?? []))
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->values();

        $paragraphWordCounts = $paragraphs
            ->map(fn (string $paragraph): int => $this->wordCount($paragraph));

        $wordCount = max(1, (int) ($snapshot['word_count'] ?? 0));
        $sentenceCount = max(1, (int) ($snapshot['sentence_count'] ?? 0));
        $headingCount = count((array) ($snapshot['headings'] ?? []));
        $denseBlockCount = $paragraphWordCounts->filter(fn (int $count): bool => $count > 90)->count();
        $transitionCount = $paragraphs->filter(function (string $paragraph): bool {
            return preg_match('/^(maar|dus|daarom|vervolgens|tegelijkertijd|however|therefore|meanwhile|next|because|so)\b/iu', $paragraph) === 1;
        })->count();

        return [
            'average_sentence_words' => round($wordCount / $sentenceCount, 1),
            'average_paragraph_words' => round($paragraphWordCounts->avg() ?: 0, 1),
            'heading_count' => $headingCount,
            'heading_frequency' => round($headingCount / max(1, $wordCount) * 300, 2),
            'list_present' => ((int) ($snapshot['list_count'] ?? 0)) > 0,
            'scanability' => $headingCount > 0 || ((int) ($snapshot['list_count'] ?? 0)) > 0,
            'dense_block_count' => $denseBlockCount,
            'transition_ratio' => round($transitionCount / max(1, $paragraphs->count()), 2),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractCtaSignals(array $snapshot): array
    {
        $context = [
            'title' => (string) ($snapshot['title'] ?? ''),
            'primary_keyword' => (string) ($snapshot['primary_keyword'] ?? ''),
            'secondary_keywords' => (array) ($snapshot['secondary_keywords'] ?? []),
            'call_to_action' => (string) ($snapshot['call_to_action'] ?? ''),
            'target_audience' => (string) ($snapshot['target_audience'] ?? ''),
            'funnel_stage' => (string) ($snapshot['funnel_stage'] ?? ''),
        ];

        $evaluation = $this->ctaScoring->evaluateContent((string) ($snapshot['content_html'] ?? ''), $context);
        $conclusion = $this->normalizeText((string) ($snapshot['conclusion'] ?? ''));
        $excerpt = $this->normalizeText((string) ($evaluation['cta_excerpt'] ?? ''));
        $tailCandidate = $this->normalizeText((string) collect((array) ($snapshot['cta_candidate_blocks'] ?? []))->last());

        return array_merge((array) ($evaluation['signals'] ?? []), [
            'cta_present' => (bool) ($evaluation['signals']['cta_detected'] ?? false),
            'cta_near_end' => $conclusion !== '' && (
                ($excerpt !== '' && (Str::contains($conclusion, $excerpt) || Str::contains($excerpt, $conclusion)))
                || ($tailCandidate !== '' && Str::contains($conclusion, $tailCandidate))
            ),
            'specificity_level' => (int) ($evaluation['signals']['specificity'] ?? 0),
            'topic_relevance' => (int) ($evaluation['signals']['relevance'] ?? 0),
            'funnel_stage_fit' => (int) ($evaluation['signals']['stage_fit'] ?? 0),
            'weak_generic_cta' => (int) ($evaluation['score'] ?? 0) < 41,
            'score' => (int) ($evaluation['score'] ?? 0),
            'band_label' => (string) ($evaluation['band_label'] ?? ''),
            'explanation' => (string) ($evaluation['explanation'] ?? ''),
            'improvements' => (array) ($evaluation['improvements'] ?? []),
            'cta_excerpt' => $evaluation['cta_excerpt'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractHeadingSignals(array $snapshot): array
    {
        $headings = collect((array) ($snapshot['headings'] ?? []))
            ->map(fn (array $heading): array => [
                'level' => (int) ($heading['level'] ?? 0),
                'text' => trim((string) ($heading['text'] ?? '')),
            ])
            ->filter(fn (array $heading): bool => $heading['text'] !== '')
            ->values();

        $genericPatterns = [
            'introduction',
            'introductie',
            'conclusion',
            'slot',
            'samenvatting',
            'summary',
            'overview',
            'benefits',
            'voordelen',
        ];

        $levels = $headings->pluck('level')->all();
        $hierarchyIssues = 0;
        $previousLevel = null;
        foreach ($levels as $level) {
            if ($previousLevel !== null && $level > ($previousLevel + 1)) {
                $hierarchyIssues++;
            }
            $previousLevel = $level;
        }

        $duplicateCount = $headings
            ->groupBy(fn (array $heading): string => Str::lower($heading['text']))
            ->filter(fn ($group) => $group->count() > 1)
            ->count();

        $genericCount = $headings
            ->filter(function (array $heading) use ($genericPatterns): bool {
                $normalized = Str::lower($heading['text']);

                return in_array($normalized, $genericPatterns, true);
            })
            ->count();

        $descriptiveCount = $headings
            ->filter(fn (array $heading): bool => $this->wordCount($heading['text']) >= 3)
            ->count();

        return [
            'h1_present' => $headings->contains(fn (array $heading): bool => $heading['level'] === 1),
            'h1_count' => $headings->where('level', 1)->count(),
            'heading_count' => $headings->count(),
            'hierarchy_consistent' => $hierarchyIssues === 0,
            'hierarchy_issue_count' => $hierarchyIssues,
            'duplicate_heading_count' => $duplicateCount,
            'generic_heading_count' => $genericCount,
            'descriptive_heading_ratio' => $headings->isEmpty()
                ? 0.0
                : round($descriptiveCount / $headings->count(), 2),
            'section_coverage' => round($headings->count() / max(1, (int) ($snapshot['word_count'] ?? 0)) * 300, 2),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractEntitySignals(Draft $draft, array $snapshot): array
    {
        $expected = collect((array) ($snapshot['expected_entities'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $detected = collect((array) ($snapshot['detected_entities'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();

        $plainText = $this->normalizeText((string) ($snapshot['plain_text'] ?? ''));
        $covered = $expected
            ->filter(fn (string $entity): bool => $this->containsPhrase($plainText, $this->normalizeText($entity)))
            ->values();

        return [
            'expected' => $expected->all(),
            'detected' => $detected->all(),
            'covered' => $covered->all(),
            'missing' => $expected->diff($covered)->values()->all(),
            'coverage_ratio' => $expected->isEmpty() ? 1.0 : round($covered->count() / $expected->count(), 2),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractBrandVoiceSignals(array $snapshot): array
    {
        $plainText = (string) ($snapshot['plain_text'] ?? '');
        $intro = (string) ($snapshot['intro'] ?? '');
        $conclusion = (string) ($snapshot['conclusion'] ?? '');
        $ctaExcerpt = (string) collect((array) ($snapshot['cta_candidate_blocks'] ?? []))->last();
        $targetAudience = trim((string) (($snapshot['target_audience'] ?? '') ?: data_get($snapshot, 'company_profile.target_audience', '')));
        $toneGuidance = trim(implode(' ', array_filter([
            (string) ($snapshot['tone_of_voice'] ?? ''),
            (string) data_get($snapshot, 'brand_voice.tone_of_voice', ''),
            (string) data_get($snapshot, 'brand_voice.writing_style', ''),
            (string) data_get($snapshot, 'brand_voice.style_guide', ''),
        ])));

        $preferred = collect((array) data_get($snapshot, 'brand_voice.preferred_terminology', []))
            ->map(fn (mixed $term): string => $this->normalizeText((string) $term))
            ->filter()
            ->values();
        $disallowed = collect((array) data_get($snapshot, 'brand_voice.disallowed_terminology', []))
            ->map(fn (mixed $term): string => $this->normalizeText((string) $term))
            ->filter()
            ->values();
        $valueProps = collect((array) data_get($snapshot, 'company_profile.value_propositions', []))
            ->map(fn (mixed $term): string => $this->normalizeText((string) $term))
            ->filter()
            ->values();

        $plainNormalized = $this->normalizeText($plainText);
        $preferredMatches = $preferred->filter(fn (string $term): bool => $this->containsPhrase($plainNormalized, $term));
        $disallowedMatches = $disallowed->filter(fn (string $term): bool => $this->containsPhrase($plainNormalized, $term));
        $valuePropMatches = $valueProps->filter(fn (string $term): bool => $this->containsPhrase($plainNormalized, $term));

        $audienceClass = $this->audienceClass($targetAudience);
        $segmentRegisters = collect([$intro, $conclusion, $ctaExcerpt])
            ->map(fn (string $segment): string => $this->registerLabel($segment))
            ->filter()
            ->values();
        $dominantRegister = (string) ($segmentRegisters->countBy()->sortDesc()->keys()->first() ?? 'neutral');

        return [
            'guidance_available' => $toneGuidance !== '' || $preferred->isNotEmpty() || $disallowed->isNotEmpty() || $targetAudience !== '' || $valueProps->isNotEmpty(),
            'target_audience' => $targetAudience,
            'audience_class' => $audienceClass,
            'tone_consistency_ratio' => $segmentRegisters->isEmpty()
                ? 0.75
                : round($segmentRegisters->filter(fn (string $label): bool => $label === $dominantRegister || $label === 'neutral')->count() / $segmentRegisters->count(), 2),
            'formality_fit_ratio' => round($this->formalityFit($toneGuidance, $plainText), 2),
            'audience_fit_ratio' => round($this->audienceFit($audienceClass, $plainNormalized), 2),
            'jargon_fit_ratio' => round($this->jargonFit($audienceClass, $plainNormalized), 2),
            'preferred_terminology_coverage' => $preferred->isEmpty() ? 0.75 : round($preferredMatches->count() / $preferred->count(), 2),
            'preferred_terminology_matches' => $preferredMatches->all(),
            'disallowed_term_hits' => $disallowedMatches->count(),
            'disallowed_terms_used' => $disallowedMatches->all(),
            'value_prop_alignment_ratio' => $valueProps->isEmpty() ? 0.7 : round($valuePropMatches->count() / $valueProps->count(), 2),
            'intro_cta_consistency' => $this->registerLabel($intro) === 'neutral'
                || $this->registerLabel($ctaExcerpt) === 'neutral'
                || $this->registerLabel($intro) === $this->registerLabel($ctaExcerpt),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function extractTrustEvidenceSignals(array $snapshot): array
    {
        $paragraphs = collect((array) ($snapshot['paragraphs'] ?? []))
            ->map(fn (mixed $paragraph): string => trim((string) $paragraph))
            ->filter()
            ->values();
        $plainText = (string) ($snapshot['plain_text'] ?? '');
        $conclusion = (string) ($snapshot['conclusion'] ?? '');
        $proofPoints = collect((array) data_get($snapshot, 'company_profile.proof_points', []))
            ->map(fn (mixed $item): string => $this->normalizeText((string) $item))
            ->filter()
            ->values();
        $normalizedPlainText = $this->normalizeText($plainText);

        $concreteClaimCount = $paragraphs->filter(function (string $paragraph): bool {
            return preg_match('/(\b\d+\b|%|\b(day|days|week|weeks|month|months|jaar|jaren|dagen|weken|maanden|stap|stappen|pilot)\b)/iu', $paragraph) === 1;
        })->count();

        $exampleCount = $paragraphs->filter(function (string $paragraph): bool {
            return preg_match('/\b(for example|for instance|example|voorbeeld|bijvoorbeeld|zoals|in practice|in de praktijk|case study)\b/iu', $paragraph) === 1;
        })->count();

        $evidenceSignalCount = $paragraphs->filter(function (string $paragraph): bool {
            return preg_match('/\b(according to|research|study|data|benchmark|survey|observed|based on|onderzoek|data|bron|ervarings|in de praktijk)\b/iu', $paragraph) === 1;
        })->count();

        $hypeTerms = preg_match_all('/\b(revolutionary|game-changing|best-in-class|world-class|effortless|seamless|ultimate|guaranteed|perfect|unmatched|baanbrekend|revolutionair|naadloos|altijd|nooit)\b/iu', $plainText, $matches);
        $overclaims = preg_match_all('/\b(always|never|guarantee|guaranteed|every|all|without fail|eliminate|completely|altijd|nooit|iedereen|volledig)\b/iu', $plainText, $overclaimMatches);

        $proofPointMatches = $proofPoints->filter(fn (string $term): bool => $this->containsPhrase($normalizedPlainText, $term));

        return [
            'concrete_claim_count' => $concreteClaimCount,
            'example_count' => $exampleCount,
            'evidence_signal_count' => $evidenceSignalCount,
            'balanced_wording_present' => preg_match('/\b(can|may|often|typically|usually|kan|kan helpen|vaak|meestal|typisch)\b/iu', $plainText) === 1,
            'hype_term_count' => $hypeTerms === false ? 0 : $hypeTerms,
            'overclaim_count' => $overclaims === false ? 0 : $overclaims,
            'proof_point_coverage_ratio' => $proofPoints->isEmpty() ? 0.65 : round($proofPointMatches->count() / $proofPoints->count(), 2),
            'recommendation_clarity_score' => $this->recommendationClarityScore($conclusion),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $ctaSignals
     * @param array<string,mixed> $trustSignals
     * @param array<string,mixed> $llmVisibilitySignals
     * @param array<string,mixed> $seoSignals
     * @return array<string,mixed>
     */
    private function extractConversionSignals(
        array $snapshot,
        array $ctaSignals,
        array $trustSignals,
        array $llmVisibilitySignals,
        array $seoSignals,
    ): array {
        $callToAction = $this->normalizeText((string) ($snapshot['call_to_action'] ?? ''));
        $ctaExcerpt = $this->normalizeText((string) ($ctaSignals['cta_excerpt'] ?? ''));
        $funnelStage = strtolower(trim((string) ($snapshot['funnel_stage'] ?? 'consideration')));
        $stepSupport = ((int) ($snapshot['step_section_count'] ?? 0)) > 0;
        $comparisonSupport = ((int) ($snapshot['comparison_section_count'] ?? 0)) > 0;
        $faqSupport = ((int) ($snapshot['faq_section_count'] ?? 0)) > 0;
        $summarySupport = ((int) ($snapshot['summary_section_count'] ?? 0)) > 0;

        $decisionSupport = 18
            + ($stepSupport ? 24 : 0)
            + ($comparisonSupport ? 18 : 0)
            + ($faqSupport ? 12 : 0)
            + ($summarySupport ? 12 : 0)
            + min(16, ((int) ($trustSignals['concrete_claim_count'] ?? 0)) * 4)
            + (($llmVisibilitySignals['explicit_answer_presence'] ?? false) ? 10 : 0);
        $decisionSupport = max(0, min(100, $decisionSupport));

        $nextStepClarity = (int) round((
            ((int) ($ctaSignals['clarity'] ?? 0))
            + ((int) ($ctaSignals['actionability'] ?? 0))
            + ((int) ($ctaSignals['specificity_level'] ?? 0))
            + (($ctaSignals['cta_near_end'] ?? false) ? 20 : 0)
        ) / 4);

        $internalPath = (($seoSignals['internal_link_present'] ?? false) ? 34 : 10)
            + (($ctaSignals['cta_present'] ?? false) ? 36 : 0)
            + (($ctaSignals['cta_near_end'] ?? false) ? 20 : 0)
            + ($callToAction !== '' && $ctaExcerpt !== '' && $this->sharedTokenRatio($callToAction, $ctaExcerpt) >= 0.35 ? 10 : 0);
        $internalPath = max(0, min(100, $internalPath));

        $promiseAlignment = (int) round((
            ((int) ($ctaSignals['topic_relevance'] ?? 0))
            + ($callToAction !== '' && $ctaExcerpt !== '' ? (int) round($this->sharedTokenRatio($callToAction, $ctaExcerpt) * 100) : 60)
        ) / 2);

        return [
            'funnel_stage' => $funnelStage,
            'cta_present' => (bool) ($ctaSignals['cta_present'] ?? false),
            'cta_stage_fit_score' => (int) ($ctaSignals['funnel_stage_fit'] ?? 0),
            'cta_relevance_score' => (int) ($ctaSignals['topic_relevance'] ?? 0),
            'next_step_clarity_score' => $nextStepClarity,
            'decision_support_score' => $decisionSupport,
            'internal_conversion_path_score' => $internalPath,
            'promise_alignment_score' => $promiseAlignment,
        ];
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return Str::contains($haystack, $needle);
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::of($value)->lower()->trim()->toString();
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\pL\pN\']+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function audienceClass(string $targetAudience): string
    {
        $targetAudience = $this->normalizeText($targetAudience);

        return match (true) {
            preg_match('/\b(cto|developer|engineer|technical|architect|operations|devops|it)\b/u', $targetAudience) === 1 => 'technical',
            preg_match('/\b(ceo|cmo|executive|leader|manager|director|founder|board)\b/u', $targetAudience) === 1 => 'executive',
            preg_match('/\b(marketing|content|seo|growth|sales)\b/u', $targetAudience) === 1 => 'marketing',
            $targetAudience !== '' => 'business',
            default => 'general',
        };
    }

    private function registerLabel(string $text): string
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return 'neutral';
        }

        $conversational = preg_match_all('/\b(you|your|we|we\'re|let\'s|je|jij|jouw|we|laten we)\b/u', $normalized);
        $formal = preg_match_all('/\b(strategy|operational|implementation|framework|governance|executive|strategisch|operationeel|implementatie|raamwerk)\b/u', $normalized);
        $exclamations = substr_count($text, '!');

        if (($formal ?: 0) > ($conversational ?: 0) + $exclamations) {
            return 'formal';
        }

        if (($conversational ?: 0) + $exclamations > 1) {
            return 'conversational';
        }

        return 'neutral';
    }

    private function formalityFit(string $toneGuidance, string $plainText): float
    {
        $toneGuidance = $this->normalizeText($toneGuidance);
        $plainNormalized = $this->normalizeText($plainText);
        $contractions = preg_match_all('/\b(don\'t|can\'t|it\'s|we\'re|that\'s|isn\'t|won\'t)\b/u', $plainNormalized);
        $secondPerson = preg_match_all('/\b(you|your|je|jij|jouw)\b/u', $plainNormalized);
        $exclamations = substr_count($plainText, '!');

        if (preg_match('/\b(formal|professional|executive|precise|authoritative|expert)\b/u', $toneGuidance) === 1) {
            return ($contractions ?: 0) === 0 && $exclamations === 0 ? 0.9 : 0.55;
        }

        if (preg_match('/\b(conversational|friendly|approachable|human|warm)\b/u', $toneGuidance) === 1) {
            return (($secondPerson ?: 0) > 2 || ($contractions ?: 0) > 0) ? 0.85 : 0.6;
        }

        return 0.72;
    }

    private function audienceFit(string $audienceClass, string $plainText): float
    {
        $technicalHits = preg_match_all('/\b(api|workflow|automation|architecture|integration|operations|pilot|process|implementation|stack)\b/u', $plainText);
        $executiveHits = preg_match_all('/\b(strategy|roi|revenue|budget|risk|leadership|growth|decision|governance)\b/u', $plainText);
        $marketingHits = preg_match_all('/\b(content|seo|campaign|audience|lead|conversion|channel|editorial)\b/u', $plainText);

        return match ($audienceClass) {
            'technical' => min(1.0, 0.45 + (($technicalHits ?: 0) * 0.08)),
            'executive' => min(1.0, 0.45 + (($executiveHits ?: 0) * 0.08)),
            'marketing' => min(1.0, 0.45 + (($marketingHits ?: 0) * 0.08)),
            'business' => min(1.0, 0.5 + ((($executiveHits ?: 0) + ($marketingHits ?: 0)) * 0.04)),
            default => 0.72,
        };
    }

    private function jargonFit(string $audienceClass, string $plainText): float
    {
        $jargonHits = preg_match_all('/\b(api|architecture|workflow|integration|orchestration|telecom|automation|governance|benchmark|operational)\b/u', $plainText);
        $wordCount = max(1, $this->wordCount($plainText));
        $density = ($jargonHits ?: 0) / $wordCount;

        return match ($audienceClass) {
            'technical' => $density >= 0.03 ? 0.85 : 0.6,
            'executive', 'business' => $density >= 0.01 && $density <= 0.04 ? 0.82 : 0.62,
            default => $density <= 0.03 ? 0.8 : 0.55,
        };
    }

    private function recommendationClarityScore(string $conclusion): int
    {
        if (trim($conclusion) === '') {
            return 20;
        }

        $actionSignals = preg_match_all('/\b(plan|book|request|schedule|contact|start|begin|use|download|review|boek|vraag|plan|start|gebruik|bepaal)\b/iu', $conclusion);
        $specificSignals = preg_match_all('/(\b\d+\b|%|\b(day|days|week|weeks|month|months|dag|dagen|week|weken|maand|maanden|pilot|checklist|gesprek)\b)/iu', $conclusion);

        return max(0, min(100, 28 + (($actionSignals ?: 0) * 18) + (($specificSignals ?: 0) * 12)));
    }

    private function sharedTokenRatio(string $left, string $right): float
    {
        $leftTokens = collect($this->meaningfulTokens($left));
        $rightTokens = collect($this->meaningfulTokens($right));

        if ($leftTokens->isEmpty() || $rightTokens->isEmpty()) {
            return 0.0;
        }

        return round($leftTokens->intersect($rightTokens)->count() / max(1, $leftTokens->count()), 2);
    }

    /**
     * @return array<int,string>
     */
    private function meaningfulTokens(string $text): array
    {
        preg_match_all('/[\pL\pN\']+/u', $this->normalizeText($text), $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->values()
            ->all();
    }
}
