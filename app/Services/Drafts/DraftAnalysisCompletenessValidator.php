<?php

namespace App\Services\Drafts;

use App\Models\DraftAnalysis;

class DraftAnalysisCompletenessValidator
{
    /**
     * Minimum number of main sections that must exist.
     */
    public const MIN_SECTIONS = 4;

    /**
     * Minimum number of sections that must have numeric scores.
     */
    public const MIN_SCORED_SECTIONS = 3;

    /**
     * Minimum number of sections that must have non-empty explanations.
     */
    public const MIN_EXPLAINED_SECTIONS = 3;

    /**
     * Minimum total improvements across all sections.
     */
    public const MIN_TOTAL_IMPROVEMENTS = 5;

    /**
     * @return array{status: string, errors: array<int, string>, metrics: array<string, int>}
     */
    public function validate(
        array $suggestions,
        ?int $seoScore,
        ?int $readabilityScore,
        ?int $ctaScore,
        ?int $headingsScore = null,
        ?int $llmVisibilityScore = null,
        ?int $brandVoiceFitScore = null,
        ?int $conversionFitScore = null,
        ?int $trustEvidenceScore = null,
        ?int $publishReadinessScore = null,
        ?int $entityCoverage = null,
    ): array
    {
        $errors = [];
        $metrics = [
            'sections_present' => 0,
            'sections_scored' => 0,
            'sections_explained' => 0,
            'total_improvements' => 0,
        ];

        $sections = (array) data_get($suggestions, 'sections', []);
        $sectionKeys = ['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'];

        // Count sections with data
        foreach ($sectionKeys as $key) {
            $section = data_get($sections, $key, []);
            $score = data_get($section, 'score');
            $explanation = trim((string) data_get($section, 'explanation', ''));
            $improvements = (array) data_get($section, 'improvements', []);
            $nonEmptyImprovements = count(array_filter($improvements, fn ($v) => trim((string) $v) !== ''));

            if (is_array($section) && (is_numeric($score) || $explanation !== '' || $nonEmptyImprovements > 0)) {
                $metrics['sections_present']++;

                if (is_numeric($score)) {
                    $metrics['sections_scored']++;
                }

                if ($explanation !== '') {
                    $metrics['sections_explained']++;
                }

                $metrics['total_improvements'] += $nonEmptyImprovements;
            }
        }

        // Also count top-level score fields that come from the database
        $topLevelScores = array_filter([
            $seoScore,
            $readabilityScore,
            $ctaScore,
            $headingsScore,
            $llmVisibilityScore,
            $brandVoiceFitScore,
            $conversionFitScore,
            $trustEvidenceScore,
            $publishReadinessScore,
            $entityCoverage,
        ], fn ($v) => is_int($v));
        $metrics['top_level_scores'] = count($topLevelScores);

        // Use the higher of section scores vs top-level scores
        $effectiveScoredSections = max($metrics['sections_scored'], $metrics['top_level_scores']);

        // Validate thresholds
        if ($metrics['sections_present'] < self::MIN_SECTIONS) {
            $errors[] = sprintf(
                'Only %d of %d required sections present.',
                $metrics['sections_present'],
                self::MIN_SECTIONS
            );
        }

        if ($effectiveScoredSections < self::MIN_SCORED_SECTIONS) {
            $errors[] = sprintf(
                'Only %d of %d required section scores present.',
                $effectiveScoredSections,
                self::MIN_SCORED_SECTIONS
            );
        }

        if ($metrics['sections_explained'] < self::MIN_EXPLAINED_SECTIONS) {
            $errors[] = sprintf(
                'Only %d of %d required section explanations present.',
                $metrics['sections_explained'],
                self::MIN_EXPLAINED_SECTIONS
            );
        }

        if ($metrics['total_improvements'] < self::MIN_TOTAL_IMPROVEMENTS) {
            $errors[] = sprintf(
                'Only %d of %d required improvements present.',
                $metrics['total_improvements'],
                self::MIN_TOTAL_IMPROVEMENTS
            );
        }

        // Determine status
        if (empty($errors)) {
            $status = DraftAnalysis::STATUS_COMPLETED;
        } elseif ($metrics['sections_present'] > 0 || $effectiveScoredSections > 0) {
            $status = DraftAnalysis::STATUS_PARTIAL;
        } else {
            $status = DraftAnalysis::STATUS_FAILED;
        }

        return [
            'status' => $status,
            'errors' => $errors,
            'metrics' => $metrics,
        ];
    }

    /**
     * Check if sections array has enough structure to be considered usable.
     */
    public function hasParsableStructure(array $payload): bool
    {
        // Must have either 'sections' key or individual section keys
        if (isset($payload['sections']) && is_array($payload['sections'])) {
            return true;
        }

        // Check for alternative structures
        $sectionKeys = ['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'];
        $found = 0;
        foreach ($sectionKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $found++;
            }
        }

        return $found >= 2;
    }
}
