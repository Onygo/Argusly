<?php

namespace App\Services\PageIntelligence;

use App\Enums\SupportedLanguage;
use App\Models\MonitoredPage;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use App\Services\SourceBriefing\ArticleContentExtractor;
use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use App\Support\LanguageDetector;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PageContentExtractor
{
    private const EXTRACTOR_VERSION = 'page-content-extractor-v1';

    public function __construct(
        private readonly ArticleContentExtractor $articleExtractor,
        private readonly LanguageDetector $languageDetector,
        private readonly PageUrlNormalizer $normalizer,
    ) {}

    public function extract(PageSnapshot $snapshot): PageExtractionResult
    {
        $snapshot = $snapshot->fresh(['page']) ?? $snapshot;
        $page = $snapshot->page;

        if (! $page instanceof MonitoredPage) {
            throw new InvalidArgumentException('Page snapshot is not linked to a monitored page.');
        }

        $html = $this->rawHtml($snapshot);
        if (trim($html) === '') {
            throw new InvalidArgumentException('Page snapshot does not contain raw HTML to extract.');
        }

        [$dom, $xpath] = $this->parseHtml($html);
        $baseUrl = $snapshot->final_url ?: $snapshot->requested_url ?: $page->canonical_url;
        $article = $this->articleExtraction($html, $baseUrl);
        $mainText = $this->mainText($article, $xpath);
        $title = $this->firstString($article['title'] ?? null, $this->text($xpath, '//title[1]'), $this->meta($xpath, "//meta[@property='og:title']/@content"));
        $metaDescription = $this->firstString(
            $article['meta_description'] ?? null,
            $this->meta($xpath, "//meta[@name='description']/@content"),
            $this->meta($xpath, "//meta[@property='og:description']/@content")
        );
        $headings = $this->headings($xpath, $article);
        $h1 = $this->firstString($article['h1'] ?? null, data_get($headings, '0.text'));
        $publishedAt = $this->parseDate($this->firstString($article['publish_date'] ?? null, $this->publishDate($xpath)));
        $author = $this->firstString($article['author'] ?? null, $this->author($xpath));
        $publisher = $this->publisher($xpath);
        $language = $this->language($article, $xpath, $mainText);
        $summary = $this->summary((string) ($article['summary'] ?? ''), $metaDescription, $mainText);
        $canonicalUrl = $this->canonicalUrl($article, $xpath, $baseUrl);
        $canonical = $this->normalizeCanonical($canonicalUrl);
        $canonicalConflict = $canonical !== null && (string) $page->canonical_url_hash !== $canonical['hash'];
        $structuredData = $this->structuredData($xpath);
        $schemaTypes = $this->schemaTypes($structuredData);
        $openGraphImage = $this->openGraphImage($xpath, $baseUrl);
        $metaRobots = $this->metaRobots($xpath);
        $indexabilityStatus = $this->indexabilityStatus($metaRobots);
        $externalModifiedAt = $this->externalModifiedAt($xpath, $structuredData);
        $images = $this->images($xpath, $baseUrl);
        $media = $this->media($xpath, $baseUrl);
        [$outboundLinks, $internalLinks] = $this->links($xpath, $baseUrl);
        $wordCount = str_word_count($mainText);
        $charCount = mb_strlen($mainText);
        $estimatedTokens = $this->estimateTokens($mainText);
        $quality = is_array($article['quality'] ?? null) ? $article['quality'] : [];
        $contentDepthScore = $this->contentDepthScore($wordCount, count($headings), count($images), count($structuredData));
        $qualityScore = $this->qualityScore($quality, $wordCount, count($headings), $metaDescription, $structuredData);

        return DB::transaction(function () use (
            $snapshot,
            $page,
            $canonical,
            $canonicalUrl,
            $canonicalConflict,
            $schemaTypes,
            $openGraphImage,
            $metaRobots,
            $indexabilityStatus,
            $externalModifiedAt,
            $title,
            $metaDescription,
            $h1,
            $headings,
            $author,
            $publisher,
            $publishedAt,
            $language,
            $summary,
            $mainText,
            $wordCount,
            $charCount,
            $estimatedTokens,
            $contentDepthScore,
            $qualityScore,
            $structuredData,
            $images,
            $media,
            $outboundLinks,
            $internalLinks,
            $article,
            $baseUrl,
        ): PageExtractionResult {
            $existing = PageContentExtraction::query()
                ->where('page_snapshot_id', $snapshot->id)
                ->first();
            $previousExtraction = PageContentExtraction::query()
                ->where('monitored_page_id', $page->id)
                ->where('page_snapshot_id', '!=', $snapshot->id)
                ->latest('created_at')
                ->first();
            $storedText = $this->storeMainText($snapshot, $mainText);
            $changeKind = $this->changeKind($snapshot, $previousExtraction, $storedText['hash']);

            $extraction = PageContentExtraction::query()->updateOrCreate(
                ['page_snapshot_id' => $snapshot->id],
                [
                    'organization_id' => $snapshot->organization_id,
                    'workspace_id' => $snapshot->workspace_id,
                    'client_site_id' => $snapshot->client_site_id,
                    'monitored_page_id' => $snapshot->monitored_page_id,
                    'extraction_method' => (string) ($article['extraction_method'] ?? 'page_dom_fallback'),
                    'extractor_version' => self::EXTRACTOR_VERSION,
                    'title' => $title,
                    'meta_description' => $metaDescription,
                    'h1' => $h1,
                    'headings_json' => $headings,
                    'author' => $author,
                    'publisher' => $publisher,
                    'published_at' => $publishedAt,
                    'language' => $language,
                    'summary' => $summary,
                    'main_text' => $storedText['inline'],
                    'main_text_path' => $storedText['path'],
                    'main_text_hash' => $storedText['hash'],
                    'main_text_bytes' => $storedText['bytes'],
                    'main_text_preview' => $storedText['preview'],
                    'main_html' => null,
                    'main_html_path' => null,
                    'main_html_hash' => null,
                    'main_html_bytes' => null,
                    'word_count' => $wordCount,
                    'char_count' => $charCount,
                    'estimated_tokens' => $estimatedTokens,
                    'content_depth_score' => $contentDepthScore,
                    'quality_score' => $qualityScore,
                    'open_graph_image_url' => $openGraphImage,
                    'schema_types_json' => $schemaTypes,
                    'meta_robots' => $metaRobots,
                    'indexability_status' => $indexabilityStatus,
                    'canonical_url' => $canonicalUrl,
                    'content_fingerprint' => $storedText['hash'],
                    'external_modified_at' => $externalModifiedAt,
                    'structured_data_json' => $structuredData,
                    'images_json' => $this->imagesWithOpenGraph($images, $openGraphImage),
                    'media_json' => $media,
                    'outbound_links_json' => $outboundLinks,
                    'internal_links_json' => $internalLinks,
                    'metadata_json' => [
                        'source_snapshot_number' => $snapshot->snapshot_number,
                        'base_url' => $baseUrl,
                        'canonical_url' => $canonicalUrl,
                        'canonical_conflict' => $canonicalConflict,
                        'open_graph' => [
                            'image' => $openGraphImage,
                        ],
                        'schema_types' => $schemaTypes,
                        'meta_robots' => $metaRobots,
                        'indexability_status' => $indexabilityStatus,
                        'external_modified_at' => $externalModifiedAt?->toISOString(),
                        'article_quality' => $article['quality'] ?? null,
                    ],
                ],
            );

            $snapshotMetadata = array_replace_recursive((array) ($snapshot->metadata_json ?? []), [
                'inventory' => [
                    'change_kind' => $changeKind,
                    'content_fingerprint' => $storedText['hash'],
                    'previous_content_fingerprint' => $previousExtraction?->content_fingerprint ?: $previousExtraction?->main_text_hash,
                    'external_modified_at' => $externalModifiedAt?->toISOString(),
                ],
            ]);

            $snapshot->forceFill([
                'canonical_url' => $canonicalUrl ?: $snapshot->canonical_url,
                'text_hash' => $mainText !== '' ? hash('sha256', $mainText) : $snapshot->text_hash,
                'canonical_conflict' => $canonicalConflict,
                'metadata_json' => $snapshotMetadata,
            ])->save();

            $this->updatePage($page, $title, $language, $publishedAt, $canonical, $canonicalConflict, $indexabilityStatus, $changeKind);

            return new PageExtractionResult($page->refresh(), $snapshot->refresh(), $extraction->refresh(), $existing === null);
        });
    }

    private function rawHtml(PageSnapshot $snapshot): string
    {
        if (trim((string) $snapshot->raw_html) !== '') {
            return (string) $snapshot->raw_html;
        }

        $path = trim((string) $snapshot->raw_html_path);
        if ($path === '') {
            return '';
        }

        return (string) Storage::disk((string) config('page_intelligence.fetch.raw_html_disk', 'local'))->get($path);
    }

    /**
     * @return array{inline:?string,path:?string,hash:?string,bytes:?int,preview:?string}
     */
    private function storeMainText(PageSnapshot $snapshot, string $mainText): array
    {
        if ($mainText === '') {
            return ['inline' => null, 'path' => null, 'hash' => null, 'bytes' => 0, 'preview' => null];
        }

        $hash = hash('sha256', $mainText);
        $bytes = strlen($mainText);
        $preview = mb_substr($mainText, 0, max(0, (int) config('page_intelligence.storage.extracted_text_preview_bytes', 2000)));

        if ((string) config('page_intelligence.storage.extracted_text_storage', 'disk') !== 'disk') {
            return ['inline' => $mainText, 'path' => null, 'hash' => $hash, 'bytes' => $bytes, 'preview' => $preview];
        }

        $path = trim((string) config('page_intelligence.storage.extracted_text_path', 'page-extractions'), '/')
            .'/'.$snapshot->monitored_page_id.'/'.$snapshot->id.'.txt';

        Storage::disk((string) config('page_intelligence.storage.extracted_text_disk', 'local'))->put($path, $mainText);

        return ['inline' => null, 'path' => $path, 'hash' => $hash, 'bytes' => $bytes, 'preview' => $preview];
    }

    /**
     * @return array{0:DOMDocument,1:DOMXPath}
     */
    private function parseHtml(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($this->normalizeHtml($html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [$dom, new DOMXPath($dom)];
    }

    /**
     * @return array<string,mixed>
     */
    private function articleExtraction(string $html, string $baseUrl): array
    {
        try {
            return $this->articleExtractor->extract($html, $baseUrl, 'default', [
                'source' => 'page_intelligence',
            ]);
        } catch (SourceBriefingException) {
            return [
                'extraction_method' => 'page_dom_fallback',
                'quality' => [],
            ];
        }
    }

    private function mainText(array $article, DOMXPath $xpath): string
    {
        $mainText = $this->normalizeWhitespace((string) ($article['plain_text'] ?? ''));
        if ($mainText !== '') {
            return $mainText;
        }

        $node = $this->firstElement($xpath, [
            '//article',
            '//main',
            "//*[@role='main']",
            '//body',
        ]);

        if (! $node instanceof DOMElement) {
            return '';
        }

        $this->removeNoise($xpath, $node);

        return $this->normalizeWhitespace((string) $node->textContent);
    }

    /**
     * @return array<int,array{level:int,text:string}>
     */
    private function headings(DOMXPath $xpath, array $article): array
    {
        $rows = [];
        foreach ($xpath->query('//h1|//h2|//h3') ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $text = $this->normalizeWhitespace((string) $heading->textContent);
            if ($text === '') {
                continue;
            }

            $rows[] = [
                'level' => (int) substr(strtolower($heading->tagName), 1),
                'text' => $text,
            ];
        }

        if ($rows !== []) {
            return array_values(array_unique($rows, SORT_REGULAR));
        }

        $h1 = $this->normalizeWhitespace((string) ($article['h1'] ?? ''));
        if ($h1 !== '') {
            $rows[] = ['level' => 1, 'text' => $h1];
        }

        foreach ((array) data_get($article, 'outline.h2', []) as $text) {
            $text = $this->normalizeWhitespace((string) $text);
            if ($text !== '') {
                $rows[] = ['level' => 2, 'text' => $text];
            }
        }

        foreach ((array) data_get($article, 'outline.h3', []) as $text) {
            $text = $this->normalizeWhitespace((string) $text);
            if ($text !== '') {
                $rows[] = ['level' => 3, 'text' => $text];
            }
        }

        return array_values(array_unique($rows, SORT_REGULAR));
    }

    private function canonicalUrl(array $article, DOMXPath $xpath, string $baseUrl): ?string
    {
        $candidate = $this->firstString(
            $article['canonical_url'] ?? null,
            $this->meta($xpath, "//link[contains(concat(' ', normalize-space(@rel), ' '), ' canonical ')]/@href"),
        );

        return $candidate !== null ? $this->resolveUrl($baseUrl, $candidate) : null;
    }

    /**
     * @return array{url:string,hash:string,domain:string,path:string}|null
     */
    private function normalizeCanonical(?string $canonicalUrl): ?array
    {
        if (trim((string) $canonicalUrl) === '') {
            return null;
        }

        try {
            $normalized = $this->normalizer->normalize((string) $canonicalUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'url' => $normalized->canonicalUrl,
            'hash' => $normalized->canonicalUrlHash,
            'domain' => $normalized->domain,
            'path' => $normalized->path,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function structuredData(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query("//script[@type='application/ld+json']") ?: [] as $script) {
            $json = trim((string) $script->textContent);
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            foreach ($this->flattenStructuredData($decoded) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flattenStructuredData(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return collect($decoded)
                ->flatMap(fn (mixed $item): array => $this->flattenStructuredData($item))
                ->values()
                ->all();
        }

        $items = [$decoded];
        $graph = $decoded['@graph'] ?? null;
        if (is_array($graph)) {
            unset($items[0]['@graph']);

            foreach ($graph as $graphItem) {
                foreach ($this->flattenStructuredData($graphItem) as $item) {
                    $items[] = $item;
                }
            }
        }

        return array_values(array_filter($items, fn (array $item): bool => $item !== []));
    }

    /**
     * @param  array<int,array<string,mixed>>  $structuredData
     * @return array<int,string>
     */
    private function schemaTypes(array $structuredData): array
    {
        $types = [];

        foreach ($structuredData as $item) {
            foreach ((array) data_get($item, '@type', []) as $type) {
                $type = trim((string) $type);
                if ($type !== '') {
                    $types[] = $type;
                }
            }
        }

        return array_values(array_unique($types));
    }

    private function openGraphImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        foreach ([
            "//meta[@property='og:image']/@content",
            "//meta[@name='og:image']/@content",
            "//meta[@property='og:image:url']/@content",
            "//meta[@name='twitter:image']/@content",
            "//meta[@property='twitter:image']/@content",
        ] as $query) {
            $value = $this->meta($xpath, $query);
            if ($value !== null) {
                $resolved = $this->resolveUrl($baseUrl, $value);

                return $resolved !== '' ? $resolved : $value;
            }
        }

        return null;
    }

    private function metaRobots(DOMXPath $xpath): ?string
    {
        $values = [];
        foreach ([
            "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='robots']/@content",
            "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='googlebot']/@content",
        ] as $query) {
            $value = $this->meta($xpath, $query);
            if ($value !== null) {
                $values[] = strtolower($value);
            }
        }

        return $values === [] ? null : implode(', ', array_values(array_unique($values)));
    }

    private function indexabilityStatus(?string $metaRobots): ?string
    {
        $robots = strtolower(trim((string) $metaRobots));
        if ($robots === '') {
            return null;
        }

        return str_contains($robots, 'noindex') ? 'noindex' : 'indexable';
    }

    /**
     * @param  array<int,array<string,mixed>>  $structuredData
     */
    private function externalModifiedAt(DOMXPath $xpath, array $structuredData): ?Carbon
    {
        $candidate = $this->firstString(
            $this->meta($xpath, "//meta[@property='article:modified_time']/@content"),
            $this->meta($xpath, "//meta[@property='og:updated_time']/@content"),
            $this->meta($xpath, "//meta[@name='last-modified']/@content"),
            $this->structuredDataDate($structuredData, 'dateModified'),
            $this->structuredDataDate($structuredData, 'datePublished'),
        );

        return $this->parseDate($candidate);
    }

    /**
     * @param  array<int,array<string,mixed>>  $structuredData
     */
    private function structuredDataDate(array $structuredData, string $key): ?string
    {
        foreach ($structuredData as $item) {
            $value = data_get($item, $key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $images
     * @return array<int,array<string,mixed>>
     */
    private function imagesWithOpenGraph(array $images, ?string $openGraphImage): array
    {
        if ($openGraphImage === null) {
            return $images;
        }

        array_unshift($images, [
            'src' => $openGraphImage,
            'source' => 'open_graph',
            'property' => 'og:image',
            'alt' => null,
            'width' => null,
            'height' => null,
        ]);

        return array_values(array_unique($images, SORT_REGULAR));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function images(DOMXPath $xpath, string $baseUrl): array
    {
        $images = [];
        foreach ($xpath->query('//img[@src]') ?: [] as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $src = $this->resolveUrl($baseUrl, (string) $image->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            $images[] = [
                'src' => $src,
                'alt' => $this->normalizeWhitespace((string) $image->getAttribute('alt')) ?: null,
                'width' => $this->positiveInt($image->getAttribute('width')),
                'height' => $this->positiveInt($image->getAttribute('height')),
            ];
        }

        return array_values(array_unique($images, SORT_REGULAR));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function media(DOMXPath $xpath, string $baseUrl): array
    {
        $media = [];
        foreach ($xpath->query('//video[@src]|//audio[@src]|//source[@src]|//iframe[@src]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $src = $this->resolveUrl($baseUrl, (string) $node->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            $media[] = [
                'type' => strtolower($node->tagName),
                'src' => $src,
            ];
        }

        return array_values(array_unique($media, SORT_REGULAR));
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function links(DOMXPath $xpath, string $baseUrl): array
    {
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $outbound = [];
        $internal = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
                continue;
            }

            $resolved = $this->resolveUrl($baseUrl, $href);
            $host = strtolower((string) parse_url($resolved, PHP_URL_HOST));
            if ($resolved === '' || $host === '') {
                continue;
            }

            $row = [
                'href' => $resolved,
                'text' => Str::limit($this->normalizeWhitespace((string) $anchor->textContent), 180, ''),
                'rel' => $this->normalizeWhitespace((string) $anchor->getAttribute('rel')) ?: null,
            ];

            if ($host === $baseHost) {
                $internal[] = $row;
            } else {
                $outbound[] = $row;
            }
        }

        return [
            array_values(array_unique($outbound, SORT_REGULAR)),
            array_values(array_unique($internal, SORT_REGULAR)),
        ];
    }

    private function updatePage(
        MonitoredPage $page,
        ?string $title,
        ?string $language,
        ?Carbon $publishedAt,
        ?array $canonical,
        bool $canonicalConflict,
        ?string $indexabilityStatus,
        string $changeKind,
    ): void {
        $updates = [
            'title_current' => $title ?: $page->title_current,
            'language_current' => $language ?: $page->language_current,
            'published_at_current' => $publishedAt ?: $page->published_at_current,
        ];

        if ($indexabilityStatus !== null) {
            $updates['indexability_status'] = $indexabilityStatus;
        }

        if ($canonical !== null) {
            $duplicate = MonitoredPage::query()
                ->where('workspace_id', $page->workspace_id)
                ->where('canonical_url_hash', $canonical['hash'])
                ->whereKeyNot($page->id)
                ->exists();

            if (! $duplicate && (! $canonicalConflict || $canonical['domain'] === $page->domain)) {
                $updates['canonical_url'] = $canonical['url'];
                $updates['canonical_url_hash'] = $canonical['hash'];
                $updates['domain'] = $canonical['domain'];
                $updates['path'] = $canonical['path'];
            }
        }

        $metadata = array_replace_recursive((array) ($page->metadata_json ?? []), [
            'inventory' => [
                'last_extraction_change_kind' => $changeKind,
            ],
        ]);
        $updates['metadata_json'] = $metadata;

        $page->forceFill($updates)->save();
    }

    private function changeKind(PageSnapshot $snapshot, ?PageContentExtraction $previousExtraction, ?string $contentFingerprint): string
    {
        if (! $previousExtraction instanceof PageContentExtraction) {
            return 'first_successful_fetch';
        }

        $previousFingerprint = $previousExtraction->content_fingerprint ?: $previousExtraction->main_text_hash;
        if ($contentFingerprint !== null && $previousFingerprint !== null && $contentFingerprint !== $previousFingerprint) {
            return 'meaningful_content_change';
        }

        if ($snapshot->content_changed) {
            return 'metadata_only_change';
        }

        return 'unchanged';
    }

    private function language(array $article, DOMXPath $xpath, string $mainText): ?string
    {
        $explicit = $this->firstString(
            $article['detected_language'] ?? null,
            $this->meta($xpath, '/html/@lang'),
            $this->meta($xpath, "//meta[@property='og:locale']/@content"),
        );

        $normalized = SupportedLanguage::tryFromString($explicit)?->value;
        if ($normalized !== null) {
            return $normalized;
        }

        $detected = $this->languageDetector->detect($mainText);

        return $detected['language']?->value;
    }

    private function publishDate(DOMXPath $xpath): ?string
    {
        foreach ([
            "//meta[@property='article:published_time']/@content",
            "//meta[@name='article:published_time']/@content",
            "//meta[@property='og:published_time']/@content",
            '//time/@datetime',
            "//meta[@name='date']/@content",
        ] as $query) {
            $value = $this->meta($xpath, $query);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function author(DOMXPath $xpath): ?string
    {
        foreach ([
            "//meta[@name='author']/@content",
            "//meta[@property='article:author']/@content",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' author ')]",
            "//*[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]",
        ] as $query) {
            $value = $this->meta($xpath, $query);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function publisher(DOMXPath $xpath): ?string
    {
        return $this->firstString(
            $this->meta($xpath, "//meta[@property='og:site_name']/@content"),
            $this->meta($xpath, "//meta[@name='publisher']/@content"),
            $this->meta($xpath, "//meta[@property='article:publisher']/@content"),
        );
    }

    private function summary(string $articleSummary, ?string $metaDescription, string $mainText): string
    {
        return Str::limit($this->firstString($articleSummary, $metaDescription, $mainText) ?? '', 280, '');
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function contentDepthScore(int $wordCount, int $headingCount, int $imageCount, int $structuredDataCount): float
    {
        $score = min(55, $wordCount / 20)
            + min(20, $headingCount * 4)
            + min(10, $imageCount * 2)
            + min(15, $structuredDataCount * 5);

        return round(max(0, min(100, $score)), 2);
    }

    private function qualityScore(array $quality, int $wordCount, int $headingCount, ?string $metaDescription, array $structuredData): float
    {
        $base = min(45, ((float) ($quality['score'] ?? 0)) * 2.5);
        $score = $base
            + min(25, $wordCount / 18)
            + min(15, $headingCount * 3)
            + ($metaDescription ? 8 : 0)
            + ($structuredData !== [] ? 7 : 0);

        return round(max(0, min(100, $score)), 2);
    }

    private function estimateTokens(string $value): int
    {
        return (int) max(0, ceil(mb_strlen($value) / 4));
    }

    private function positiveInt(string $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeWhitespace((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function meta(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);
        if (! $node instanceof DOMNode) {
            return null;
        }

        $value = $node instanceof DOMAttr ? $node->value : (string) $node->textContent;
        $normalized = $this->normalizeWhitespace($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        return $this->meta($xpath, $query);
    }

    /**
     * @param  array<int,string>  $queries
     */
    private function firstElement(DOMXPath $xpath, array $queries): ?DOMElement
    {
        foreach ($queries as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private function resolveUrl(string $baseUrl, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($base['scheme'] ?? 'https'));
        $host = strtolower((string) $base['host']);
        $port = isset($base['port']) ? ':'.(int) $base['port'] : '';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$port.$url;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_ends_with($basePath, '/') ? $basePath : dirname($basePath), '/');

        return $scheme.'://'.$host.$port.($directory !== '' ? $directory : '').'/'.$url;
    }

    private function removeNoise(DOMXPath $xpath, DOMElement $node): void
    {
        foreach (['.//script', './/style', './/noscript', './/svg', './/nav', './/footer', './/aside', './/form'] as $query) {
            foreach ($xpath->query($query, $node) ?: [] as $child) {
                if ($child instanceof DOMNode && $child->parentNode) {
                    $child->parentNode->removeChild($child);
                }
            }
        }
    }

    private function normalizeHtml(string $html): string
    {
        if (preg_match('/<meta[^>]+charset=/i', $html)) {
            return $html;
        }

        return '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
