<?php

namespace App\Services\Content;

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\TaxonomyItem;
use App\Support\ContentIntentCatalog;
use App\Support\EditorialTaxonomyService;

class SeriesBriefPayloadFactory
{
    public function __construct(
        private readonly EditorialTaxonomyService $taxonomyService,
        private readonly IntentDetectionService $intentDetectionService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  array<int, string>  $secondaryKeywords
     * @param  array<int, int>  $internalLinksTo
     * @return array<string, mixed>
     */
    public function build(
        ContentSeries $series,
        ClientSite $site,
        array $article,
        int $articleNumber,
        string $title,
        string $primaryKeyword,
        array $secondaryKeywords,
        string $slug,
        string $plannedUrl,
        array $internalLinksTo,
    ): array {
        $outputType = $this->resolveOutputType($article);
        $roleContext = $this->resolveRoleContext($series, $article, $articleNumber);
        $intentKeys = $this->resolveIntentKeys($series, $article, $articleNumber, $outputType, $title, $primaryKeyword, $roleContext['is_pillar']);
        $audienceKeys = $this->resolveAudienceKeys($series, $article);
        $targetAudience = trim((string) data_get($article, 'target_audience', ''))
            ?: trim((string) ($series->audience ?? ''));

        return [
            'client' => [
                'type' => 'content_series',
                'site_url' => (string) ($site->site_url ?: $site->base_url ?: ''),
            ],
            'brief' => [
                'title' => $title,
                'language' => $site->workspace?->defaultContentLanguageCode() ?? 'en',
                'intent' => [
                    'keys' => $intentKeys,
                ],
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'audience_keys' => $audienceKeys,
                'target_audience' => $targetAudience !== '' ? $targetAudience : null,
                'funnel_stage' => (string) ($series->funnel_stage ?? '') ?: null,
                'tone_of_voice' => (string) ($series->tone ?? '') ?: null,
                'output_type' => $outputType,
                'content_type' => $this->mapOutputTypeToBriefContentType($outputType),
                'preferred_length' => $this->resolvePreferredLength($article, $articleNumber, $roleContext['is_pillar']),
                'notes' => $this->buildNotes($series, $article, $articleNumber, $slug, $plannedUrl, $internalLinksTo, $roleContext),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<int, string>
     */
    private function resolveIntentKeys(
        ContentSeries $series,
        array $article,
        int $articleNumber,
        string $outputType,
        string $title,
        string $primaryKeyword,
        bool $isPillar,
    ): array {
        $selected = ContentIntentCatalog::normalizeKeys((array) ($series->intent_keys ?? []));
        $detected = $selected === []
            ? $this->intentDetectionService->detectFromKeywords(
                $primaryKeyword !== '' ? $primaryKeyword : (string) $series->primary_keyword,
                array_values(array_filter(array_map('strval', [
                    ...((array) data_get($article, 'secondary_keywords', [])),
                    $title,
                ])))
            )
            : [];
        $keys = $selected !== []
            ? $selected
            : array_values(array_unique(array_merge(
                ContentIntentCatalog::defaultsForOutputType($outputType),
                $detected
            )));

        return array_values(array_unique(array_merge(
            $keys,
            $this->intentExtensionsForArticle($article, $articleNumber, $title, $primaryKeyword, $isPillar)
        )));
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<int, string>
     */
    private function resolveAudienceKeys(ContentSeries $series, array $article): array
    {
        $organizationId = (int) $series->organization_id;
        $this->taxonomyService->ensureDefaults($organizationId);

        $availableKeys = array_keys($this->taxonomyService->activeItemMapByTenantAndType($organizationId, TaxonomyItem::TYPE_AUDIENCE));
        $candidateValues = collect([
            ...((array) data_get($article, 'audience_keys', [])),
            (string) data_get($article, 'target_audience', ''),
            (string) ($series->audience ?? ''),
        ]);

        $resolved = $candidateValues
            ->flatMap(function ($value): array {
                if (is_array($value)) {
                    return $value;
                }

                $string = trim((string) $value);
                if ($string === '') {
                    return [];
                }

                return preg_split('/[,;\n|]+/', $string) ?: [];
            })
            ->map(fn ($value): string => $this->normalizeAudienceKey((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && in_array($value, $availableKeys, true))
            ->unique()
            ->values()
            ->all();

        if ($resolved !== []) {
            return $resolved;
        }

        foreach (['operations', 'developer'] as $fallback) {
            if (in_array($fallback, $availableKeys, true)) {
                return [$fallback];
            }
        }

        return $availableKeys !== [] ? [(string) $availableKeys[0]] : [];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<int, string>
     */
    private function intentExtensionsForArticle(array $article, int $articleNumber, string $title, string $primaryKeyword, bool $isPillar): array
    {
        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) data_get($article, 'type', ''),
            (string) data_get($article, 'article_type', ''),
            $title,
            $primaryKeyword,
        ]))));

        $extensions = [];

        if ($isPillar || $articleNumber === 1) {
            $extensions[] = 'compare';
        }

        if (str_contains($haystack, 'process')) {
            $extensions[] = 'process';
        }

        if (str_contains($haystack, 'strateg')) {
            $extensions[] = 'strategic';
        }

        if (str_contains($haystack, 'solution')) {
            $extensions[] = 'solution';
        }

        return $extensions;
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private function resolveOutputType(array $article): string
    {
        $outputType = strtolower(trim((string) data_get($article, 'output_type', '')));
        if ($outputType !== '') {
            return $outputType;
        }

        $contentType = strtolower(trim((string) data_get($article, 'content_type', '')));

        return match ($contentType) {
            'landing', 'landing_page' => 'seo_page',
            'blog', 'blog_post' => 'article',
            default => 'kb_article',
        };
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private function resolvePreferredLength(array $article, int $articleNumber, bool $isPillar): string
    {
        $preferredLength = strtolower(trim((string) data_get($article, 'preferred_length', '')));
        if (in_array($preferredLength, ['short', 'medium', 'long', 'pillar'], true)) {
            return $preferredLength;
        }

        return $isPillar || $articleNumber === 1 ? 'pillar' : 'medium';
    }

    private function mapOutputTypeToBriefContentType(string $outputType): string
    {
        return match (ContentIntentCatalog::outputFamily($outputType)) {
            'landing_page' => 'landing',
            default => 'blog',
        };
    }

    /**
     * @param  array<int, int>  $internalLinksTo
     */
    private function buildNotes(ContentSeries $series, array $article, int $articleNumber, string $slug, string $plannedUrl, array $internalLinksTo, array $roleContext): string
    {
        $lines = array_filter([
            'Series: ' . (string) $series->name,
            'Article number: ' . $articleNumber,
            ! empty($series->intent_keys) ? 'Series intents: ' . implode(', ', (array) $series->intent_keys) : null,
            'Chain role: ' . ($roleContext['is_pillar'] ? 'pillar' : 'supporting'),
            $roleContext['is_pillar']
                ? 'Treat this article as the broad, authoritative pillar page for the chain.'
                : 'Treat this article as a narrower supporting article that naturally reinforces the pillar topic.',
            ! $roleContext['is_pillar'] && trim((string) ($roleContext['pillar_title'] ?? '')) !== ''
                ? 'Pillar article: ' . (string) $roleContext['pillar_title']
                : null,
            ! $roleContext['is_pillar'] && trim((string) ($roleContext['pillar_primary_keyword'] ?? '')) !== ''
                ? 'Pillar keyword: ' . (string) $roleContext['pillar_primary_keyword']
                : null,
            $roleContext['is_pillar'] && ! empty($roleContext['supporting_titles'])
                ? 'Supporting articles: ' . implode(', ', (array) $roleContext['supporting_titles'])
                : null,
            $slug !== '' ? 'Slug: ' . $slug : null,
            $plannedUrl !== '' ? 'Planned URL: ' . $plannedUrl : null,
            ! empty((array) data_get($series->strategy_json, 'meta.source_references', []))
                ? 'Source references: ' . implode(' | ', (array) data_get($series->strategy_json, 'meta.source_references', []))
                : null,
            ! empty((array) data_get($series->strategy_json, 'meta.source_references', []))
                ? 'Use sources as strategic context only. Do not copy, summarize, or mirror them section by section.'
                : null,
            trim((string) data_get($series->strategy_json, 'meta.strategic_positioning', '')) !== ''
                ? 'Strategic positioning: ' . trim((string) data_get($series->strategy_json, 'meta.strategic_positioning', ''))
                : null,
            trim((string) data_get($series->strategy_json, 'meta.complete_briefing.raw', '')) !== ''
                ? 'Complete chain briefing: ' . \Illuminate\Support\Str::limit(trim((string) data_get($series->strategy_json, 'meta.complete_briefing.raw', '')), 20000, '')
                : null,
            trim((string) data_get($series->strategy_json, 'meta.complete_briefing.raw', '')) !== ''
                ? 'Use the complete chain briefing as source-of-truth context for this article.'
                : null,
            $internalLinksTo !== [] ? 'Internal links to: ' . implode(', ', $internalLinksTo) : null,
            trim((string) data_get($article, 'editorial_angle', '')) !== ''
                ? 'Editorial guidance: ' . trim((string) data_get($article, 'editorial_angle', ''))
                : null,
        ]);

        return implode("\n", $lines);
    }

    private function normalizeAudienceKey(string $value): string
    {
        $normalized = EditorialTaxonomyService::normalizeKeyStatic($value);

        if ($normalized === '') {
            return '';
        }

        return match (true) {
            str_contains($normalized, 'develop'),
            str_contains($normalized, 'engineer'),
            str_contains($normalized, 'dev') => 'developer',
            str_contains($normalized, 'cto') => 'cto',
            str_contains($normalized, 'tech_lead'),
            str_contains($normalized, 'technical_lead') => 'tech_lead',
            str_contains($normalized, 'market') => 'marketer',
            str_contains($normalized, 'founder') => 'founder',
            str_contains($normalized, 'operation'),
            str_contains($normalized, 'ops') => 'operations',
            default => $normalized,
        };
    }

    /**
     * @return array{
     *   is_pillar:bool,
     *   pillar_title:?string,
     *   pillar_primary_keyword:?string,
     *   supporting_titles:array<int,string>
     * }
     */
    private function resolveRoleContext(ContentSeries $series, array $article, int $articleNumber): array
    {
        $articles = collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row, int $index): array {
                $row['article_number'] = (int) data_get($row, 'article_number', $index + 1);

                return $row;
            })
            ->values();

        $pillar = $articles->first(fn (array $row): bool => (bool) data_get($row, 'is_pillar', false));
        $isPillar = (bool) data_get($article, 'is_pillar', false);

        if (! $isPillar && ! $pillar && $articleNumber === 1) {
            $isPillar = true;
        }

        if (! $pillar && $isPillar) {
            $pillar = $article;
        }

        return [
            'is_pillar' => $isPillar,
            'pillar_title' => $this->nullableString(data_get($pillar, 'title')),
            'pillar_primary_keyword' => $this->nullableString(data_get($pillar, 'primary_keyword')),
            'supporting_titles' => $articles
                ->reject(fn (array $row): bool => (int) data_get($row, 'article_number', 0) === $articleNumber)
                ->map(fn (array $row): string => trim((string) data_get($row, 'title', '')))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
