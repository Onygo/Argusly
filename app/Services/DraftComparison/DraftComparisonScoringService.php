<?php

namespace App\Services\DraftComparison;

use App\Models\BrandVoice;
use App\Models\Content;
use App\Models\ContentAiSeoScore;
use App\Models\Draft;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Services\Drafts\DraftCtaScoringService;
use Illuminate\Support\Str;

class DraftComparisonScoringService
{
    /**
     * @var array<string,array{label:string,group:string,source_type:string}>
     */
    private const METRIC_DEFINITIONS = [
        'word_count' => ['label' => 'Word Count', 'group' => 'content', 'source_type' => 'derived'],
        'reading_time' => ['label' => 'Reading Time', 'group' => 'content', 'source_type' => 'derived'],
        'seo_score' => ['label' => 'SEO Score', 'group' => 'seo', 'source_type' => 'heuristic'],
        'ai_seo_score' => ['label' => 'AI SEO Score', 'group' => 'seo', 'source_type' => 'heuristic'],
        'readability_score' => ['label' => 'Readability Score', 'group' => 'content', 'source_type' => 'heuristic'],
        'brand_voice_match' => ['label' => 'Brand Voice Match', 'group' => 'brand', 'source_type' => 'heuristic'],
        'cta_strength' => ['label' => 'CTA Strength', 'group' => 'conversion', 'source_type' => 'heuristic'],
        'structure_quality' => ['label' => 'Structure Quality', 'group' => 'content', 'source_type' => 'heuristic'],
        'topical_coverage' => ['label' => 'Topical Coverage', 'group' => 'seo', 'source_type' => 'heuristic'],
        'entity_coverage' => ['label' => 'Entity Coverage', 'group' => 'quality', 'source_type' => 'heuristic'],
        'factual_confidence' => ['label' => 'Factual Confidence', 'group' => 'quality', 'source_type' => 'heuristic'],
        'conversion_focus' => ['label' => 'Conversion Focus', 'group' => 'conversion', 'source_type' => 'heuristic'],
    ];

    private const SCORING_VERSION = 'draft_compare_v2';

    public function __construct(
        private readonly DraftCtaScoringService $ctaScoring,
    ) {}

    /**
     * @return array<string,string>
     */
    public function metricSourceLegend(): array
    {
        return [
            'derived' => 'Measured directly from the generated draft output.',
            'heuristic' => 'Calculated using transparent rule-based heuristics.',
            'existing_signal' => 'Reused from existing analytics or previously computed signals.',
        ];
    }

