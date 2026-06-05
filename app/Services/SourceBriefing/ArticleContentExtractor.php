<?php

namespace App\Services\SourceBriefing;

use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArticleContentExtractor
{
    private const MIN_WORD_COUNT = 120;

    private const MAX_EXTRACTED_CHARS = 60_000;

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html, string $fallbackUrl, string $mode = 'default', array $context = []): array
    {
        $startedAt = microtime(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousState = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($this->normalizeHtml($html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if (! $loaded) {
            Log::warning('source_briefing.extraction_parse_failed', array_merge($context, [
                'url' => $fallbackUrl,
                'mode' => $mode,
            ]));

            throw new SourceBriefingException(
                'SOURCE_EXTRACTION_UNSUPPORTED_STRUCTURE',
                'The page was fetched, but its structure could not be parsed. Try another public article URL.',
            );
        }

        $xpath = new DOMXPath($dom);
        $candidates = $this->collectExtractionCandidates($xpath, $mode);
        $winner = $candidates
            ->sortByDesc(fn (array $candidate): float => (float) $candidate['score'])
            ->first();

        if (! is_array($winner) || trim((string) ($winner['plain_text'] ?? '')) === '') {
            Log::warning('source_briefing.extraction_failed', array_merge($context, [
                'final_url' => $fallbackUrl,
                'mode' => $mode,
                'rejected_reason' => 'no_meaningful_candidate',
            ]));

            throw new SourceBriefingException(
                'SOURCE_EXTRACTION_EMPTY',
                'We could not extract readable article content from this page. Try another source or retry extraction.',
            );
        }

        $plainText = (string) $winner['plain_text'];
        $wordCount = (int) $winner['word_count'];
        $headings = (array) $winner['headings'];
        $quality = (array) $winner['quality'];

        if (! $this->passesQualityGate($quality)) {
            Log::warning('source_briefing.extraction_rejected', array_merge($context, [
                'final_url' => $fallbackUrl,
                'mode' => $mode,
                'method' => $winner['method'],
                'word_count' => $wordCount,
                'score' => $quality['score'] ?? null,
                'rejected_reason' => 'quality_below_threshold',
                'quality' => $quality,
            ]));

            throw new SourceBriefingException(
                $wordCount < self::MIN_WORD_COUNT ? 'SOURCE_EXTRACTION_TOO_SHORT' : 'SOURCE_EXTRACTION_UNSUPPORTED_STRUCTURE',
                $wordCount < self::MIN_WORD_COUNT
                    ? 'The extracted page content is too short to generate a reliable brief. Try a fuller article URL.'
                    : 'We could not confidently extract enough structured content from this page. Try another source or retry extraction.',
            );
        }

        $wasTruncated = false;
        if (mb_strlen($plainText) > self::MAX_EXTRACTED_CHARS) {
            $plainText = $this->truncateTextSafely($plainText, self::MAX_EXTRACTED_CHARS);
            $wasTruncated = true;
        }

        $title = $this->metaContent($xpath, "//*[self::title][1]") ?: $this->metaContent($xpath, "//meta[@property='og:title']/@content");
        $metaDescription = $this->metaContent($xpath, "//meta[@name='description']/@content")
            ?: $this->metaContent($xpath, "//meta[@property='og:description']/@content");
        $canonicalUrl = $this->metaContent($xpath, "//link[@rel='canonical']/@href") ?: $fallbackUrl;
        $language = $this->detectLanguage($xpath, $plainText);
        $summary = $this->buildSummary($plainText, $metaDescription);

        Log::info('source_briefing.extraction_selected', array_merge($context, [
            'final_url' => $fallbackUrl,
            'mode' => $mode,
            'method' => $winner['method'],
            'extracted_length' => mb_strlen($plainText),
            'estimated_tokens' => $this->estimateTokens($plainText),
            'truncated' => $wasTruncated,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'word_count' => $wordCount,
            'score' => $quality['score'] ?? null,
            'heading_count' => $quality['heading_count'] ?? null,
            'paragraph_count' => $quality['paragraph_count'] ?? null,
        ]));

        return [
            'final_url' => $canonicalUrl ?: $fallbackUrl,
            'domain' => (string) parse_url($fallbackUrl, PHP_URL_HOST),
            'title' => $title ?: ($headings['h1'] ?? 'Untitled source'),
            'meta_description' => $metaDescription,
            'canonical_url' => $canonicalUrl ?: null,
            'h1' => $headings['h1'],
            'outline' => [
                'h2' => $headings['h2'],
                'h3' => $headings['h3'],
            ],
            'plain_text' => $plainText,
            'detected_language' => $language,
            'word_count' => $wordCount,
            'publish_date' => $this->resolvePublishDate($xpath),
            'author' => $this->resolveAuthor($xpath),
            'summary' => $summary,
            'extraction_method' => (string) $winner['method'],
            'quality' => array_merge($quality, [
                'extracted_characters' => mb_strlen($plainText),
                'estimated_tokens' => $this->estimateTokens($plainText),
                'truncated' => $wasTruncated,
            ]),
        ];
    }

    private function normalizeHtml(string $html): string
    {
        if (preg_match('/<meta[^>]+charset=/i', $html)) {
            return $html;
        }

        return '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectExtractionCandidates(DOMXPath $xpath, string $mode): Collection
    {
        $candidates = collect();

        $readabilityNode = $this->resolveBestNode($xpath, [
            '//article',
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' article ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' content-body ')]",
        ]);
        if ($readabilityNode instanceof DOMElement) {
            $candidates->push($this->buildCandidate($xpath, $readabilityNode, 'readability_primary', $mode));
        }

        $semanticNode = $this->resolveBestNode($xpath, [
            '//main',
            '//article',
            "//*[@role='main']",
        ]);
        if ($semanticNode instanceof DOMElement) {
            $candidates->push($this->buildCandidate($xpath, $semanticNode, 'semantic_main', $mode));
        }

        $largestNode = $this->resolveLargestTextContainer($xpath);
        if ($largestNode instanceof DOMElement) {
            $candidates->push($this->buildCandidate($xpath, $largestNode, 'largest_text_container', $mode));
        }

        $body = $xpath->query('//body')->item(0);
        if ($body instanceof DOMElement) {
            $candidates->push($this->buildCandidate($xpath, $body, 'cleaned_body_fallback', $mode));
        }

        $metaFallback = $this->buildMetaHeadingFallbackCandidate($xpath);
        if ($metaFallback !== null) {
            $candidates->push($metaFallback);
        }

        return $candidates->unique(fn (array $candidate): string => (string) $candidate['method'])->values();
    }

    private function removeNoise(DOMXPath $xpath, DOMElement $mainNode): void
    {
        $noiseQueries = [
            './/script',
            './/style',
            './/noscript',
            './/svg',
            './/nav',
            './/footer',
            './/aside',
            './/form',
            './/button',
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' cookie ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' newsletter ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' related ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' share ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' social ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' menu ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' comments ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' overlay ')]",
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' modal ')]",
            ".//*[contains(concat(' ', normalize-space(@id), ' '), ' cookie ')]",
            ".//*[contains(concat(' ', normalize-space(@id), ' '), ' consent ')]",
        ];

        foreach ($noiseQueries as $query) {
            foreach ($xpath->query($query, $mainNode) ?: [] as $node) {
                if ($node instanceof DOMNode && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildMetaHeadingFallbackCandidate(DOMXPath $xpath): ?array
    {
        $headings = [
            'h1' => $this->metaContent($xpath, '//h1[1]'),
            'h2' => [],
            'h3' => [],
        ];

        foreach ($xpath->query('//h2|//h3') ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeWhitespace($heading->textContent ?? '');
            if ($text === '') {
                continue;
            }

            if ($heading->tagName === 'h2') {
                $headings['h2'][] = $text;
            } else {
                $headings['h3'][] = $text;
            }
        }

        $parts = collect([
            $this->metaContent($xpath, "//*[self::title][1]"),
            $this->metaContent($xpath, "//meta[@property='og:title']/@content"),
            $this->metaContent($xpath, "//meta[@name='description']/@content"),
            $this->metaContent($xpath, "//meta[@property='og:description']/@content"),
            $headings['h1'],
            ...array_slice($headings['h2'], 0, 8),
            ...array_slice($headings['h3'], 0, 8),
        ])->filter()->unique()->values();

        if ($parts->isEmpty()) {
            return null;
        }

        $plainText = $this->normalizeWhitespace($parts->implode('. '));
        $wordCount = str_word_count($plainText);

        return [
            'method' => 'meta_heading_fallback',
            'plain_text' => $plainText,
            'word_count' => $wordCount,
            'headings' => $headings,
            'quality' => [
                'score' => min(14, count($headings['h2']) + count($headings['h3']) + 4),
                'word_count' => $wordCount,
                'heading_count' => count($headings['h2']) + count($headings['h3']) + ($headings['h1'] ? 1 : 0),
                'paragraph_count' => 0,
                'keyword_density_score' => 0,
                'semantic_variation_score' => 0,
                'paragraph_density' => 0.0,
                'has_intro_paragraph' => false,
                'seo_article_structure' => false,
            ],
            'score' => 1.0,
        ];
    }

    /**
     * @return array{h1:?string,h2:array<int,string>,h3:array<int,string>}
     */
    private function extractHeadings(DOMXPath $xpath, DOMElement $mainNode): array
    {
        $h1 = null;
        $h2 = [];
        $h3 = [];

        foreach ($xpath->query('.//h1|.//h2|.//h3', $mainNode) ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeWhitespace($heading->textContent ?? '');
            if ($text === '') {
                continue;
            }

            if ($heading->tagName === 'h1' && $h1 === null) {
                $h1 = $text;
            } elseif ($heading->tagName === 'h2') {
                $h2[] = $text;
            } elseif ($heading->tagName === 'h3') {
                $h3[] = $text;
            }
        }

        return ['h1' => $h1, 'h2' => array_values(array_unique($h2)), 'h3' => array_values(array_unique($h3))];
    }

    private function resolveBestNode(DOMXPath $xpath, array $queries): ?DOMElement
    {
        $bestNode = null;
        $bestScore = -INF;

        foreach ($queries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $score = $this->nodeSelectionScore($xpath, $node);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestNode = $node;
                }
            }
        }

        return $bestNode;
    }

    private function resolveLargestTextContainer(DOMXPath $xpath): ?DOMElement
    {
        $bestNode = null;
        $bestScore = -INF;

        foreach ($xpath->query('//div|//section') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $score = $this->nodeSelectionScore($xpath, $node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $node;
            }
        }

        return $bestNode;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCandidate(DOMXPath $xpath, DOMElement $node, string $method, string $mode): array
    {
        $clone = $node->cloneNode(true);
        if (! $clone instanceof DOMElement) {
            return [
                'method' => $method,
                'plain_text' => '',
                'word_count' => 0,
                'headings' => ['h1' => null, 'h2' => [], 'h3' => []],
                'quality' => ['score' => 0],
                'score' => 0,
            ];
        }

        $tempDom = new DOMDocument('1.0', 'UTF-8');
        $tempDom->appendChild($tempDom->importNode($clone, true));
        $tempXpath = new DOMXPath($tempDom);
        $root = $tempDom->documentElement;
        if (! $root instanceof DOMElement) {
            return [
                'method' => $method,
                'plain_text' => '',
                'word_count' => 0,
                'headings' => ['h1' => null, 'h2' => [], 'h3' => []],
                'quality' => ['score' => 0],
                'score' => 0,
            ];
        }

        $this->removeNoise($tempXpath, $root);

        $plainText = $this->normalizeWhitespace($root->textContent ?? '');
        $headings = $this->extractHeadings($tempXpath, $root);
        $quality = $this->scoreExtractedContent($plainText, $headings, $root);

        $score = (float) ($quality['score'] ?? 0);
        if ($mode === 'alternative') {
            $score += match ($method) {
                'largest_text_container' => 5.0,
                'cleaned_body_fallback' => 4.0,
                'semantic_main' => 2.0,
                default => 0.0,
            };
        }

        return [
            'method' => $method,
            'plain_text' => $plainText,
            'word_count' => $quality['word_count'],
            'headings' => $headings,
            'quality' => $quality,
            'score' => $score,
        ];
    }

    private function nodeSelectionScore(DOMXPath $xpath, DOMElement $node): float
    {
        $text = $this->normalizeWhitespace($node->textContent ?? '');
        $length = mb_strlen($text);
        $paragraphCount = $xpath->query('.//p', $node)?->length ?? 0;
        $headingCount = $xpath->query('.//h2|.//h3', $node)?->length ?? 0;

        return $length + ($paragraphCount * 120) + ($headingCount * 180);
    }

    /**
     * @param array{h1:?string,h2:array<int,string>,h3:array<int,string>} $headings
     * @return array<string, int|float|bool>
     */
    private function scoreExtractedContent(string $plainText, array $headings, DOMElement $root): array
    {
        $wordCount = str_word_count($plainText);
        $paragraphs = $this->paragraphCount($root);
        $headingCount = count($headings['h2']) + count($headings['h3']) + ($headings['h1'] ? 1 : 0);
        $introPresent = $paragraphs >= 1 && $wordCount >= 80;
        $keywordDensityScore = $this->keywordDensityScore($plainText, $headings);
        $semanticVariationScore = $this->semanticVariationScore($plainText);
        $seoStructured = $this->isSeoArticleStructure($headings, $paragraphs, $plainText);
        $score = ($headingCount * 2) + $paragraphs + $keywordDensityScore + $semanticVariationScore + ($seoStructured ? 8 : 0);
        $paragraphDensity = $wordCount > 0 ? round($paragraphs / max(1, $wordCount / 100), 2) : 0.0;

        return [
            'score' => $score,
            'word_count' => $wordCount,
            'heading_count' => $headingCount,
            'paragraph_count' => $paragraphs,
            'keyword_density_score' => $keywordDensityScore,
            'semantic_variation_score' => $semanticVariationScore,
            'paragraph_density' => $paragraphDensity,
            'has_intro_paragraph' => $introPresent,
            'seo_article_structure' => $seoStructured,
        ];
    }

    /**
     * @param array{h1:?string,h2:array<int,string>,h3:array<int,string>} $headings
     */
    private function passesQualityGate(array $quality): bool
    {
        $score = (float) ($quality['score'] ?? 0);
        $wordCount = (int) ($quality['word_count'] ?? 0);
        $hasStructure = ((int) ($quality['heading_count'] ?? 0) >= 3) && ((int) ($quality['paragraph_count'] ?? 0) >= 3);
        $seoStructured = (bool) ($quality['seo_article_structure'] ?? false);

        return $wordCount >= self::MIN_WORD_COUNT
            && ($score > 14 || ($wordCount > 300 && $hasStructure) || ($wordCount > 260 && $seoStructured));
    }

    private function paragraphCount(DOMElement $root): int
    {
        $count = 0;
        foreach ($root->getElementsByTagName('p') as $paragraph) {
            if ($this->normalizeWhitespace($paragraph->textContent ?? '') !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array{h1:?string,h2:array<int,string>,h3:array<int,string>} $headings
     */
    private function keywordDensityScore(string $plainText, array $headings): int
    {
        $basis = collect([$headings['h1'] ?? null, ...$headings['h2'], ...$headings['h3']])
            ->filter()
            ->implode(' ');
        $tokens = collect(preg_split('/\s+/u', strtolower($basis)) ?: [])
            ->map(fn (string $token): string => trim(preg_replace('/[^a-z0-9\p{L}-]+/u', '', $token)))
            ->filter(fn (string $token): bool => mb_strlen($token) > 3)
            ->unique()
            ->take(6);

        if ($tokens->isEmpty()) {
            return 0;
        }

        $haystack = ' ' . strtolower($plainText) . ' ';
        $matches = $tokens->sum(fn (string $token): int => substr_count($haystack, ' ' . $token . ' '));

        return min(8, $matches);
    }

    private function semanticVariationScore(string $plainText): int
    {
        $tokens = collect(preg_split('/\s+/u', strtolower($plainText)) ?: [])
            ->map(fn (string $token): string => trim(preg_replace('/[^a-z0-9\p{L}-]+/u', '', $token)))
            ->filter(fn (string $token): bool => mb_strlen($token) > 3)
            ->values();

        if ($tokens->isEmpty()) {
            return 0;
        }

        $uniqueRatio = $tokens->unique()->count() / max(1, $tokens->count());

        return (int) min(8, round($uniqueRatio * 10));
    }

    /**
     * @param array{h1:?string,h2:array<int,string>,h3:array<int,string>} $headings
     */
    private function isSeoArticleStructure(array $headings, int $paragraphs, string $plainText): bool
    {
        $headingCount = count($headings['h2']) + count($headings['h3']);
        $sample = strtolower(Str::limit($plainText, 1600, ''));
        $explanatorySignals = [
            'what is', 'why', 'how', 'example', 'examples', 'means', 'benefit', 'benefits',
            'wat is', 'waarom', 'hoe', 'voorbeeld', 'voorbeelden', 'betekent',
        ];
        $signalHits = collect($explanatorySignals)->sum(fn (string $signal): int => str_contains($sample, $signal) ? 1 : 0);

        return $headingCount >= 2 && $paragraphs >= 3 && $signalHits >= 2;
    }

    private function resolvePublishDate(DOMXPath $xpath): ?string
    {
        $candidates = [
            "//meta[@property='article:published_time']/@content",
            "//meta[@name='article:published_time']/@content",
            "//time/@datetime",
        ];

        foreach ($candidates as $candidate) {
            $value = $this->metaContent($xpath, $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function resolveAuthor(DOMXPath $xpath): ?string
    {
        $candidates = [
            "//meta[@name='author']/@content",
            "//meta[@property='article:author']/@content",
            "//*[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]",
        ];

        foreach ($candidates as $candidate) {
            $value = $this->metaContent($xpath, $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function detectLanguage(DOMXPath $xpath, string $plainText): string
    {
        $explicit = $this->metaContent($xpath, '/html/@lang')
            ?: $this->metaContent($xpath, "//meta[@property='og:locale']/@content");

        $normalized = strtolower(substr((string) $explicit, 0, 2));
        if (in_array($normalized, ['nl', 'en'], true)) {
            return $normalized;
        }

        $sample = ' ' . strtolower(Str::limit($plainText, 1600, '')) . ' ';
        $nlSignals = [' de ', ' het ', ' een ', ' voor ', ' met ', ' wat ', ' hoe '];
        $enSignals = [' the ', ' and ', ' for ', ' with ', ' what ', ' how ', ' your '];

        $nlScore = collect($nlSignals)->sum(fn (string $signal): int => substr_count($sample, $signal));
        $enScore = collect($enSignals)->sum(fn (string $signal): int => substr_count($sample, $signal));

        return $nlScore > $enScore ? 'nl' : 'en';
    }

    private function buildSummary(string $plainText, ?string $metaDescription): string
    {
        $fallback = trim((string) $metaDescription);
        if ($fallback !== '') {
            return Str::limit($fallback, 280, '');
        }

        return Str::limit($plainText, 280, '');
    }

    private function metaContent(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);
        if (! $node instanceof DOMNode) {
            return null;
        }

        $value = $node instanceof \DOMAttr
            ? $node->value
            : ($node->textContent ?? '');

        $normalized = $this->normalizeWhitespace((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function truncateTextSafely(string $value, int $limit): string
    {
        $truncated = mb_substr($value, 0, $limit);
        $lastBoundary = max(
            (int) mb_strrpos($truncated, '. '),
            (int) mb_strrpos($truncated, "\n"),
            (int) mb_strrpos($truncated, ' ')
        );

        if ($lastBoundary > 0) {
            $truncated = mb_substr($truncated, 0, $lastBoundary + 1);
        }

        return $this->normalizeWhitespace($truncated);
    }

    private function estimateTokens(string $value): int
    {
        return (int) max(1, ceil(mb_strlen($value) / 4));
    }
}
