<?php

namespace App\Services\Drafts;

use Illuminate\Support\Arr;

class DraftAnalysisResponseNormalizer
{
    /**
     * Maps alternative key names to canonical paths.
     *
     * @var array<string, string>
     */
    private const KEY_MAPPINGS = [
        // Top-level score alternatives
        'seo_score' => 'sections.seo.score',
        'seoScore' => 'sections.seo.score',
        'readability_score' => 'sections.readability.score',
        'readabilityScore' => 'sections.readability.score',
        'cta_score' => 'sections.cta.score',
        'ctaScore' => 'sections.cta.score',
        'structure_score' => 'sections.structure.score',
        'structureScore' => 'sections.structure.score',
        'llm_visibility_score' => 'sections.llm_visibility.score',
        'llmVisibilityScore' => 'sections.llm_visibility.score',
        'brand_voice_fit_score' => 'sections.brand_voice_fit.score',
        'brandVoiceFitScore' => 'sections.brand_voice_fit.score',
        'conversion_fit_score' => 'sections.conversion_fit.score',
        'conversionFitScore' => 'sections.conversion_fit.score',
        'trust_evidence_score' => 'sections.trust_evidence.score',
        'trustEvidenceScore' => 'sections.trust_evidence.score',
        'publish_readiness_score' => 'sections.publish_readiness.score',
        'publishReadinessScore' => 'sections.publish_readiness.score',
        'publish_readiness_status' => 'sections.publish_readiness.status_label',
        'publishReadinessStatus' => 'sections.publish_readiness.status_label',
        'entities_score' => 'sections.entities.score',
        'entitiesScore' => 'sections.entities.score',
        'entity_score' => 'sections.entities.score',

        // Top-level explanation alternatives
        'seo_explanation' => 'sections.seo.explanation',
        'seoExplanation' => 'sections.seo.explanation',
        'readability_explanation' => 'sections.readability.explanation',
        'readabilityExplanation' => 'sections.readability.explanation',
        'cta_explanation' => 'sections.cta.explanation',
        'ctaExplanation' => 'sections.cta.explanation',
        'structure_explanation' => 'sections.structure.explanation',
        'structureExplanation' => 'sections.structure.explanation',
        'llm_visibility_explanation' => 'sections.llm_visibility.explanation',
        'llmVisibilityExplanation' => 'sections.llm_visibility.explanation',
        'brand_voice_fit_explanation' => 'sections.brand_voice_fit.explanation',
        'brandVoiceFitExplanation' => 'sections.brand_voice_fit.explanation',
        'conversion_fit_explanation' => 'sections.conversion_fit.explanation',
        'conversionFitExplanation' => 'sections.conversion_fit.explanation',
        'trust_evidence_explanation' => 'sections.trust_evidence.explanation',
        'trustEvidenceExplanation' => 'sections.trust_evidence.explanation',
        'publish_readiness_explanation' => 'sections.publish_readiness.explanation',
        'publishReadinessExplanation' => 'sections.publish_readiness.explanation',
        'entities_explanation' => 'sections.entities.explanation',
        'entitiesExplanation' => 'sections.entities.explanation',

        // Top-level improvement alternatives
        'seo_improvements' => 'sections.seo.improvements',
        'seoImprovements' => 'sections.seo.improvements',
        'readability_improvements' => 'sections.readability.improvements',
        'readabilityImprovements' => 'sections.readability.improvements',
        'cta_improvements' => 'sections.cta.improvements',
        'ctaImprovements' => 'sections.cta.improvements',
        'structure_improvements' => 'sections.structure.improvements',
        'structureImprovements' => 'sections.structure.improvements',
        'llm_visibility_improvements' => 'sections.llm_visibility.improvements',
        'llmVisibilityImprovements' => 'sections.llm_visibility.improvements',
        'brand_voice_fit_improvements' => 'sections.brand_voice_fit.improvements',
        'brandVoiceFitImprovements' => 'sections.brand_voice_fit.improvements',
        'conversion_fit_improvements' => 'sections.conversion_fit.improvements',
        'conversionFitImprovements' => 'sections.conversion_fit.improvements',
        'trust_evidence_improvements' => 'sections.trust_evidence.improvements',
        'trustEvidenceImprovements' => 'sections.trust_evidence.improvements',
        'publish_readiness_improvements' => 'sections.publish_readiness.improvements',
        'publishReadinessImprovements' => 'sections.publish_readiness.improvements',
        'publish_readiness_blocking_issues' => 'sections.publish_readiness.blocking_issues',
        'publishReadinessBlockingIssues' => 'sections.publish_readiness.blocking_issues',
        'publish_readiness_next_actions' => 'sections.publish_readiness.recommended_next_actions',
        'publishReadinessNextActions' => 'sections.publish_readiness.recommended_next_actions',
        'entities_improvements' => 'sections.entities.improvements',
        'entitiesImprovements' => 'sections.entities.improvements',

        // Internal link alternatives
        'links' => 'internal_link_opportunities',
        'link_opportunities' => 'internal_link_opportunities',
        'linkOpportunities' => 'internal_link_opportunities',
        'suggested_links' => 'internal_link_opportunities',
        'suggestedLinks' => 'internal_link_opportunities',
        'internalLinkSummary' => 'internal_link_summary',
        'link_summary' => 'internal_link_summary',

        // Entity coverage alternatives
        'entity_coverage' => 'entity_coverage',
        'entityCoverage' => 'entity_coverage',
        'entities_detected' => 'entity_coverage.detected_entities',
        'detectedEntities' => 'entity_coverage.detected_entities',
        'detected_entities' => 'entity_coverage.detected_entities',
        'missing_entities' => 'entity_coverage.missing_entities',
        'missingEntities' => 'entity_coverage.missing_entities',

        // Keyword coverage alternatives
        'keyword_coverage' => 'keyword_coverage',
        'keywordCoverage' => 'keyword_coverage',
        'keywords' => 'keyword_coverage',
        'covered_terms' => 'keyword_coverage.covered_terms',
        'coveredTerms' => 'keyword_coverage.covered_terms',
        'missing_terms' => 'keyword_coverage.missing_terms',
        'missingTerms' => 'keyword_coverage.missing_terms',

        // Top improvements alternatives
        'topImprovements' => 'top_improvements',
        'recommendations' => 'top_improvements',
        'top_recommendations' => 'top_improvements',
        'priority_improvements' => 'top_improvements',
    ];