    /**
     * @return array{metrics:array<string,mixed>,score_rows:array<int,array<string,mixed>>}
     */
    public function evaluateDraft(Draft $draft): array
    {
        $draft->loadMissing('analysis');

        $html = (string) ($draft->content_html ?? '');
        $plainTextOriginal = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $plainText = mb_strtolower($plainTextOriginal);
        $wordCount = $plainTextOriginal === '' ? 0 : str_word_count($plainTextOriginal);
        $readingTime = $wordCount > 0 ? max(1, (int) ceil($wordCount / 220)) : null;

        $brandVoice = $this->resolveBrandVoice($draft);
        $brandVoiceMatch = $this->brandVoiceMatchDetails($plainText, $brandVoice);
        $ctaAssessment = $this->ctaScoring->evaluateDraft($draft);
        $ctaStrength = [
            'score' => (int) ($ctaAssessment['score'] ?? 0),
            'explanation' => (string) ($ctaAssessment['explanation'] ?? ''),
        ];
        $structureQuality = $this->structureQualityDetails($html, $plainTextOriginal);
        $readability = $this->readabilityScoreDetails($plainTextOriginal, $wordCount);
        $primaryKeyword = $this->resolvePrimaryKeyword($draft);
        $seoScore = $this->seoScoreDetails($draft, $html, $plainText, $primaryKeyword);
        $topicalCoverage = $this->topicalCoverageDetails($draft, $plainText);
        $entityCoverage = $this->entityCoverageDetails($plainTextOriginal, $wordCount);
        $factualConfidence = $this->factualConfidenceDetails($plainTextOriginal, $plainText);
        $conversionFocus = $this->conversionFocusDetails($plainText, (int) ($ctaStrength['score'] ?? 0));
        $aiSeoScore = $this->aiSeoScoreDetails(
            draft: $draft,
            seoScore: (float) ($seoScore['score'] ?? 0),
            readabilityScore: isset($readability['score']) ? (float) $readability['score'] : null,
            structureQuality: (float) ($structureQuality['score'] ?? 0),
        );

        $metrics = [
            'word_count' => $wordCount,
            'reading_time' => $readingTime,
            // Backward-compatible key used in current Draft Compare item cards.
            'reading_time_minutes' => $readingTime,
            'seo_score' => $seoScore['score'],
            'ai_seo_score' => $aiSeoScore['score'],
            'readability_score' => $readability['score'],
            'brand_voice_match' => $brandVoiceMatch['score'],
            'cta_strength' => $ctaStrength['score'],
            'structure_quality' => $structureQuality['score'],
            'topical_coverage' => $topicalCoverage['score'],
            'entity_coverage' => $entityCoverage['score'],
            'factual_confidence' => $factualConfidence['score'],
            'conversion_focus' => $conversionFocus['score'],
            'meta_description_length' => Str::length((string) ($draft->seo_meta_description ?? '')),
            'has_focus_keyword' => $primaryKeyword !== null,
            'scored_at' => now()->toIso8601String(),
            'scoring_version' => self::SCORING_VERSION,
        ];

        $explanations = [
            'word_count' => sprintf('Detected %d words in rendered draft body.', $wordCount),
            'reading_time' => $readingTime !== null
                ? sprintf('Estimated %d minute read at 220 words per minute.', $readingTime)
                : 'Reading time unavailable because the draft has no body text.',
            'seo_score' => (string) ($seoScore['explanation'] ?? ''),
            'ai_seo_score' => (string) ($aiSeoScore['explanation'] ?? ''),
            'readability_score' => (string) ($readability['explanation'] ?? ''),
            'brand_voice_match' => (string) ($brandVoiceMatch['explanation'] ?? ''),
            'cta_strength' => (string) ($ctaStrength['explanation'] ?? ''),
            'structure_quality' => (string) ($structureQuality['explanation'] ?? ''),
            'topical_coverage' => (string) ($topicalCoverage['explanation'] ?? ''),
            'entity_coverage' => (string) ($entityCoverage['explanation'] ?? ''),
            'factual_confidence' => (string) ($factualConfidence['explanation'] ?? ''),
            'conversion_focus' => (string) ($conversionFocus['explanation'] ?? ''),
        ];

        $analysis = $draft->analysis;
        if ($analysis) {
            $analysisSections = is_array(data_get($analysis->suggestions, 'sections'))
                ? data_get($analysis->suggestions, 'sections')
                : [];

            $metrics = array_replace($metrics, array_filter([
                'seo_score' => $analysis->seo_score,
                'ai_seo_score' => $analysis->seo_score,
                'readability_score' => $analysis->readability_score,
                'cta_strength' => $analysis->cta_score,
                'structure_quality' => $this->scoreFromSection($analysisSections, 'structure'),
                'topical_coverage' => $analysis->keyword_coverage,
                'entity_coverage' => $analysis->entity_coverage,
                'conversion_focus' => $analysis->cta_score,
            ], static fn ($value) => $value !== null));

            $explanations = array_replace($explanations, array_filter([
                'seo_score' => (string) data_get($analysisSections, 'seo.explanation', $explanations['seo_score']),
                'ai_seo_score' => (string) data_get($analysisSections, 'seo.explanation', $explanations['ai_seo_score']),
                'readability_score' => (string) data_get($analysisSections, 'readability.explanation', $explanations['readability_score']),
                'cta_strength' => (string) data_get($analysisSections, 'cta.explanation', $explanations['cta_strength']),
                'structure_quality' => (string) data_get($analysisSections, 'structure.explanation', $explanations['structure_quality']),
                'topical_coverage' => (string) data_get($analysis->suggestions, 'keyword_coverage.explanation', $explanations['topical_coverage']),
                'entity_coverage' => (string) data_get($analysis->suggestions, 'entity_coverage.explanation', $explanations['entity_coverage']),
                'conversion_focus' => (string) data_get($analysisSections, 'cta.explanation', $explanations['conversion_focus']),
            ], static fn ($value) => trim((string) $value) !== ''));
        }

        $metricSourceTypes = $this->defaultMetricSourceTypes();
        $metricSourceTypes['ai_seo_score'] = (string) ($aiSeoScore['source_type'] ?? ($metricSourceTypes['ai_seo_score'] ?? 'heuristic'));
        if ($analysis) {
            foreach (['seo_score', 'ai_seo_score', 'readability_score', 'cta_strength', 'structure_quality', 'topical_coverage', 'entity_coverage', 'conversion_focus'] as $metricKey) {
                if (($metrics[$metricKey] ?? null) !== null) {
                    $metricSourceTypes[$metricKey] = 'existing_signal';
                }
            }
        }
        $metrics['metric_source_types'] = $metricSourceTypes;

        $scoreRows = $this->buildScoreRows($metrics, $explanations, $metricSourceTypes);

        return [
            'metrics' => $metrics,
            'score_rows' => $scoreRows,
        ];
    }

