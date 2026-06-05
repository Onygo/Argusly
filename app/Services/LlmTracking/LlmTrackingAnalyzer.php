<?php

namespace App\Services\LlmTracking;

use App\Models\LlmSourceRule;
use App\Models\LlmTrackingQuery;
use App\Services\LlmTracking\Data\AnalysisResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LlmTrackingAnalyzer
{
    /**
     * Current flow and storage model:
     * 1) Run job stores one row per tracking execution in llm_tracking_query_runs.
     * 2) This analyzer transforms raw answer text into deterministic JSON fields.
     * 3) Run-level metrics (mentions, citations, sources, SoV, suggestions) stay in the run row.
     * 4) Aggregates are computed separately from succeeded non-cached runs.
     *
     * Future improvements:
     * - Use provider-native citation objects when available (instead of URL regex parsing).
     * - Compare SoV/citation placement across multiple models in one run group.
     * - Replace keyword heuristics with embedding-based topic extraction.
     */
    public function analyzeAnswer(string $answer, LlmTrackingQuery $query, array $rawResponse = []): AnalysisResult
    {
        $brandTerms = (array) ($query->brand_terms ?? []);
        $competitorTerms = (array) ($query->competitor_terms ?? []);
        $targetUrls = (array) ($query->target_urls ?? []);

        $brandHits = $this->extractMentions($answer, $brandTerms);
        $competitorHits = $this->extractMentions($answer, $competitorTerms);

        $extractedUrls = collect([
            ...$this->extractUrls($answer),
            ...$this->extractRawUrls($rawResponse),
        ])
            ->sortBy('position')
            ->unique(fn (array $hit): string => $this->normalizeUrlForMatch((string) ($hit['url'] ?? '')))
            ->values()
            ->all();
        $urlHits = $this->mapUrlsToTargets($extractedUrls, $targetUrls);
        $sources = $this->extractSources($answer, $extractedUrls);
        $detectedDomains = $this->detectedDomains($sources);
        $firstMention = $this->resolveFirstMention($answer, $brandHits);

        $citationRanking = $this->computeCitationRanking($brandHits, $urlHits, max(1, strlen($answer)));
        $shareOfVoiceSnapshot = $this->computeShareOfVoiceSnapshot($brandHits, $competitorHits);
        $suggestions = $this->buildSuggestions(
            query: $query,
            brandHits: $brandHits,
            competitorHits: $competitorHits,
            urlHits: $urlHits,
            sources: $sources,
        );

        return new AnalysisResult(
            brandHits: $brandHits,
            competitorHits: $competitorHits,
            urlHits: $urlHits,
            citationRanking: $citationRanking,
            sources: $sources,
            detectedDomains: $detectedDomains,
            firstMentionIndex: $firstMention['index'],
            firstMentionBlock: $firstMention['block'],
            firstMentionContext: $firstMention['context'],
            shareOfVoiceSnapshot: $shareOfVoiceSnapshot,
            suggestions: $suggestions,
        );
    }

    /**
     * @param array<int,string> $terms
     * @return array<int,array<string,mixed>>
     */
    public function extractMentions(string $answer, array $terms): array
    {
        $sentenceOffsets = $this->sentenceOffsets($answer);
        $answerLength = max(1, strlen($answer));
        $hits = [];

        foreach ($terms as $termRaw) {
            $term = trim((string) $termRaw);
            if ($term === '') {
                continue;
            }

            $pattern = '/' . preg_quote($term, '/') . '/iu';
            preg_match_all($pattern, $answer, $matches, PREG_OFFSET_CAPTURE);
            $occurrences = (array) ($matches[0] ?? []);
            if ($occurrences === []) {
                continue;
            }

            $positions = collect($occurrences)
                ->map(fn ($match) => (int) ($match[1] ?? -1))
                ->filter(fn (int $position): bool => $position >= 0)
                ->values();

            if ($positions->isEmpty()) {
                continue;
            }

            $firstPosition = (int) $positions->min();
            $normalized = $firstPosition / $answerLength;
            $hits[] = [
                'term' => $term,
                'count' => $positions->count(),
                'first_position' => $firstPosition,
                'first_sentence_index' => $this->sentenceIndexForOffset($sentenceOffsets, $firstPosition),
                'context_snippets' => $this->buildContextSnippets($answer, $positions->all()),
                'normalized_position' => round($normalized, 4),
                'bucket' => $this->bucketFromNormalized($normalized),
            ];
        }

        return $hits;
    }

    /**
     * @return array<int,array{url:string,position:int,first_sentence_index:int,normalized_position:float,bucket:string}>
     */
    public function extractUrls(string $answer): array
    {
        preg_match_all('/https?:\/\/[^\s<>"\'\)\]]+/i', $answer, $matches, PREG_OFFSET_CAPTURE);
        $sentenceOffsets = $this->sentenceOffsets($answer);
        $answerLength = max(1, strlen($answer));
        $hits = [];

        foreach ((array) ($matches[0] ?? []) as $match) {
            $rawUrl = (string) ($match[0] ?? '');
            $position = (int) ($match[1] ?? -1);
            if ($rawUrl === '' || $position < 0) {
                continue;
            }

            $url = rtrim($rawUrl, ".,;:!?)]}");
            $normalized = $position / $answerLength;
            $hits[] = [
                'url' => $url,
                'position' => $position,
                'first_sentence_index' => $this->sentenceIndexForOffset($sentenceOffsets, $position),
                'normalized_position' => round($normalized, 4),
                'bucket' => $this->bucketFromNormalized($normalized),
            ];
        }

        return $hits;
    }

    /**
     * @param array<int,array<string,mixed>> $extractedUrls
     * @param array<int,string> $targetUrls
     * @return array<int,array<string,mixed>>
     */
    public function mapUrlsToTargets(array $extractedUrls, array $targetUrls): array
    {
        $targetHits = [];

        foreach ($targetUrls as $targetRaw) {
            $targetUrl = trim((string) $targetRaw);
            if ($targetUrl === '') {
                continue;
            }

            $targetNorm = $this->normalizeUrlForMatch($targetUrl);
            $matches = collect($extractedUrls)->filter(function (array $urlHit) use ($targetNorm): bool {
                $foundNorm = $this->normalizeUrlForMatch((string) ($urlHit['url'] ?? ''));

                return $foundNorm !== '' && (
                    $foundNorm === $targetNorm
                    || Str::startsWith($foundNorm, $targetNorm . '/')
                    || Str::startsWith($targetNorm, $foundNorm . '/')
                );
            })->values();

            if ($matches->isEmpty()) {
                continue;
            }

            /** @var array{position:int,first_sentence_index:int,normalized_position:float,bucket:string,url:string} $first */
            $first = $matches->sortBy('position')->first();

            $targetHits[] = [
                'target_url' => $targetUrl,
                'count' => $matches->count(),
                'first_position' => (int) $first['position'],
                'first_sentence_index' => (int) $first['first_sentence_index'],
                'normalized_position' => (float) $first['normalized_position'],
                'bucket' => (string) $first['bucket'],
                'matched_urls' => $matches
                    ->pluck('url')
                    ->filter()
                    ->unique()
                    ->take(3)
                    ->values()
                    ->all(),
            ];
        }

        return $targetHits;
    }

    /**
     * @param array<int,array<string,mixed>> $extractedUrls
     * @return array<int,array<string,mixed>>
     */
    public function extractSources(string $answer, array $extractedUrls): array
    {
        $sources = [];
        $seen = [];

        foreach ($extractedUrls as $urlHit) {
            $url = trim((string) ($urlHit['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $normalized = $this->normalizeUrlForMatch($url);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $domain = $this->extractDomain($url);
            $sources[] = [
                'url' => $url,
                'domain' => $domain,
                'type' => $this->classifySource($url, $domain),
                'title' => null,
                'position' => (int) ($urlHit['position'] ?? 0),
            ];
        }

        return $sources;
    }

    public function classifySource(string $url, string $domain): string
    {
        foreach ($this->sourceRules() as $rule) {
            $pattern = trim((string) $rule->domain_pattern);
            if ($pattern === '') {
                continue;
            }

            if ($this->matchesPattern($domain, $pattern) || $this->matchesPattern($url, $pattern)) {
                return (string) $rule->type;
            }
        }

        $domainLower = Str::lower($domain);
        $urlLower = Str::lower($url);

        if (Str::contains($domainLower, 'wikipedia.org')) {
            return 'wikipedia';
        }

        if (Str::contains($domainLower, 'blog.') || Str::contains($urlLower, '/blog')) {
            return 'blog';
        }

        if ($this->isKnownNewsDomain($domainLower) || Str::contains($urlLower, '/news')) {
            return 'news';
        }

        if (Str::contains($domainLower, 'docs.') || Str::contains($urlLower, '/docs')) {
            return 'docs';
        }

        if (
            Str::contains($domainLower, 'forum')
            || Str::contains($domainLower, 'reddit.com')
            || Str::contains($domainLower, 'stackoverflow.com')
            || Str::contains($domainLower, 'stackexchange.com')
        ) {
            return 'forum';
        }

        return $domainLower === '' ? 'unknown' : 'website';
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $urlHits
     * @return array<string,mixed>
     */
    public function computeCitationRanking(array $brandHits, array $urlHits, int $answerLength = 1): array
    {
        $answerLength = max(1, $answerLength);

        $brandPositions = collect($brandHits)
            ->pluck('first_position')
            ->filter(fn ($position) => is_numeric($position))
            ->map(fn ($position): int => (int) $position)
            ->values();

        $urlPositions = collect($urlHits)
            ->pluck('first_position')
            ->filter(fn ($position) => is_numeric($position))
            ->map(fn ($position): int => (int) $position)
            ->values();

        $brandFirst = $brandPositions->isEmpty() ? null : (int) $brandPositions->min();
        $brandLast = $brandPositions->isEmpty() ? null : (int) $brandPositions->max();
        $urlFirst = $urlPositions->isEmpty() ? null : (int) $urlPositions->min();
        $urlLast = $urlPositions->isEmpty() ? null : (int) $urlPositions->max();

        $brandNormalized = $brandFirst !== null ? round($brandFirst / $answerLength, 4) : null;
        $urlNormalized = $urlFirst !== null ? round($urlFirst / $answerLength, 4) : null;

        return [
            'brand' => [
                'first_index' => $brandFirst,
                'last_index' => $brandLast,
                'normalized_position' => $brandNormalized,
                'bucket' => $brandNormalized !== null ? $this->bucketFromNormalized($brandNormalized) : null,
            ],
            'url' => [
                'first_index' => $urlFirst,
                'last_index' => $urlLast,
                'normalized_position' => $urlNormalized,
                'bucket' => $urlNormalized !== null ? $this->bucketFromNormalized($urlNormalized) : null,
            ],
            'brand_bucket' => $brandNormalized !== null ? $this->bucketFromNormalized($brandNormalized) : null,
            'url_bucket' => $urlNormalized !== null ? $this->bucketFromNormalized($urlNormalized) : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $competitorHits
     * @return array<string,mixed>
     */
    public function computeShareOfVoiceSnapshot(array $brandHits, array $competitorHits): array
    {
        $brandTotal = (int) collect($brandHits)->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0));
        $competitorTotal = (int) collect($competitorHits)->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0));
        $denominator = $brandTotal + $competitorTotal;

        return [
            'brand_total_mentions' => $brandTotal,
            'competitor_total_mentions' => $competitorTotal,
            'share_brand' => $denominator > 0 ? round($brandTotal / $denominator, 4) : null,
            'share_by_term' => [
                'brand' => collect($brandHits)->map(function (array $hit) use ($denominator): array {
                    $count = (int) ($hit['count'] ?? 0);

                    return [
                        'term' => (string) ($hit['term'] ?? ''),
                        'count' => $count,
                        'share' => $denominator > 0 ? round($count / $denominator, 4) : null,
                    ];
                })->values()->all(),
                'competitors' => collect($competitorHits)->map(function (array $hit) use ($denominator): array {
                    $count = (int) ($hit['count'] ?? 0);

                    return [
                        'term' => (string) ($hit['term'] ?? ''),
                        'count' => $count,
                        'share' => $denominator > 0 ? round($count / $denominator, 4) : null,
                    ];
                })->values()->all(),
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $competitorHits
     * @param array<int,array<string,mixed>> $urlHits
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,array<string,mixed>>
     */
    private function buildSuggestions(
        LlmTrackingQuery $query,
        array $brandHits,
        array $competitorHits,
        array $urlHits,
        array $sources,
    ): array {
        $suggestions = [];
        $keywords = $this->inferTopicKeywords(
            (string) $query->query_text,
            array_merge((array) ($query->brand_terms ?? []), (array) ($query->competitor_terms ?? [])),
        );

        $primaryKeyword = (string) ($keywords[0] ?? Str::limit((string) $query->query_text, 50, ''));
        $secondaryKeywords = array_values(array_slice($keywords, 1, 3));
        $slugBase = collect($keywords)->take(4)->implode('-');
        $slug = $slugBase !== '' ? Str::slug($slugBase) : Str::slug($query->name ?: 'llm-visibility-topic');

        $brandMentionCount = (int) collect($brandHits)->sum('count');
        $competitorMentionCount = (int) collect($competitorHits)->sum('count');
        $contentTopics = collect([$primaryKeyword, ...$secondaryKeywords])
            ->filter()
            ->values()
            ->all();
        $landingPages = collect([
            [
                'title' => Str::headline($primaryKeyword) . ' overview',
                'slug' => $slug !== '' ? $slug : Str::slug($query->name ?: 'ai-visibility'),
            ],
            [
                'title' => Str::headline($primaryKeyword) . ' comparison page',
                'slug' => $slug !== '' ? $slug . '-comparison' : 'ai-visibility-comparison',
            ],
        ])->unique('slug')->values()->all();
        $geoImprovements = [
            'Add direct-answer sections that mirror the tracked query wording.',
            'Create comparison copy that names competitors and explains your differentiation clearly.',
            'Strengthen entity consistency with PublishLayer references in headings, FAQs, and schema-supported sections.',
        ];

        if ($brandMentionCount === 0) {
            $suggestions[] = [
                'title' => 'Close the visibility gap for ' . $primaryKeyword,
                'rationale' => 'PublishLayer was not mentioned for this query, so this is a missing visibility opportunity.',
                'recommended_content_type' => $competitorMentionCount > 0 ? 'comparison' : 'pillar',
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'suggested_url_slug' => $slug !== '' ? $slug . '-overview' : 'ai-visibility-overview',
                'content_topics' => $contentTopics,
                'landing_pages' => $landingPages,
                'seo_geo_improvements' => $geoImprovements,
            ];
        }

        if ($competitorMentionCount > 0 && $brandMentionCount === 0) {
            $suggestions[] = [
                'title' => 'Create content about ' . $primaryKeyword,
                'rationale' => 'Competitors were mentioned, but your brand terms were missing in the answer.',
                'recommended_content_type' => 'comparison',
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'suggested_url_slug' => $slug . '-comparison',
                'content_topics' => $contentTopics,
                'landing_pages' => $landingPages,
                'seo_geo_improvements' => $geoImprovements,
            ];
        }

        if ($brandMentionCount > 0 && $urlHits === []) {
            $suggestions[] = [
                'title' => 'Publish a strong explainer page for ' . $primaryKeyword,
                'rationale' => 'Your brand is visible, but no target URL was cited.',
                'recommended_content_type' => 'pillar',
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'suggested_url_slug' => $slug . '-explainer',
                'content_topics' => $contentTopics,
                'landing_pages' => $landingPages,
                'seo_geo_improvements' => [
                    'Add a canonical landing page that answers the query directly.',
                    'Increase internal links pointing to the landing page from related cluster content.',
                    'Use concise FAQ and summary blocks so LLMs can lift direct answers from owned pages.',
                ],
            ];
        }

        $sourceTypeCounts = collect($sources)
            ->groupBy(fn (array $source): string => (string) ($source['type'] ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count());

        $wikiAndNewsCount = (int) (($sourceTypeCounts->get('wikipedia', 0)) + ($sourceTypeCounts->get('news', 0)));
        $sourceCount = max(1, (int) collect($sources)->count());
        $sourceRatio = $wikiAndNewsCount / $sourceCount;
        $hasOwnDomain = $this->hasOwnDomainSource($sources, (array) ($query->target_urls ?? []));

        if ($sourceRatio >= 0.5 && ! $hasOwnDomain) {
            $suggestions[] = [
                'title' => 'Create evergreen pillar content for ' . $primaryKeyword,
                'rationale' => 'Sources are mostly Wikipedia/news and do not include your domains.',
                'recommended_content_type' => 'pillar',
                'primary_keyword' => $primaryKeyword,
                'secondary_keywords' => $secondaryKeywords,
                'suggested_url_slug' => $slug . '-pillar-faq',
                'content_topics' => $contentTopics,
                'landing_pages' => $landingPages,
                'seo_geo_improvements' => [
                    'Publish evergreen pages on your own domain so answers cite owned sources instead of third-party references.',
                    'Add expert definitions, FAQ sections, and entity-rich summaries to increase citation readiness.',
                    'Expand internal links from related pages so the topic cluster is easier to discover.',
                ],
            ];
        }

        return collect($suggestions)
            ->unique('title')
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $excludedTerms
     * @return array<int,string>
     */
    private function inferTopicKeywords(string $queryText, array $excludedTerms): array
    {
        $text = Str::lower($queryText);

        foreach ($excludedTerms as $term) {
            $cleanTerm = trim((string) $term);
            if ($cleanTerm === '') {
                continue;
            }

            $text = preg_replace('/\b' . preg_quote(Str::lower($cleanTerm), '/') . '\b/u', ' ', $text) ?? $text;
        }

        $stopwords = (array) config('llm_tracking.analysis.ignore_words', []);
        $stopwordMap = array_fill_keys($stopwords, true);
        $tokens = preg_split('/[^a-z0-9]+/i', $text) ?: [];

        $frequencies = [];
        foreach ($tokens as $tokenRaw) {
            $token = trim((string) $tokenRaw);
            if ($token === '' || strlen($token) < 3 || isset($stopwordMap[$token])) {
                continue;
            }

            $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
        }

        arsort($frequencies);

        return array_values(array_slice(array_keys($frequencies), 0, 4));
    }

    /**
     * @param array<string,mixed> $rawResponse
     * @return array<int,array{url:string,position:int,first_sentence_index:int,normalized_position:float,bucket:string}>
     */
    private function extractRawUrls(array $rawResponse): array
    {
        $json = json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        return $this->extractUrls($json);
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<int,string>
     */
    private function detectedDomains(array $sources): array
    {
        return collect($sources)
            ->map(fn (array $source): string => Str::lower(trim((string) ($source['domain'] ?? ''))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @return array{index:?int,block:?string,context:?string}
     */
    private function resolveFirstMention(string $answer, array $brandHits): array
    {
        /** @var array<string,mixed>|null $first */
        $first = collect($brandHits)
            ->sortBy('first_position')
            ->first();

        $index = is_numeric($first['first_position'] ?? null)
            ? (int) $first['first_position']
            : null;

        if ($index === null) {
            return ['index' => null, 'block' => null, 'context' => null];
        }

        $blockIndex = $this->blockIndexForOffset($answer, $index);
        $context = collect((array) ($first['context_snippets'] ?? []))->first();

        return [
            'index' => $index,
            'block' => $blockIndex === null ? null : 'block_' . ($blockIndex + 1),
            'context' => is_string($context) && trim($context) !== '' ? $context : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param array<int,string> $targetUrls
     */
    private function hasOwnDomainSource(array $sources, array $targetUrls): bool
    {
        $targetDomains = collect($targetUrls)
            ->map(fn ($url): string => $this->extractDomain((string) $url))
            ->filter()
            ->unique()
            ->values();

        if ($targetDomains->isEmpty()) {
            return false;
        }

        foreach ($sources as $source) {
            $sourceDomain = (string) ($source['domain'] ?? '');
            if ($sourceDomain === '') {
                continue;
            }

            foreach ($targetDomains as $targetDomain) {
                if (
                    $sourceDomain === $targetDomain
                    || Str::endsWith($sourceDomain, '.' . $targetDomain)
                    || Str::endsWith($targetDomain, '.' . $sourceDomain)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,int> $positions
     * @return array<int,string>
     */
    private function buildContextSnippets(string $answer, array $positions): array
    {
        $snippets = [];
        $answerLength = strlen($answer);

        foreach (array_slice($positions, 0, 3) as $position) {
            $start = max(0, (int) $position - 60);
            $length = min(180, max(0, $answerLength - $start));
            $snippet = trim((string) substr($answer, $start, $length));
            if ($snippet === '') {
                continue;
            }

            if ($start > 0) {
                $snippet = '... ' . $snippet;
            }

            if (($start + $length) < $answerLength) {
                $snippet .= ' ...';
            }

            $snippets[] = $snippet;
        }

        return collect($snippets)->unique()->values()->all();
    }

    /**
     * @return array<int,int>
     */
    private function blockOffsets(string $answer): array
    {
        $offsets = [0];
        preg_match_all('/(?:\n\s*\n|(?:^|\n)\s*(?:[-*•]|\d+\.)\s+)/u', $answer, $matches, PREG_OFFSET_CAPTURE);

        foreach ((array) ($matches[0] ?? []) as $match) {
            $separator = (string) ($match[0] ?? '');
            $position = (int) ($match[1] ?? 0);
            $offsets[] = $position + strlen($separator);
        }

        return array_values(array_unique($offsets));
    }

    /**
     * @return array<int,int>
     */
    private function sentenceOffsets(string $answer): array
    {
        $offsets = [0];
        preg_match_all('/[.!?]\s+/u', $answer, $matches, PREG_OFFSET_CAPTURE);

        foreach ((array) ($matches[0] ?? []) as $match) {
            $separator = (string) ($match[0] ?? '');
            $position = (int) ($match[1] ?? 0);
            $offsets[] = $position + strlen($separator);
        }

        return array_values(array_unique($offsets));
    }

    /**
     * @param array<int,int> $sentenceOffsets
     */
    private function sentenceIndexForOffset(array $sentenceOffsets, int $offset): int
    {
        $index = 0;

        foreach ($sentenceOffsets as $candidateIndex => $sentenceOffset) {
            if ($offset < $sentenceOffset) {
                break;
            }
            $index = $candidateIndex;
        }

        return $index;
    }

    private function blockIndexForOffset(string $answer, int $offset): ?int
    {
        $blockOffsets = $this->blockOffsets($answer);
        if ($blockOffsets === []) {
            return null;
        }

        $index = 0;

        foreach ($blockOffsets as $candidateIndex => $blockOffset) {
            if ($offset < $blockOffset) {
                break;
            }

            $index = $candidateIndex;
        }

        return $index;
    }

    private function bucketFromNormalized(float $normalized): string
    {
        if ($normalized <= 0.3333) {
            return 'first';
        }

        if ($normalized <= 0.6667) {
            return 'middle';
        }

        return 'last';
    }

    private function normalizeUrlForMatch(string $url): string
    {
        $value = Str::lower(trim($url));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = preg_replace('#^www\.#', '', $value) ?? $value;
        $value = rtrim($value, '/');

        return $value;
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return Str::lower((string) $host);
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        $value = Str::lower($value);
        $pattern = Str::lower(trim($pattern));
        if ($pattern === '') {
            return false;
        }

        if (Str::contains($pattern, '*')) {
            return Str::is($pattern, $value);
        }

        return $value === $pattern || Str::contains($value, $pattern);
    }

    private function isKnownNewsDomain(string $domain): bool
    {
        $known = [
            'nytimes.com',
            'wsj.com',
            'bloomberg.com',
            'reuters.com',
            'theguardian.com',
            'forbes.com',
            'techcrunch.com',
            'bbc.com',
            'cnn.com',
            'ft.com',
        ];

        foreach ($known as $candidate) {
            if ($domain === $candidate || Str::endsWith($domain, '.' . $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Illuminate\Support\Collection<int,\App\Models\LlmSourceRule>
     */
    private function sourceRules(): Collection
    {
        return Cache::remember('llm_tracking_source_rules.v1', now()->addHour(), function (): Collection {
            if (! \Illuminate\Support\Facades\Schema::hasTable('llm_source_rules')) {
                return collect();
            }

            return LlmSourceRule::query()
                ->orderBy('priority')
                ->orderBy('id')
                ->get();
        });
    }
}