    /**
     * Normalize raw LLM response to canonical structure.
     *
     * @return array{normalized: array<string,mixed>, errors: array<int,string>}
     */
    public function normalize(array $payload): array
    {
        $errors = [];

        // Start with canonical empty structure
        $normalized = $this->emptyCanonicalStructure();

        // First, check if sections are at top level instead of nested
        $payload = $this->hoistTopLevelSections($payload);

        // Apply key mappings for any alternate keys found
        $payload = $this->applyKeyMappings($payload);

        // Now extract and normalize each part
        $normalized['summary'] = $this->normalizeSummary(data_get($payload, 'summary', []));
        $normalized['sections'] = $this->normalizeSections(data_get($payload, 'sections', []));
        $normalized['keyword_coverage'] = $this->normalizeCoverage(data_get($payload, 'keyword_coverage', []), 'terms');
        $normalized['entity_coverage'] = $this->normalizeCoverage(data_get($payload, 'entity_coverage', []), 'entities');
        $normalized['internal_link_summary'] = $this->nullableString(data_get($payload, 'internal_link_summary'));
        $normalized['internal_link_opportunities'] = $this->normalizeLinkOpportunities(data_get($payload, 'internal_link_opportunities', []));
        $normalized['top_improvements'] = $this->normalizeStringList(data_get($payload, 'top_improvements', []));

        // Validate minimum structure
        $sectionsFound = count(array_filter($normalized['sections'], fn ($s) => ! empty($s['explanation']) || is_numeric($s['score'])));
        if ($sectionsFound === 0) {
            $errors[] = 'No valid section data could be extracted from response.';
        }

        return [
            'normalized' => $normalized,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function emptyCanonicalStructure(): array
    {
        $emptySection = [
            'score' => null,
            'explanation' => null,
            'improvements' => [],
        ];
        $publishReadinessSection = [
            'score' => null,
            'explanation' => null,
            'improvements' => [],
            'status_label' => null,
            'blocking_issues' => [],
            'recommended_next_actions' => [],
        ];

        return [
            'summary' => [
                'headline' => null,
                'overall_explanation' => null,
            ],
            'sections' => [
                'seo' => $emptySection,
                'readability' => $emptySection,
                'cta' => $emptySection,
                'structure' => $emptySection,
                'llm_visibility' => $emptySection,
                'brand_voice_fit' => $emptySection,
                'conversion_fit' => $emptySection,
                'trust_evidence' => $emptySection,
                'publish_readiness' => $publishReadinessSection,
                'entities' => $emptySection,
            ],
            'keyword_coverage' => [
                'score' => null,
                'covered_terms' => [],
                'missing_terms' => [],
                'explanation' => null,
            ],
            'entity_coverage' => [
                'score' => null,
                'detected_entities' => [],
                'missing_entities' => [],
                'explanation' => null,
            ],
            'internal_link_summary' => null,
            'internal_link_opportunities' => [],
            'top_improvements' => [],
            'context' => [],
        ];
    }

    private function hoistTopLevelSections(array $payload): array
    {
        // If sections key exists, payload is already structured correctly
        if (isset($payload['sections']) && is_array($payload['sections'])) {
            return $this->massageLegacyPayload($payload);
        }

        // Check if section keys are at top level
        $sectionKeys = ['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'];
        $foundSections = [];

        foreach ($sectionKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $foundSections[$key] = $payload[$key];
                unset($payload[$key]);
            }
        }

        if (! empty($foundSections)) {
            $payload['sections'] = $foundSections;
        }

        return $this->massageLegacyPayload($payload);
    }

    private function applyKeyMappings(array $payload): array
    {
        foreach (self::KEY_MAPPINGS as $altKey => $canonicalPath) {
            if (Arr::has($payload, $altKey) && ! Arr::has($payload, $canonicalPath)) {
                $value = Arr::get($payload, $altKey);
                Arr::set($payload, $canonicalPath, $value);
                Arr::forget($payload, $altKey);
            }
        }

        return $payload;
    }

    /**
     * @return array{headline: ?string, overall_explanation: ?string}
     */
    private function normalizeSummary(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'headline' => null,
                'overall_explanation' => $this->nullableString(data_get($value, 'overall.comments.0')),
            ];
        }

