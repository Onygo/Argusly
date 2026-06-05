<?php

namespace App\Services\Content;

use App\Enums\ContentIntelligenceStatus;
use App\Models\Content;
use App\Services\Seo\ContentIndexationHealthService;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

class ContentHealthService
{
    public function __construct(
        private readonly \App\Services\AIVisibility\AIVisibilityService $aiVisibility,
        private readonly ContentDecayService $decay,
        private readonly ContentIndexationHealthService $indexationHealth,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(?Content $content, ?string $html = null, ?string $siteUrl = null): array
    {
        if ($content) {
            $content->loadMissing('clientSite:id,name,base_url,site_url', 'currentRevision', 'currentVersion', 'drafts');
        }

        $resolvedHtml = trim((string) ($html ?? $this->resolveBodyHtml($content)));
        $plainText = $this->plainText($resolvedHtml);
        $headings = $this->extractHeadings($resolvedHtml);
        $resolvedSiteUrl = trim((string) ($siteUrl ?: $content?->clientSite?->site_url ?: $content?->clientSite?->base_url ?: ''));

        return [
            'html' => $resolvedHtml,
            'plain_text' => $plainText,
            'word_count' => str_word_count($plainText),
            'headings' => $headings,
            'heading_count' => count($headings),
            'link_urls' => $this->linkUrls($resolvedHtml),
            'internal_link_count' => $this->internalLinkCount($resolvedHtml, $resolvedSiteUrl),
            'body_years' => $this->extractYears($plainText),
            'has_faq' => $this->hasFaq($content, $headings),
            'latest_reference_at' => $content ? $this->latestReferenceAt($content) : null,
            'missing_seo_fields' => $content ? $this->missingSeoFields($content) : [],
            'title_h1_mismatch' => $content ? $this->hasTitleH1Mismatch($content) : false,
            'target_word_count' => $content ? $this->targetWordCount($content) : 0,
        ];
    }

    /**
     * @return array{
     *   content_health_score:int,
     *   ai_visibility_score:int|null,
     *   semantic_coverage_score:int,
     *   freshness_score:int,
     *   internal_link_score:int,
     *   answer_block_score:int,
     *   translation_parity_score:int,
     *   competitor_freshness_risk:int,
     *   optimization_opportunity_score:int,
     *   decay_risk_level:string,
     *   intelligence_status:string,
     *   signal_badges:array<int,array{label:string,tone:string,tooltip:string}>,
     *   recommendations:array<int,array<string,mixed>>,
     *   ai_visibility:array<string,mixed>
     * }
     */
    public function metrics(Content $content, ?string $html = null): array
    {
        $content->loadMissing([
            'workspace',
            'clientSite.analyticsSite',
            'currentRevision',
            'currentVersion',
            'drafts',
            'localizedVariants',
            'translationSourceContent',
            'recommendations',
            'aiVisibilitySnapshots',
            'indexationHealth',
        ]);

        $snapshot = $this->snapshot($content, $html);
        $aiVisibility = $this->aiVisibility->forContent($content);
        $indexation = $this->indexationHealth->evaluate($content);

        $semanticCoverage = $this->resolveStoredOrComputed(
            $content->semantic_coverage_score,
            $this->semanticCoverageScore($snapshot)
        );
        $freshnessScore = $this->resolveStoredOrComputed(
            $content->freshness_score,
            $this->freshnessScore($content, $snapshot)
        );
        $internalLinkScore = $this->resolveStoredOrComputed(
            $content->internal_link_score,
            $this->internalLinkScore($snapshot)
        );
        $answerBlockScore = $this->resolveStoredOrComputed(
            $content->answer_block_score,
            $this->answerBlockScore($content, $snapshot)
        );
        $translationParityScore = $this->resolveStoredOrComputed(
            $content->translation_parity_score,
            $this->translationParityScore($content)
        );
        $competitorFreshnessRisk = $this->resolveStoredOrComputed(
            $content->competitor_freshness_risk,
            $this->competitorFreshnessRisk($freshnessScore, (int) ($aiVisibility['score'] ?? 0))
        );
        $aiVisibilityScore = $this->resolveStoredOrComputed(
            $content->ai_visibility_score,
            is_numeric($aiVisibility['score'] ?? null) ? (int) $aiVisibility['score'] : null
        );

        $healthScore = $this->resolveStoredOrComputed(
            $content->content_health_score,
            $this->contentHealthScore(
                freshnessScore: $freshnessScore,
                aiVisibilityScore: $aiVisibilityScore,
                semanticCoverageScore: $semanticCoverage,
                internalLinkScore: $internalLinkScore,
                answerBlockScore: $answerBlockScore,
                translationParityScore: $translationParityScore,
                content: $content,
                indexationHealth: $indexation,
            )
        );

        $optimizationOpportunityScore = $this->resolveStoredOrComputed(
            $content->optimization_opportunity_score,
            $this->optimizationOpportunityScore(
                $healthScore,
                $semanticCoverage,
                $internalLinkScore,
                $answerBlockScore,
                $aiVisibilityScore
            )
        );

        $decay = $this->decay->detect($content, [
            'content_health_score' => $healthScore,
            'freshness_score' => $freshnessScore,
            'ai_visibility_score' => $aiVisibilityScore,
            'answer_block_score' => $answerBlockScore,
            'semantic_coverage_score' => $semanticCoverage,
            'competitor_freshness_risk' => $competitorFreshnessRisk,
        ]);

        $intelligenceStatus = $content->intelligence_status?->value
            ?? $this->intelligenceStatus(
                healthScore: $healthScore,
                optimizationOpportunityScore: $optimizationOpportunityScore,
                decayRiskLevel: $decay['level']->value,
                aiVisibilityScore: $aiVisibilityScore,
                content: $content,
            )->value;

        $recommendations = $this->recommendations($content, [
            'content_health_score' => $healthScore,
            'ai_visibility_score' => $aiVisibilityScore,
            'semantic_coverage_score' => $semanticCoverage,
            'freshness_score' => $freshnessScore,
            'internal_link_score' => $internalLinkScore,
            'answer_block_score' => $answerBlockScore,
            'translation_parity_score' => $translationParityScore,
            'competitor_freshness_risk' => $competitorFreshnessRisk,
            'optimization_opportunity_score' => $optimizationOpportunityScore,
            'decay_risk_level' => $decay['level']->value,
            'snapshot' => $snapshot,
        ]);

        return [
            'content_health_score' => $healthScore,
            'ai_visibility_score' => $aiVisibilityScore,
            'semantic_coverage_score' => $semanticCoverage,
            'freshness_score' => $freshnessScore,
            'internal_link_score' => $internalLinkScore,
            'answer_block_score' => $answerBlockScore,
            'translation_parity_score' => $translationParityScore,
            'competitor_freshness_risk' => $competitorFreshnessRisk,
            'optimization_opportunity_score' => $optimizationOpportunityScore,
            'decay_risk_level' => $content->decay_risk_level?->value ?? $decay['level']->value,
            'intelligence_status' => $intelligenceStatus,
            'signal_badges' => $this->signalBadges($content, [
                'indexation_health' => $indexation,
                'content_health_score' => $healthScore,
                'ai_visibility_score' => $aiVisibilityScore,
                'semantic_coverage_score' => $semanticCoverage,
                'freshness_score' => $freshnessScore,
                'internal_link_score' => $internalLinkScore,
                'answer_block_score' => $answerBlockScore,
                'translation_parity_score' => $translationParityScore,
                'decay_risk_level' => $decay['level']->value,
                'optimization_opportunity_score' => $optimizationOpportunityScore,
                'snapshot' => $snapshot,
            ]),
            'recommendations' => $recommendations,
            'ai_visibility' => $aiVisibility,
            'indexation_health' => $indexation,
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<int,array{label:string,tone:string,tooltip:string}>
     */
    public function signalBadges(Content $content, array $metrics): array
    {
        $badges = collect([
            ($metrics['indexation_health']['indexed'] ?? null) === true ? ['label' => 'Indexed', 'tone' => 'green', 'tooltip' => 'Google Search Console marks this page as indexed.'] : null,
            ($metrics['indexation_health']['canonical_accepted'] ?? null) === true ? ['label' => 'Canonical accepted', 'tone' => 'green', 'tooltip' => 'Google accepted the platform canonical for this page.'] : null,
            ($metrics['ai_visibility_score'] ?? 0) >= 70 ? ['label' => 'AI Visible', 'tone' => 'green', 'tooltip' => 'This content is performing well across AI visibility signals.'] : null,
            ($metrics['freshness_score'] ?? 0) >= 70 ? ['label' => 'Fresh', 'tone' => 'green', 'tooltip' => 'The content has been updated recently enough for its topic.'] : null,
            ($metrics['content_health_score'] ?? 0) >= 80 ? ['label' => 'Optimized', 'tone' => 'green', 'tooltip' => 'The combined content health score is in the top range.'] : null,
            ($metrics['semantic_coverage_score'] ?? 0) >= 75 ? ['label' => 'Complete', 'tone' => 'green', 'tooltip' => 'The structure and topic coverage look strong.'] : null,
            ($metrics['indexation_health']['redirect_issue'] ?? false) ? ['label' => 'Redirect detected', 'tone' => 'amber', 'tooltip' => 'The canonical or sitemap route still resolves through a redirect or stale route.'] : null,
            ($metrics['indexation_health']['canonical_accepted'] ?? true) === false ? ['label' => 'Canonical mismatch', 'tone' => 'amber', 'tooltip' => 'Google selected a different canonical than the platform expected.'] : null,
            ($metrics['freshness_score'] ?? 100) < 50 ? ['label' => 'Needs Refresh', 'tone' => 'amber', 'tooltip' => 'Age and freshness signals suggest this content should be refreshed.'] : null,
            ($metrics['internal_link_score'] ?? 100) < 50 ? ['label' => 'Weak Links', 'tone' => 'amber', 'tooltip' => 'Internal linking is below the desired operational baseline.'] : null,
            ($metrics['answer_block_score'] ?? 100) < 55 ? ['label' => 'Missing FAQ', 'tone' => 'amber', 'tooltip' => 'FAQ or answer extraction coverage is weak.'] : null,
            ($metrics['translation_parity_score'] ?? 100) < 65 ? ['label' => 'Translation Drift', 'tone' => 'amber', 'tooltip' => 'Localized variants are incomplete or lagging behind the source.'] : null,
            ($metrics['indexation_health']['indexed'] ?? true) === false ? ['label' => 'Not indexed', 'tone' => 'red', 'tooltip' => 'Google Search Console reports this page as not indexed.'] : null,
            ($metrics['indexation_health']['duplicate_detected'] ?? false) ? ['label' => 'Duplicate detected', 'tone' => 'red', 'tooltip' => 'This content family has duplicate locale or canonical conflicts.'] : null,
            ($metrics['indexation_health']['canonical_accepted'] ?? true) === false ? ['label' => 'Google ignored canonical', 'tone' => 'red', 'tooltip' => 'Google chose a different canonical URL than the one Argusly expects.'] : null,
            ($metrics['decay_risk_level'] ?? '') === 'critical' ? ['label' => 'High Decay', 'tone' => 'red', 'tooltip' => 'This content is decaying and should enter refresh operations.'] : null,
            ($metrics['ai_visibility_score'] ?? 100) < 40 ? ['label' => 'AI Visibility Low', 'tone' => 'red', 'tooltip' => 'AI visibility is below the healthy threshold.'] : null,
            ($metrics['answer_block_score'] ?? 100) < 35 ? ['label' => 'Missing Answer Blocks', 'tone' => 'red', 'tooltip' => 'Structured answer coverage is missing or failing.'] : null,
            ($metrics['internal_link_score'] ?? 100) < 25 ? ['label' => 'Orphan Content', 'tone' => 'red', 'tooltip' => 'This content has very weak internal linking support.'] : null,
        ])->filter();

        return $badges->take(3)->values()->all();
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<int,array<string,mixed>>
     */
    public function recommendations(Content $content, array $metrics): array
    {
        $persisted = $content->relationLoaded('recommendations')
            ? $content->recommendations
            : $content->recommendations()->get();

        $stored = $persisted->map(function ($recommendation): array {
            $payload = (array) ($recommendation->payload ?? []);

            return [
                'type' => (string) $recommendation->type,
                'priority' => (string) $recommendation->priority,
                'status' => (string) ($recommendation->status?->value ?? $recommendation->status),
                'title' => (string) ($payload['title'] ?? Str::headline((string) $recommendation->type)),
                'summary' => (string) ($payload['summary'] ?? ''),
                'generated_by' => (string) ($recommendation->generated_by ?? 'system'),
            ];
        });

        $computed = collect([
            ($metrics['answer_block_score'] ?? 100) < 55 ? ['type' => 'add_faq_schema', 'priority' => 'high', 'status' => 'pending', 'title' => 'Add FAQ schema', 'summary' => 'Add structured FAQ coverage to improve answer extraction and result eligibility.', 'generated_by' => 'content_health'] : null,
            ($metrics['ai_visibility_score'] ?? 100) < 50 ? ['type' => 'improve_ai_answer_extraction', 'priority' => 'high', 'status' => 'pending', 'title' => 'Improve AI answer extraction', 'summary' => 'Add a direct answer near the top of the article and tighten entity coverage.', 'generated_by' => 'ai_visibility'] : null,
            ($metrics['freshness_score'] ?? 100) < 50 ? ['type' => 'refresh_statistics', 'priority' => 'high', 'status' => 'pending', 'title' => 'Refresh statistics', 'summary' => 'Update examples, numbers, and dated references before the content decays further.', 'generated_by' => 'decay_engine'] : null,
            ($metrics['internal_link_score'] ?? 100) < 50 ? ['type' => 'improve_internal_linking', 'priority' => 'medium', 'status' => 'pending', 'title' => 'Improve internal linking', 'summary' => 'Add contextual links from and to related content to strengthen discovery.', 'generated_by' => 'content_health'] : null,
            ($metrics['semantic_coverage_score'] ?? 100) < 60 ? ['type' => 'add_missing_entities', 'priority' => 'medium', 'status' => 'pending', 'title' => 'Add missing entities', 'summary' => 'Expand topic coverage with missing concepts, competitors, and support sections.', 'generated_by' => 'content_health'] : null,
            ($metrics['translation_parity_score'] ?? 100) < 65 ? ['type' => 'repair_translation_parity', 'priority' => 'medium', 'status' => 'pending', 'title' => 'Repair translation parity', 'summary' => 'Bring localized variants back in sync with the latest source content.', 'generated_by' => 'localization'] : null,
        ])->filter();

        return $stored
            ->concat($computed)
            ->unique(fn (array $row): string => (string) ($row['type'] ?? $row['title']))
            ->sortBy([
                [fn (array $row): int => match ((string) ($row['priority'] ?? 'medium')) {
                    'high' => 0,
                    'medium' => 1,
                    default => 2,
                }, 'asc'],
            ])
            ->values()
            ->all();
    }

    private function semanticCoverageScore(array $snapshot): int
    {
        $score = 30;
        $score += min(20, (int) (($snapshot['heading_count'] ?? 0) * 5));
        $score += min(20, (int) floor(((int) ($snapshot['word_count'] ?? 0)) / 180));
        $score += ! empty($snapshot['has_faq']) ? 15 : 0;
        $score += ((int) ($snapshot['title_h1_mismatch'] ?? 0)) === 0 ? 10 : 0;
        $score -= min(20, count((array) ($snapshot['missing_seo_fields'] ?? [])) * 7);

        return max(0, min(100, $score));
    }

    private function freshnessScore(Content $content, array $snapshot): int
    {
        $reference = $snapshot['latest_reference_at'] ?? null;
        $ageDays = $reference ? abs(now()->diffInDays($reference, false)) : 0;
        $currentYear = (int) now()->format('Y');
        $latestYear = collect((array) ($snapshot['body_years'] ?? []))->max();

        $score = match (true) {
            $ageDays <= 30 => 95,
            $ageDays <= 90 => 78,
            $ageDays <= 180 => 60,
            $ageDays <= 365 => 42,
            default => 25,
        };

        if (is_int($latestYear) && $latestYear < ($currentYear - 1)) {
            $score -= 12;
        }

        if ($content->lifecycleStageEnum()->normalized() === \App\Enums\ContentLifecycleStatus::REFRESH_NEEDED) {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    private function internalLinkScore(array $snapshot): int
    {
        $count = (int) ($snapshot['internal_link_count'] ?? 0);

        return match (true) {
            $count >= 6 => 95,
            $count >= 4 => 78,
            $count >= 2 => 58,
            $count === 1 => 35,
            default => 15,
        };
    }

    private function answerBlockScore(Content $content, array $snapshot): int
    {
        $persistedCount = (int) ($content->answer_block_generation_persisted_count ?? 0);
        $status = (string) ($content->answer_block_generation_status ?? '');
        $hasFaq = (bool) ($snapshot['has_faq'] ?? false);

        if ($persistedCount >= 3) {
            return 92;
        }

        if ($persistedCount > 0 || $hasFaq) {
            return $status === Content::ANSWER_BLOCK_STATUS_FAILED ? 42 : 68;
        }

        return $status === Content::ANSWER_BLOCK_STATUS_FAILED ? 20 : 30;
    }

    private function translationParityScore(Content $content): int
    {
        $enabledLocales = max(1, count($content->workspace?->getEnabledLanguagesAsEnums() ?? []));
        $family = $content->normalizedLocalizationFamily();
        $availableLocales = max(1, $family->pluck('language')->filter()->unique()->count());

        $base = (int) round(($availableLocales / $enabledLocales) * 100);
        $driftPenalty = $family
            ->filter(fn (Content $variant): bool => $variant->isTranslationVariant() && $variant->isTranslationOutdated())
            ->count() * 12;

        return max(0, min(100, $base - $driftPenalty));
    }

    private function competitorFreshnessRisk(int $freshnessScore, int $aiVisibilityScore): int
    {
        return max(0, min(100, (int) round(((100 - $freshnessScore) * 0.6) + ((100 - $aiVisibilityScore) * 0.4))));
    }

    private function contentHealthScore(
        int $freshnessScore,
        ?int $aiVisibilityScore,
        int $semanticCoverageScore,
        int $internalLinkScore,
        int $answerBlockScore,
        int $translationParityScore,
        Content $content,
        array $indexationHealth = [],
    ): int {
        $components = [
            $freshnessScore * 0.22,
            ($aiVisibilityScore ?? max(40, (int) ($content->aeo_score ?? 40))) * 0.18,
            $semanticCoverageScore * 0.18,
            $internalLinkScore * 0.14,
            $answerBlockScore * 0.12,
            $translationParityScore * 0.08,
            ($this->missingSeoFields($content) === [] ? 88 : 52) * 0.05,
            ($content->clientSite?->supports_meta_title ? 75 : 55) * 0.03,
        ];

        $score = (int) round(array_sum($components));
        $score -= ($indexationHealth['indexed'] ?? null) === false ? 10 : 0;
        $score -= ($indexationHealth['canonical_accepted'] ?? null) === false ? 8 : 0;
        $score -= ($indexationHealth['redirect_issue'] ?? false) ? 7 : 0;
        $score -= ($indexationHealth['duplicate_detected'] ?? false) ? 10 : 0;

        return max(0, min(100, $score));
    }

    private function optimizationOpportunityScore(
        int $healthScore,
        int $semanticCoverage,
        int $internalLinkScore,
        int $answerBlockScore,
        ?int $aiVisibilityScore,
    ): int {
        $gapAverage = collect([
            100 - $healthScore,
            100 - $semanticCoverage,
            100 - $internalLinkScore,
            100 - $answerBlockScore,
            100 - ($aiVisibilityScore ?? 55),
        ])->avg() ?? 0;

        return max(0, min(100, (int) round($gapAverage)));
    }

    private function intelligenceStatus(
        int $healthScore,
        int $optimizationOpportunityScore,
        string $decayRiskLevel,
        ?int $aiVisibilityScore,
        Content $content,
    ): ContentIntelligenceStatus {
        if ($content->ai_optimized_at) {
            return ContentIntelligenceStatus::AI_OPTIMIZED;
        }

        return match (true) {
            $decayRiskLevel === 'critical' => ContentIntelligenceStatus::DECAYING,
            $healthScore < 50 || ($aiVisibilityScore ?? 100) < 40 => ContentIntelligenceStatus::AT_RISK,
            $optimizationOpportunityScore >= 45 => ContentIntelligenceStatus::OPPORTUNITY,
            default => ContentIntelligenceStatus::HEALTHY,
        };
    }

    private function resolveStoredOrComputed(mixed $stored, ?int $computed): ?int
    {
        if (is_numeric($stored)) {
            return max(0, min(100, (int) $stored));
        }

        if ($computed === null) {
            return null;
        }

        return max(0, min(100, $computed));
    }

    public function resolveBodyHtml(?Content $content): string
    {
        if (! $content) {
            return '';
        }

        $content->loadMissing('currentRevision', 'currentVersion', 'drafts');

        $latestDraft = $content->drafts
            ->sortByDesc(fn ($draft): string => sprintf(
                '%010d-%s',
                max(
                    $draft->updated_at?->getTimestamp() ?? 0,
                    $draft->created_at?->getTimestamp() ?? 0,
                ),
                (string) $draft->id,
            ))
            ->first();

        return trim((string) (
            $latestDraft?->content_html
            ?: $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));
    }

    /**
     * @return array<int,string>
     */
    public function missingSeoFields(Content $content): array
    {
        return collect([
            'seo_title' => trim((string) ($content->seo_title ?? '')),
            'seo_meta_description' => trim((string) ($content->seo_meta_description ?? '')),
            'seo_h1' => trim((string) ($content->seo_h1 ?? '')),
        ])->filter(fn (string $value): bool => $value === '')
            ->keys()
            ->values()
            ->all();
    }

    public function hasTitleH1Mismatch(Content $content): bool
    {
        $title = $this->normalizeComparable((string) $content->title);
        $h1 = $this->normalizeComparable((string) ($content->seo_h1 ?? ''));

        return $title !== '' && $h1 !== '' && $title !== $h1;
    }

    public function targetWordCount(Content $content): int
    {
        $targets = (array) config('content_refresh.thresholds.type_word_count_targets', []);

        return (int) ($targets[(string) $content->type] ?? $targets['article'] ?? 0);
    }

    public function latestReferenceAt(Content $content): ?\Illuminate\Support\Carbon
    {
        $content->loadMissing('currentRevision', 'currentVersion', 'drafts');

        $latestDraft = $content->drafts
            ->sortByDesc(fn ($draft): string => sprintf(
                '%010d-%s',
                max(
                    $draft->updated_at?->getTimestamp() ?? 0,
                    $draft->created_at?->getTimestamp() ?? 0,
                ),
                (string) $draft->id,
            ))
            ->first();

        return collect([
            $latestDraft?->updated_at,
            $latestDraft?->created_at,
            $content->currentVersion?->updated_at,
            $content->currentVersion?->created_at,
            $content->currentRevision?->updated_at,
            $content->currentRevision?->created_at,
            $content->updated_at,
            $content->created_at,
        ])->filter()->sortDesc()->first();
    }

    /**
     * @return array<int,string>
     */
    private function extractHeadings(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = $this->loadDocument($html);
        $headings = [];

        foreach (['h1', 'h2', 'h3', 'h4'] as $tagName) {
            foreach ($document->getElementsByTagName($tagName) as $heading) {
                if (! $heading instanceof DOMElement) {
                    continue;
                }

                $value = trim(preg_replace('/\s+/u', ' ', (string) $heading->textContent) ?? '');
                if ($value !== '') {
                    $headings[] = $value;
                }
            }
        }

        return array_values(array_unique($headings));
    }

    /**
     * @return array<int,string>
     */
    private function linkUrls(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = $this->loadDocument($html);
        $links = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            if ($href !== '') {
                $links[] = $href;
            }
        }

        return array_values(array_unique($links));
    }

    private function internalLinkCount(string $html, string $siteUrl): int
    {
        if ($html === '') {
            return 0;
        }

        $document = $this->loadDocument($html);
        $siteHost = parse_url($siteUrl, PHP_URL_HOST);
        $count = 0;

        foreach ($document->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            if (str_starts_with($href, '/')) {
                $count++;
                continue;
            }

            $hrefHost = parse_url($href, PHP_URL_HOST);
            if ($siteHost !== null && $hrefHost !== null && Str::lower($siteHost) === Str::lower($hrefHost)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int,int>
     */
    private function extractYears(string $plainText): array
    {
        preg_match_all('/\b(20\d{2})\b/', $plainText, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($year): int => (int) $year)
            ->filter(fn (int $year): bool => $year >= 2000 && $year <= ((int) now()->format('Y') + 1))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $headings
     */
    private function hasFaq(?Content $content, array $headings): bool
    {
        $headingFaq = collect($headings)->contains(function (string $heading): bool {
            $normalized = Str::lower($heading);

            return str_contains($normalized, 'faq')
                || str_contains($normalized, 'frequently asked')
                || str_contains($normalized, 'questions');
        });

        if ($headingFaq || ! $content) {
            return $headingFaq;
        }

        foreach ([
            data_get($content->currentRevision?->meta, 'faq'),
            data_get($content->currentRevision?->meta, 'faqs'),
            data_get($content->currentVersion?->meta, 'faq'),
            data_get($content->currentVersion?->meta, 'faqs'),
            data_get($content->currentVersion?->meta, 'faq_items'),
            data_get($content->currentVersion?->meta, 'questions'),
        ] as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }

    private function normalizeComparable(string $value): string
    {
        return trim(Str::lower(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