    /**
     * @param array<string,mixed> $sections
     */
    private function scoreFromSection(array $sections, string $key): ?int
    {
        $score = data_get($sections, $key . '.score');

        return is_numeric($score) ? (int) round((float) $score) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function scoreDraft(Draft $draft): array
    {
        return $this->evaluateDraft($draft)['metrics'];
    }

    /**
     * @param array<int,array<string,mixed>> $scoreRows
     */
    public function replaceVariantScores(DraftComparisonVariant $variant, array $scoreRows): void
    {
        DraftComparisonScore::query()
            ->where('draft_comparison_variant_id', $variant->id)
            ->delete();

        foreach ($scoreRows as $row) {
            DraftComparisonScore::query()->create([
                'draft_comparison_variant_id' => (string) $variant->id,
                'metric_key' => (string) ($row['metric_key'] ?? ''),
                'metric_label' => (string) ($row['metric_label'] ?? ''),
                'metric_group' => isset($row['metric_group']) ? (string) $row['metric_group'] : null,
                'source_type' => isset($row['source_type']) && trim((string) $row['source_type']) !== ''
                    ? (string) $row['source_type']
                    : null,
                'numeric_score' => is_numeric($row['numeric_score'] ?? null)
                    ? round((float) $row['numeric_score'], 3)
                    : null,
                'text_score' => isset($row['text_score']) && trim((string) $row['text_score']) !== ''
                    ? (string) $row['text_score']
                    : null,
                'explanation' => isset($row['explanation']) && trim((string) $row['explanation']) !== ''
                    ? mb_substr((string) $row['explanation'], 0, 2000)
                    : null,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,string> $explanations
     * @param array<string,string> $metricSourceTypes
     * @return array<int,array<string,mixed>>
     */
    private function buildScoreRows(array $metrics, array $explanations, array $metricSourceTypes): array
    {
        $rows = [];

        foreach (self::METRIC_DEFINITIONS as $key => $definition) {
            $rawValue = $metrics[$key] ?? null;
            $numericScore = is_numeric($rawValue) ? round((float) $rawValue, 3) : null;
            $textScore = $numericScore === null && $rawValue !== null
                ? trim((string) $rawValue)
                : null;

            $rows[] = [
                'metric_key' => $key,
                'metric_label' => $definition['label'],
                'metric_group' => $definition['group'],
                'source_type' => (string) ($metricSourceTypes[$key] ?? ($definition['source_type'] ?? 'heuristic')),
                'numeric_score' => $numericScore,
                'text_score' => $textScore,
                'explanation' => mb_substr((string) ($explanations[$key] ?? ''), 0, 2000),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,string>
     */
    private function defaultMetricSourceTypes(): array
    {
        return collect(self::METRIC_DEFINITIONS)
            ->mapWithKeys(static fn (array $definition, string $metric): array => [
                $metric => (string) ($definition['source_type'] ?? 'heuristic'),
            ])
            ->all();
    }

    /**
     * @return array{score:?int,explanation:string}
     */
    private function brandVoiceMatchDetails(string $plainText, ?BrandVoice $brandVoice): array
    {
        if ($brandVoice === null || trim($plainText) === '') {
            return [
                'score' => null,
                'explanation' => 'No brand voice profile available for this draft context.',
            ];
        }

        $preferred = collect($brandVoice->preferredTerminologyArray())
            ->map(fn (string $item): string => mb_strtolower(trim($item)))
            ->filter()
            ->values();
        $disallowed = collect($brandVoice->disallowedTerminologyArray())
            ->map(fn (string $item): string => mb_strtolower(trim($item)))
            ->filter()
            ->values();

        $preferredHits = 0;
        if ($preferred->isNotEmpty()) {
            $preferredHits = $preferred->filter(fn (string $needle): bool => str_contains($plainText, $needle))->count();
        }

        $disallowedHits = 0;
        if ($disallowed->isNotEmpty()) {
            $disallowedHits = $disallowed->filter(fn (string $needle): bool => str_contains($plainText, $needle))->count();
        }

        $score = 65;
        if ($preferred->isNotEmpty()) {
            $score = (int) round(40 + (($preferredHits / max(1, $preferred->count())) * 60));
        }
        if ($disallowedHits > 0) {
            $score -= min(40, $disallowedHits * 10);
        }

        $score = (int) max(0, min(100, $score));

        return [
            'score' => $score,
            'explanation' => sprintf(
                'Matched %d/%d preferred terms with %d disallowed term hits.',
                $preferredHits,
                $preferred->count(),
                $disallowedHits
            ),
        ];
    }

    /**
     * @return array{score:int,explanation:string}
     */
    private function ctaStrengthDetails(string $plainText): array
    {
        if (trim($plainText) === '') {
            return [
                'score' => 0,
                'explanation' => 'Draft has no body text, so no call-to-action signals were detected.',
            ];
        }

        $length = mb_strlen($plainText);
        $tail = $length > 0 ? mb_substr($plainText, (int) max(0, $length - ($length * 0.35))) : $plainText;

        $ctaPhrases = [
            'book a demo',
            'request a demo',
            'schedule a demo',
            'start free',
            'start your trial',
            'contact us',
            'talk to sales',
            'get started',
            'download',
            'subscribe',
            'learn more',
        ];

        $phraseHits = collect($ctaPhrases)
            ->filter(fn (string $needle): bool => str_contains($tail, $needle))
            ->count();

        $imperativeSignals = preg_match_all('/\b(start|book|request|contact|download|schedule|subscribe|discover|explore|try)\b/u', $tail);
        $imperativeSignals = $imperativeSignals === false ? 0 : $imperativeSignals;

        $score = 20 + ($phraseHits * 20) + min(20, (int) $imperativeSignals * 3);
        $score = (int) max(0, min(100, $score));

        return [
            'score' => $score,
            'explanation' => sprintf(
                'Detected %d CTA phrase hits and %d imperative signals near the end of the draft.',
                $phraseHits,
                (int) $imperativeSignals
            ),
        ];
    }

    /**
     * @return array{score:int,explanation:string}
     */
    private function structureQualityDetails(string $html, string $plainText): array
    {
        if (trim($html) === '' || trim($plainText) === '') {
            return [
                'score' => 0,
                'explanation' => 'No rendered structure found in this draft output.',
            ];
        }

        $h2Count = preg_match_all('/<h2\b[^>]*>/i', $html);
        $h3Count = preg_match_all('/<h3\b[^>]*>/i', $html);
        $paragraphCount = preg_match_all('/<p\b[^>]*>/i', $html);

        $h2Count = $h2Count === false ? 0 : $h2Count;
        $h3Count = $h3Count === false ? 0 : $h3Count;
        $paragraphCount = $paragraphCount === false ? 0 : $paragraphCount;

        $wordCount = str_word_count($plainText);
        $avgParagraphWords = $paragraphCount > 0 ? $wordCount / $paragraphCount : $wordCount;

        $score = 0;
        $score += min(35, $h2Count * 8);
        $score += min(20, $h3Count * 4);
        $score += min(30, $paragraphCount * 2);

        if ($avgParagraphWords >= 40 && $avgParagraphWords <= 130) {
            $score += 15;
        } elseif ($avgParagraphWords >= 25 && $avgParagraphWords <= 170) {
            $score += 8;
        }

        $score = (int) max(0, min(100, $score));

        return [
            'score' => $score,
            'explanation' => sprintf(
                'Found %d H2, %d H3, %d paragraphs; average paragraph length %.1f words.',
                $h2Count,
                $h3Count,
                $paragraphCount,
                $avgParagraphWords
            ),
        ];
    }

    /**
     * @return array{score:?float,explanation:string}
     */
    private function readabilityScoreDetails(string $plainText, int $wordCount): array
    {
        if ($wordCount <= 0 || trim($plainText) === '') {
            return [
                'score' => null,
                'explanation' => 'Readability cannot be measured because no text was generated.',
            ];
        }

        $sentenceCount = preg_match_all('/[.!?]+/u', $plainText);
        $sentenceCount = $sentenceCount === false ? 0 : $sentenceCount;
        $sentenceCount = max(1, $sentenceCount);

        $tokens = preg_split('/\s+/u', trim($plainText)) ?: [];
        $syllableCount = collect($tokens)
            ->map(fn (string $word): int => $this->estimateSyllables($word))
            ->sum();
        $syllableCount = max($wordCount, $syllableCount);

        $flesch = 206.835
            - (1.015 * ($wordCount / $sentenceCount))
            - (84.6 * ($syllableCount / $wordCount));

        $score = $this->clampScore($flesch);

        return [
            'score' => $score,
            'explanation' => sprintf(
                'Estimated Flesch-style readability using %d words, %d sentences, and %d syllables.',
                $wordCount,
                $sentenceCount,
                $syllableCount
            ),
        ];
    }

    /**
     * @return array{score:float,explanation:string}
     */
    private function seoScoreDetails(Draft $draft, string $html, string $plainText, ?string $primaryKeyword): array
    {
        $deductions = [];
        $score = 100.0;

        $seoTitle = trim((string) ($draft->seo_title ?? $draft->title ?? ''));
        if ($seoTitle === '') {
            $score -= 25;
            $deductions[] = 'missing SEO title';
        } else {
            $titleLength = Str::length($seoTitle);
            if ($titleLength > 65) {
                $score -= 8;
                $deductions[] = 'title too long';
            } elseif ($titleLength < 30) {
                $score -= 4;
                $deductions[] = 'title too short';
            }
        }

        $metaDescription = trim((string) ($draft->seo_meta_description ?? ''));
        if ($metaDescription === '') {
            $score -= 15;
            $deductions[] = 'missing meta description';
        } else {
            $descriptionLength = Str::length($metaDescription);
            if ($descriptionLength < 120 || $descriptionLength > 160) {
                $score -= 5;
                $deductions[] = 'meta description length out of range';
            }
        }

        $seoCanonical = trim((string) ($draft->seo_canonical ?? ''));
        if ($seoCanonical === '') {
            $score -= 10;
            $deductions[] = 'missing canonical URL';
        }

        $h1 = trim((string) ($draft->seo_h1 ?? ''));
        if ($h1 === '' && ! preg_match('/<h1\b[^>]*>.*?<\/h1>/is', $html)) {
            $score -= 15;
            $deductions[] = 'missing H1';
        }

        $hasH2 = (bool) preg_match('/<h2\b[^>]*>/i', $html);
        if (! $hasH2) {
            $score -= 5;
            $deductions[] = 'no H2 structure';
        }

        if ($primaryKeyword === null) {
            $score -= 10;
            $deductions[] = 'missing focus keyword';
        } else {
            $keyword = mb_strtolower($primaryKeyword);
            if (! str_contains(mb_strtolower($seoTitle), $keyword)) {
                $score -= 8;
                $deductions[] = 'keyword missing from title';
            }
            if ($metaDescription !== '' && ! str_contains(mb_strtolower($metaDescription), $keyword)) {
                $score -= 8;
                $deductions[] = 'keyword missing from meta description';
            }
            if (! str_contains($plainText, $keyword)) {
                $score -= 10;
                $deductions[] = 'keyword missing from body';
            }
        }

        $score = $this->clampScore($score);

        return [
            'score' => $score,
            'explanation' => $deductions === []
                ? 'Core SEO fields and focus keyword placement look healthy.'
                : 'SEO deductions: ' . implode(', ', $deductions) . '.',
        ];
    }

    /**
     * @return array{score:?float,explanation:string}
     */
    private function topicalCoverageDetails(Draft $draft, string $plainText): array
    {
        $targets = $this->resolveTopicalTargets($draft);
        if ($targets === []) {
            return [
                'score' => null,
                'explanation' => 'No keyword or topical hints were available to measure coverage.',
            ];
        }

        $matched = collect($targets)
            ->filter(fn (string $target): bool => str_contains($plainText, mb_strtolower($target)))
            ->count();
        $total = count($targets);

        $score = $this->clampScore(($matched / max(1, $total)) * 100);

        return [
            'score' => $score,
            'explanation' => sprintf('Matched %d of %d configured topics/keywords in the draft body.', $matched, $total),
        ];
    }

    /**
     * @return array{score:float,explanation:string}
     */
    private function entityCoverageDetails(string $plainTextOriginal, int $wordCount): array
    {
        if (trim($plainTextOriginal) === '' || $wordCount <= 0) {
            return [
                'score' => 0.0,
                'explanation' => 'No text available to estimate entity coverage.',
            ];
        }

        preg_match_all('/\b([A-Z]{2,}|[A-Z][a-z]{2,})\b/u', $plainTextOriginal, $matches);
        $entities = collect($matches[1] ?? [])
            ->map(fn (string $entity): string => trim($entity))
            ->filter(fn (string $entity): bool => ! in_array(mb_strtolower($entity), ['the', 'and', 'with', 'from', 'this'], true))
            ->unique()
            ->values();

        $entityCount = $entities->count();
        $density = $entityCount / max(1, $wordCount);
        $score = (($entityCount / 18) * 70) + (min(1.0, $density / 0.08) * 30);
        $score = $this->clampScore($score);

        return [
            'score' => $score,
            'explanation' => sprintf('Estimated %d unique entity-like mentions (density %.3f).', $entityCount, $density),
        ];
    }

    /**
     * @return array{score:float,explanation:string}
     */
    private function factualConfidenceDetails(string $plainTextOriginal, string $plainText): array
    {
        if (trim($plainTextOriginal) === '') {
            return [
                'score' => 0.0,
                'explanation' => 'No text available to estimate factual confidence.',
            ];
        }

        $evidencePhrases = [
            'according to',
            'research',
            'study',
            'report',
            'data',
            'benchmark',
            'survey',
            'source',
        ];
        $evidenceHits = collect($evidencePhrases)
            ->filter(fn (string $phrase): bool => str_contains($plainText, $phrase))
            ->count();

        $numericSignals = preg_match_all('/\b\d+(\.\d+)?%?\b/u', $plainTextOriginal);
        $numericSignals = $numericSignals === false ? 0 : $numericSignals;

        $hedgeSignals = preg_match_all('/\b(may|might|could|possibly|perhaps|probably|seems|appears)\b/u', $plainText);
        $hedgeSignals = $hedgeSignals === false ? 0 : $hedgeSignals;

        $absoluteSignals = preg_match_all('/\b(always|never|guaranteed|everyone|no one)\b/u', $plainText);
        $absoluteSignals = $absoluteSignals === false ? 0 : $absoluteSignals;

        $score = 55 + ($evidenceHits * 6) + min(15, (int) $numericSignals) - ((int) $hedgeSignals * 4) - ((int) $absoluteSignals * 6);
        $score = $this->clampScore($score);

        return [
            'score' => $score,
            'explanation' => sprintf(
                'Evidence terms: %d, numeric signals: %d, hedge signals: %d, absolute claims: %d.',
                $evidenceHits,
                (int) $numericSignals,
                (int) $hedgeSignals,
                (int) $absoluteSignals
            ),
        ];
    }

    /**
     * @return array{score:float,explanation:string}
     */
    private function conversionFocusDetails(string $plainText, int $ctaStrength): array
    {
        if (trim($plainText) === '') {
            return [
                'score' => 0.0,
                'explanation' => 'No conversion signals found because the draft body is empty.',
            ];
        }

        $benefitSignals = preg_match_all('/\b(increase|improve|reduce|save|faster|growth|revenue|efficient|results)\b/u', $plainText);
        $benefitSignals = $benefitSignals === false ? 0 : $benefitSignals;

        $urgencySignals = preg_match_all('/\b(now|today|immediately|this week|limited|before)\b/u', $plainText);
        $urgencySignals = $urgencySignals === false ? 0 : $urgencySignals;

        $audienceSignals = preg_match_all('/\b(you|your)\b/u', $plainText);
        $audienceSignals = $audienceSignals === false ? 0 : $audienceSignals;

        $benefitScore = min(100, ((int) $benefitSignals * 12) + ((int) $urgencySignals * 10));
        $audienceScore = min(100, (int) $audienceSignals * 4);

        $score = ($ctaStrength * 0.6) + ($benefitScore * 0.25) + ($audienceScore * 0.15);
        $score = $this->clampScore($score);

        return [
            'score' => $score,
            'explanation' => sprintf(
                'CTA=%d with %d benefit, %d urgency, and %d audience-focused signals.',
                $ctaStrength,
                (int) $benefitSignals,
                (int) $urgencySignals,
                (int) $audienceSignals
            ),
        ];
    }

    /**
     * @return array{score:float,explanation:string,source_type:string}
     */
    private function aiSeoScoreDetails(
        Draft $draft,
        float $seoScore,
        ?float $readabilityScore,
        float $structureQuality,
    ): array {
        $persistedScore = $this->resolveAiSeoScore($draft);
        if ($persistedScore !== null) {
            return [
                'score' => $this->clampScore($persistedScore),
                'explanation' => 'Reused latest stored AI SEO score from analytics for this content URL.',
                'source_type' => 'existing_signal',
            ];
        }

        $heuristicScore = $this->weightedAverage([
            ['value' => $seoScore, 'weight' => 0.6],
            ['value' => $readabilityScore, 'weight' => 0.25],
            ['value' => $structureQuality, 'weight' => 0.15],
        ]);

        return [
            'score' => $this->clampScore($heuristicScore ?? 0),
            'explanation' => 'No analytics AI SEO snapshot found; used a transparent heuristic from SEO, readability, and structure.',
            'source_type' => 'heuristic',
        ];
    }

    private function resolvePrimaryKeyword(Draft $draft): ?string
    {
        $keyword = trim((string) data_get($draft->meta, 'primary_keyword', ''));
        if ($keyword !== '') {
            return $keyword;
        }

        $contentKeyword = trim((string) ($draft->content?->primary_keyword ?? ''));
        if ($contentKeyword !== '') {
            return $contentKeyword;
        }

        $briefKeyword = trim((string) ($draft->brief?->primary_keyword ?? ''));

        return $briefKeyword !== '' ? $briefKeyword : null;
    }

    /**
     * @return array<int,string>
     */
    private function resolveTopicalTargets(Draft $draft): array
    {
        $brief = $draft->relationLoaded('brief') ? $draft->brief : $draft->brief()->first();
        $targets = [];

        $primaryKeyword = $this->resolvePrimaryKeyword($draft);
        if ($primaryKeyword !== null) {
            $targets[] = $primaryKeyword;
        }

        $secondary = data_get($draft->meta, 'secondary_keywords');
        if ($secondary === null && $brief) {
            $secondary = $brief->secondary_keywords;
        }

        if (is_string($secondary)) {
            $secondary = preg_split('/[,;|\n]+/', $secondary) ?: [];
        }
        if (is_array($secondary)) {
            foreach ($secondary as $keyword) {
                $targets[] = (string) $keyword;
            }
        }

        $keyPoints = data_get($draft->meta, 'key_points');
        if ($keyPoints === null && $brief) {
            $keyPoints = $brief->key_points;
        }
        if (is_string($keyPoints)) {
            $keyPoints = preg_split('/[,;|\n]+/', $keyPoints) ?: [];
        }
        if (is_array($keyPoints)) {
            foreach ($keyPoints as $point) {
                $targets[] = (string) $point;
            }
        }

        return collect($targets)
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function weightedAverage(array $weightedValues): ?float
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($weightedValues as $item) {
            $value = $item['value'] ?? null;
            $weight = $item['weight'] ?? null;
            if (! is_numeric($value) || ! is_numeric($weight)) {
                continue;
            }

            $weightValue = (float) $weight;
            if ($weightValue <= 0) {
                continue;
            }

            $weightedSum += ((float) $value * $weightValue);
            $weightTotal += $weightValue;
        }

        if ($weightTotal <= 0.0) {
            return null;
        }

        return $weightedSum / $weightTotal;
    }

    private function clampScore(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    private function estimateSyllables(string $word): int
    {
        $word = mb_strtolower($word);
        $word = preg_replace('/[^a-z]/', '', $word) ?? '';
        if ($word === '') {
            return 0;
        }

        $groups = preg_match_all('/[aeiouy]+/', $word);
        $groups = $groups === false ? 0 : $groups;

        if (str_ends_with($word, 'e') && $groups > 1) {
            $groups--;
        }

        return max(1, $groups);
    }

    private function resolveBrandVoice(Draft $draft): ?BrandVoice
    {
        $content = $draft->relationLoaded('content') ? $draft->content : null;

        if (! $content && $draft->content_id) {
            $content = Content::query()
                ->with(['brandVoice', 'workspace.brandVoices'])
                ->find($draft->content_id);
        }

        if ($content?->brandVoice instanceof BrandVoice) {
            return $content->brandVoice;
        }

        $metaBrandVoiceId = trim((string) data_get($draft->meta, 'brand_voice_id', ''));
        if ($metaBrandVoiceId !== '' && $content?->workspace_id) {
            $voice = BrandVoice::query()
                ->where('workspace_id', $content->workspace_id)
                ->whereKey($metaBrandVoiceId)
                ->first();
            if ($voice) {
                return $voice;
            }
        }

        $default = $content?->workspace?->brandVoices?->firstWhere('is_default', true);

        return $default instanceof BrandVoice ? $default : null;
    }

    public function resolveAiSeoScore(Draft $draft): ?float
    {
        $content = $draft->relationLoaded('content') ? $draft->content : null;
        if (! $content && $draft->content_id) {
            $content = Content::query()->find($draft->content_id);
        }

        if (! $content) {
            return null;
        }

        $urlKeyCandidates = array_filter([
            trim((string) ($content->publish_url_key ?? '')),
            trim((string) ($content->canonical_url_key ?? '')),
        ]);

        if ($urlKeyCandidates === []) {
            return null;
        }

        $score = ContentAiSeoScore::query()
            ->whereIn('url_key', $urlKeyCandidates)
            ->orderByDesc('calculated_at')
            ->value('ai_seo_score');

        return $score !== null ? (float) $score : null;
    }
}