        return [
            'headline' => $this->nullableString(
                data_get($value, 'headline')
                    ?? data_get($value, 'title')
                    ?? data_get($value, 'heading')
            ),
            'overall_explanation' => $this->nullableString(
                data_get($value, 'overall_explanation')
                    ?? data_get($value, 'explanation')
                    ?? data_get($value, 'description')
                    ?? data_get($value, 'overallExplanation')
                    ?? implode(' ', $this->normalizeStringList(data_get($value, 'comments', [])))
            ),
        ];
    }

    /**
     * @return array<string, array{score: ?int, explanation: ?string, improvements: array<int,string>}>
     */
    private function normalizeSections(mixed $value): array
    {
        $sections = [];
        $rawSections = is_array($value) ? $value : [];

        foreach (['seo', 'readability', 'cta', 'structure', 'llm_visibility', 'brand_voice_fit', 'conversion_fit', 'trust_evidence', 'publish_readiness', 'entities'] as $key) {
            $raw = data_get($rawSections, $key, []);
            $sections[$key] = $this->normalizeSection($raw);
        }

        return $sections;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeSection(mixed $value): array
    {
        if (! is_array($value)) {
            return ['score' => null, 'explanation' => null, 'improvements' => []];
        }

        $comments = $this->normalizeStringList(data_get($value, 'comments', []));
        $feedback = $this->nullableString(data_get($value, 'feedback'));
        $explanation = $this->nullableString(data_get($value, 'explanation'))
            ?? $feedback
            ?? ($comments[0] ?? null);
        $improvements = $this->normalizeStringList(data_get($value, 'improvements', []));

        if ($improvements === [] && $feedback !== null) {
            $improvements = [$feedback];
        }

        if ($improvements === [] && count($comments) > 1) {
            $improvements = array_slice($comments, 1);
        }

        return [
            'score' => $this->normalizeScore(data_get($value, 'score')),
            'explanation' => $explanation,
            'improvements' => $improvements,
            'status_label' => $this->nullableString(data_get($value, 'status_label')),
            'blocking_issues' => $this->normalizeStringList(data_get($value, 'blocking_issues', [])),
            'recommended_next_actions' => $this->normalizeStringList(data_get($value, 'recommended_next_actions', [])),
        ];
    }

    /**
     * @return array{score: ?int, covered_terms?: array<int,string>, missing_terms?: array<int,string>, detected_entities?: array<int,string>, missing_entities?: array<int,string>, explanation: ?string}
     */
    private function normalizeCoverage(mixed $value, string $type): array
    {
        if (! is_array($value)) {
            if ($type === 'terms') {
                return ['score' => null, 'covered_terms' => [], 'missing_terms' => [], 'explanation' => null];
            }

            return ['score' => null, 'detected_entities' => [], 'missing_entities' => [], 'explanation' => null];
        }

        $result = [
            'score' => $this->normalizeScore(data_get($value, 'score')),
            'explanation' => $this->nullableString(data_get($value, 'explanation')),
        ];

        if ($type === 'terms') {
            $result['covered_terms'] = $this->normalizeStringList(data_get($value, 'covered_terms', []));
            $result['missing_terms'] = $this->normalizeStringList(data_get($value, 'missing_terms', []));
        } else {
            $result['detected_entities'] = $this->normalizeStringList(data_get($value, 'detected_entities', []));
            $result['missing_entities'] = $this->normalizeStringList(data_get($value, 'missing_entities', []));
        }

        return $result;
    }

    /**
     * @return array<int, array{target_title: ?string, reason: ?string, anchor_text: ?string, placement: ?string, target_url?: ?string}>
     */
    private function normalizeLinkOpportunities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $targetTitle = $this->nullableString(
                    data_get($item, 'target_title')
                        ?? data_get($item, 'targetTitle')
                        ?? data_get($item, 'title')
                );

                if ($targetTitle === null) {
                    return null;
                }

                return [
                    'target_title' => $targetTitle,
                    'reason' => $this->nullableString(
                        data_get($item, 'reason')
                            ?? data_get($item, 'explanation')
                    ),
                    'anchor_text' => $this->nullableString(
                        data_get($item, 'anchor_text')
                            ?? data_get($item, 'anchorText')
                            ?? data_get($item, 'anchor')
                    ),
                    'placement' => $this->nullableString(
                        data_get($item, 'placement')
                            ?? data_get($item, 'location')
                            ?? data_get($item, 'position')
                    ),
                    'target_url' => $this->nullableString(
                        data_get($item, 'target_url')
                            ?? data_get($item, 'targetUrl')
                            ?? data_get($item, 'url')
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function massageLegacyPayload(array $payload): array
    {
        $sections = $payload['sections'] ?? null;

        if (is_array($sections) && array_is_list($sections)) {
            $payload['sections'] = $this->normalizeLegacySectionList($sections);
        }

        if (is_array($payload['sections'] ?? null)) {
            $payload['sections'] = $this->mergeLegacySectionKeys((array) $payload['sections']);
        }

        if (! isset($payload['summary']) && is_array($payload['overall'] ?? null)) {
            $payload['summary'] = [
                'headline' => $this->nullableString(data_get($payload, 'overall.headline'))
                    ?? $this->nullableString(data_get($payload, 'overall.title'))
                    ?? 'Draft intelligence summary',
                'overall_explanation' => $this->nullableString(data_get($payload, 'overall.explanation'))
                    ?? implode(' ', $this->normalizeStringList(data_get($payload, 'overall.comments', []))),
            ];
        }

        return $payload;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<string,mixed>
     */
    private function normalizeLegacySectionList(array $sections): array
    {
        $mapped = [];

        foreach ($sections as $item) {
            if (! is_array($item)) {
                continue;
            }

            $bucket = $this->legacySectionBucket((string) ($item['name'] ?? ''));
            if ($bucket === null || isset($mapped[$bucket])) {
                continue;
            }

            $mapped[$bucket] = [
                'score' => data_get($item, 'score'),
                'explanation' => $this->nullableString(data_get($item, 'feedback')) ?? ($this->normalizeStringList(data_get($item, 'comments', []))[0] ?? null),
                'improvements' => $this->normalizeStringList(data_get($item, 'comments', [])),
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    private function mergeLegacySectionKeys(array $sections): array
    {
        $mapped = [];

        foreach ($sections as $key => $value) {
            $bucket = $this->legacySectionBucket((string) $key);
            if ($bucket === null) {
                continue;
            }

            $section = is_array($value) ? $value : ['feedback' => $value];
            $existing = is_array($mapped[$bucket] ?? null) ? $mapped[$bucket] : [];
            $comments = $this->normalizeStringList(data_get($section, 'comments', []));
            $feedback = $this->nullableString(data_get($section, 'feedback'));

            if (! array_key_exists('score', $existing) && is_numeric(data_get($section, 'score'))) {
                $existing['score'] = data_get($section, 'score');
            }

            if (! isset($existing['explanation'])) {
                $existing['explanation'] = $this->nullableString(data_get($section, 'explanation'))
                    ?? $feedback
                    ?? ($comments[0] ?? null);
            }

            $existing['improvements'] = array_values(array_unique(array_merge(
                $this->normalizeStringList((array) ($existing['improvements'] ?? [])),
                $this->normalizeStringList(data_get($section, 'improvements', [])),
                $feedback !== null ? [$feedback] : [],
                $comments
            )));

            $mapped[$bucket] = $existing;
        }

        return array_replace($sections, $mapped);
    }

    private function legacySectionBucket(string $key): ?string
    {
        $normalized = strtolower(trim($key));

        return match (true) {
            in_array($normalized, ['seo', 'keyword_usage', 'keywords', 'seo_title', 'meta_description', 'title', 'title_metadata', 'title & metadata', 'keyword usage & seo optimization'], true) => 'seo',
            in_array($normalized, ['readability', 'body', 'body_content', 'intro', 'readability & structure'], true) => 'readability',
            in_array($normalized, ['cta', 'call_to_action', 'conclusion'], true) => 'cta',
            in_array($normalized, ['structure', 'headings', 'heading_structure', 'heading structure'], true) => 'structure',
            in_array($normalized, ['llm_visibility', 'llm visibility', 'ai discoverability', 'ai visibility', 'llm discoverability'], true) => 'llm_visibility',
            in_array($normalized, ['brand_voice_fit', 'brand voice fit', 'brand voice', 'voice fit'], true) => 'brand_voice_fit',
            in_array($normalized, ['conversion_fit', 'conversion fit', 'conversion'], true) => 'conversion_fit',
            in_array($normalized, ['trust_evidence', 'trust evidence', 'trust', 'evidence'], true) => 'trust_evidence',
            in_array($normalized, ['publish_readiness', 'publish readiness', 'readiness'], true) => 'publish_readiness',
            in_array($normalized, ['entities', 'entity coverage'], true) => 'entities',
            default => null,
        };
    }
}
