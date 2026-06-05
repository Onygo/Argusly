<?php

namespace App\Services\SeoAudit;

use App\Models\ClientSite;
use App\Models\Content;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SeoAuditCrawlerService
{
    private const DEFAULT_TIMEOUT_SECONDS = 10;
    private const BROKEN_LINK_TIMEOUT_SECONDS = 4;
    private const BROKEN_LINK_CHECK_LIMIT = 6;
    private const MAX_SITEMAP_BYTES = 2_000_000;
    private const REDIRECT_LIMIT = 5;
    private const USER_AGENT = 'PublishLayerSeoAuditCrawler/1.0 (+https://argusly.local)';
    private const FETCH_DIAGNOSTIC_SAMPLE_LIMIT = 100;
    private const PAGE_TYPE_PUBLISHLAYER_ARTICLE = 'publishlayer_article';
    private const PAGE_TYPE_SITE_PAGE = 'site_page';
    private const PAGE_TYPE_SYSTEM_PAGE = 'system_page';

    /** @var array<int,array<string,mixed>> */
    private array $fetchDiagnostics = [];

    private string $crawlBaseHost = '';

    /** @var array<int,string> */
    private array $allowedRedirectDomains = [];

    /** @var array<string,string> */
    private array $publishLayerContentByUrlKey = [];

    /**
     * @return array{pages:array<int,array<string,mixed>>,issues:array<int,array<string,mixed>>,crawl_source:string,diagnostics:array<string,mixed>}
     */
    public function crawl(ClientSite $site, int $maxPages): array
    {
        $maxPages = max(1, $maxPages);
        $baseUrl = $this->normalizeUrl((string) ($site->base_url ?: $site->site_url));
        $baseHost = (string) parse_url($baseUrl, PHP_URL_HOST);
        $this->crawlBaseHost = strtolower($baseHost);
        $this->allowedRedirectDomains = $this->buildAllowedRedirectDomains($site, $baseHost);
        $this->fetchDiagnostics = [];
        $this->publishLayerContentByUrlKey = $this->buildPublishLayerContentLookup($site);

        $seedUrls = $this->discoverFromSitemap($baseUrl, $baseHost, $maxPages);
        $crawlSource = $seedUrls !== [] ? 'sitemap' : 'bfs';

        if ($seedUrls === []) {
            $seedUrls = $this->discoverByBfs($baseUrl, $baseHost, $maxPages);
        }

        $pages = [];
        $issues = [];

        foreach (array_slice($seedUrls, 0, $maxPages) as $url) {
            $page = $this->auditSinglePage($url, $baseHost);
            $pages[] = $page;

            foreach ($this->issuesForPage($page) as $issue) {
                $issues[] = $issue;
            }
        }

        return [
            'pages' => $pages,
            'issues' => $issues,
            'crawl_source' => $crawlSource,
            'diagnostics' => $this->buildDiagnosticsSummary(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function discoverFromSitemap(string $baseUrl, string $baseHost, int $maxPages): array
    {
        $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
        $response = $this->requestWithLocalFallback($sitemapUrl, self::DEFAULT_TIMEOUT_SECONDS, 'application/xml,text/xml,*/*');

        if (! $response || ! $response->successful()) {
            return [];
        }

        $xml = (string) $response->body();
        if (trim($xml) === '') {
            return [];
        }
        if (strlen($xml) > self::MAX_SITEMAP_BYTES) {
            return [];
        }

        $dom = new \DOMDocument();
        if (! @ $dom->loadXML($xml)) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $locNodes = $xpath->query('//*[local-name()="url"]/*[local-name()="loc"]');

        if (! $locNodes) {
            return [];
        }

        $urls = [];
        foreach ($locNodes as $node) {
            $raw = trim((string) $node->textContent);
            $url = $this->normalizeUrl($raw);
            if ($url === '') {
                continue;
            }

            if ((string) parse_url($url, PHP_URL_HOST) !== $baseHost) {
                continue;
            }

            $urls[] = $url;
            if (count($urls) >= $maxPages) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, string>
     */
    private function discoverByBfs(string $baseUrl, string $baseHost, int $maxPages): array
    {
        $visited = [];
        $queue = [$baseUrl];
        $results = [];

        while ($queue !== [] && count($results) < $maxPages) {
            $current = array_shift($queue);
            if (! is_string($current) || $current === '' || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            $results[] = $current;

            $page = $this->fetchPage($current);
            if (! $page['is_html']) {
                continue;
            }

            foreach ($this->extractInternalLinks($page['body'], $current, $baseHost) as $link) {
                if (! isset($visited[$link])) {
                    $queue[] = $link;
                }

                if (count($queue) >= ($maxPages * 5)) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    private function auditSinglePage(string $url, string $baseHost): array
    {
        $page = $this->fetchPage($url);

        if (! $page['is_html']) {
            $classification = $this->classifyPage(
                url: $url,
                fetchedUrl: (string) ($page['fetched_url'] ?? ''),
                canonicalUrl: null,
                html: '',
            );

            return [
                'url' => $url,
                'status_code' => $page['status_code'],
                'title' => null,
                'meta_description' => null,
                'canonical_url' => null,
                'robots_meta' => null,
                'h1' => null,
                'word_count' => 0,
                'internal_links_count' => 0,
                'broken_links_count' => 0,
                'is_html' => false,
                'fetch_error' => $page['fetch_error'],
                'fetch_error_category' => $page['fetch_error_category'],
                'fetched_url' => $page['fetched_url'],
                'fetch_meta' => $page['fetch_meta'],
                'page_type' => $classification['page_type'],
                'publishlayer_article_id' => $classification['publishlayer_article_id'],
            ];
        }

        $html = $page['body'];

        $title = $this->extractFirst($html, '//title');
        $metaDescription = $this->extractMetaContent($html, 'description');
        $canonical = $this->extractCanonical($html, $url);
        $robotsMeta = $this->extractMetaContent($html, 'robots');
        $h1 = $this->extractFirst($html, '//h1');
        $wordCount = $this->countWords($html);

        $internalLinks = $this->extractInternalLinks($html, $url, $baseHost);
        $brokenLinksCount = $this->countBrokenLinks($internalLinks);
        $classification = $this->classifyPage(
            url: $url,
            fetchedUrl: (string) ($page['fetched_url'] ?? ''),
            canonicalUrl: $canonical,
            html: $html,
        );

        return [
            'url' => $url,
            'status_code' => $page['status_code'],
            'title' => $title,
            'meta_description' => $metaDescription,
            'canonical_url' => $canonical,
            'robots_meta' => $robotsMeta,
            'h1' => $h1,
            'word_count' => $wordCount,
            'internal_links_count' => count($internalLinks),
            'broken_links_count' => $brokenLinksCount,
            'is_html' => true,
            'fetch_error' => $page['fetch_error'],
            'fetch_error_category' => $page['fetch_error_category'],
            'fetched_url' => $page['fetched_url'],
            'fetch_meta' => $page['fetch_meta'],
            'page_type' => $classification['page_type'],
            'publishlayer_article_id' => $classification['publishlayer_article_id'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPage(string $url): array
    {
        [$response, $fetchError, $effectiveUrl, $fetchMeta] = $this->requestWithLocalFallbackWithMeta(
            $url,
            self::DEFAULT_TIMEOUT_SECONDS,
            'text/html,application/xhtml+xml'
        );

        if (! $response) {
            $this->recordFetchDiagnostic(array_merge($fetchMeta, [
                'target_url' => $url,
                'effective_url' => $effectiveUrl,
                'error_message' => $fetchError,
            ]));

            return [
                'status_code' => 0,
                'body' => '',
                'is_html' => false,
                'fetch_error' => $fetchError,
                'fetch_error_category' => (string) ($fetchMeta['error_category'] ?? 'http_error'),
                'fetched_url' => $effectiveUrl,
                'fetch_meta' => $fetchMeta,
            ];
        }

        $statusCode = (int) $response->status();
        $body = (string) $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));
        $isHtmlContentType = $this->isHtmlContentType($contentType);
        $isSuccessfulStatus = $statusCode >= 200 && $statusCode < 300;
        $finalUrl = (string) ($fetchMeta['effective_url'] ?? $effectiveUrl ?: $url);
        $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
        $responseLength = strlen($body);

        if ($finalHost !== '' && ! $this->isAllowedRedirectHost($finalHost)) {
            $fetchError = 'Redirect landed on a non-allowed host.';
            $fetchMeta['error_category'] = 'redirect_blocked_cross_domain';
            $fetchMeta['error_message'] = $fetchError;
            $fetchMeta['is_allowed_redirect_host'] = false;
            $fetchMeta['response_length'] = $responseLength;
            $this->recordFetchDiagnostic(array_merge($fetchMeta, [
                'target_url' => $url,
                'status_code' => $statusCode,
                'content_type' => $contentType,
                'final_url' => $finalUrl,
                'response_length' => $responseLength,
            ]));

            return [
                'status_code' => $statusCode,
                'body' => '',
                'is_html' => false,
                'fetch_error' => $fetchError,
                'fetch_error_category' => 'redirect_blocked_cross_domain',
                'fetched_url' => $finalUrl,
                'fetch_meta' => $fetchMeta,
            ];
        }

        $fetchErrorCategory = (string) ($fetchMeta['error_category'] ?? '');
        if ($this->isAuthenticationRedirect($url, $finalUrl, $statusCode)) {
            $fetchErrorCategory = 'login_redirect';
            $fetchError = 'Request redirected to an authentication page.';
        } elseif (! $isSuccessfulStatus) {
            $fetchErrorCategory = $this->categorizeStatusCode($statusCode);
            $fetchError = $fetchError ?: sprintf('Unexpected HTTP status %d.', $statusCode);
        } elseif (! $isHtmlContentType) {
            $fetchErrorCategory = 'non_html';
            $fetchError = 'Content-Type is not HTML.';
        } else {
            $fetchError = null;
        }

        $fetchMeta['error_category'] = $fetchErrorCategory !== '' ? $fetchErrorCategory : null;
        $fetchMeta['error_message'] = $fetchError;
        $fetchMeta['final_url'] = $finalUrl;
        $fetchMeta['is_allowed_redirect_host'] = true;
        $fetchMeta['response_length'] = $responseLength;

        $this->recordFetchDiagnostic(array_merge($fetchMeta, [
            'target_url' => $url,
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'final_url' => $finalUrl,
            'response_length' => $responseLength,
        ]));

        return [
            'status_code' => $statusCode,
            'body' => $body,
            'is_html' => $isSuccessfulStatus && $isHtmlContentType,
            'fetch_error' => $fetchError,
            'fetch_error_category' => $fetchErrorCategory !== '' ? $fetchErrorCategory : null,
            'fetched_url' => $finalUrl,
            'fetch_meta' => $fetchMeta,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractInternalLinks(string $html, string $baseUrl, string $baseHost): array
    {
        $dom = new \DOMDocument();
        if (! @ $dom->loadHTML($html)) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');
        if (! $nodes) {
            return [];
        }

        $urls = [];

        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $resolved = $this->resolveUrl($baseUrl, $href);
            if ($resolved === '') {
                continue;
            }

            if ((string) parse_url($resolved, PHP_URL_HOST) !== $baseHost) {
                continue;
            }

            $urls[] = $resolved;
        }

        return array_values(array_unique($urls));
    }

    private function countBrokenLinks(array $links): int
    {
        $broken = 0;

        foreach (array_slice($links, 0, self::BROKEN_LINK_CHECK_LIMIT) as $link) {
            $response = $this->requestWithLocalFallback($link, self::BROKEN_LINK_TIMEOUT_SECONDS, '*/*', 'HEAD');
            $status = $response ? (int) $response->status() : 0;

            // Some origins do not support HEAD consistently.
            if ($status === 405 || $status === 501) {
                $response = $this->requestWithLocalFallback($link, self::BROKEN_LINK_TIMEOUT_SECONDS, '*/*', 'GET');
                $status = $response ? (int) $response->status() : 0;
            }

            if (! $response || $status >= 400) {
                $broken++;
            }
        }

        return $broken;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function issuesForPage(array $page): array
    {
        if ((string) ($page['page_type'] ?? '') === self::PAGE_TYPE_SYSTEM_PAGE) {
            return [];
        }

        $issues = [];

        $statusCode = (int) ($page['status_code'] ?? 0);
        $fetchError = trim((string) ($page['fetch_error'] ?? ''));
        $fetchErrorCategory = trim((string) ($page['fetch_error_category'] ?? ''));
        $isHtml = (bool) ($page['is_html'] ?? false);
        $isSuccessful = $statusCode >= 200 && $statusCode < 300;

        if (! $isSuccessful || ! $isHtml || $fetchError !== '' || $fetchErrorCategory !== '') {
            $issues[] = $this->issue('error', 'http_error', 'Page returns an error status', 'Page could not be fetched as parseable HTML.', 'Fix server/client errors and redirects.', $page);

            return $issues;
        }

        $title = trim((string) ($page['title'] ?? ''));
        if ($title === '') {
            $issues[] = $this->issue('warning', 'title_missing', 'Missing title tag', 'No <title> found.', 'Add a unique descriptive title.', $page);
        } elseif (mb_strlen($title) > 60) {
            $issues[] = $this->issue('info', 'title_long', 'Title too long', 'Title exceeds recommended length.', 'Keep title around 50-60 characters.', $page);
        }

        $description = trim((string) ($page['meta_description'] ?? ''));
        if ($description === '') {
            $issues[] = $this->issue('warning', 'meta_description_missing', 'Missing meta description', 'No meta description detected.', 'Add a concise meta description (120-160 chars).', $page);
        }

        if (trim((string) ($page['canonical_url'] ?? '')) === '') {
            $issues[] = $this->issue('info', 'canonical_missing', 'Missing canonical tag', 'No canonical URL found.', 'Add rel="canonical" to avoid duplication issues.', $page);
        }

        if (trim((string) ($page['h1'] ?? '')) === '') {
            $issues[] = $this->issue('warning', 'h1_missing', 'Missing H1', 'No H1 heading detected.', 'Add one clear H1 heading.', $page);
        }

        if ((int) ($page['word_count'] ?? 0) < 250) {
            $issues[] = $this->issue('info', 'thin_content', 'Low word count', 'Page may be too thin for SEO depth.', 'Expand content with useful details.', $page);
        }

        if (str_contains(strtolower((string) ($page['robots_meta'] ?? '')), 'noindex')) {
            $issues[] = $this->issue('warning', 'noindex_detected', 'Noindex meta detected', 'Page contains noindex in robots meta.', 'Remove noindex if page should rank.', $page);
        }

        if ((int) ($page['broken_links_count'] ?? 0) > 0) {
            $issues[] = $this->issue('warning', 'broken_links_detected', 'Broken links detected', 'Internal link checks found broken URLs.', 'Update or remove broken links.', $page);
        }

        return $issues;
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $severity, string $code, string $title, string $description, string $recommendation, array $page): array
    {
        return [
            'page_url' => (string) ($page['url'] ?? ''),
            'severity' => $severity,
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'context_json' => [
                'status_code' => (int) ($page['status_code'] ?? 0),
                'url' => (string) ($page['url'] ?? ''),
                'fetched_url' => (string) ($page['fetched_url'] ?? ''),
                'fetch_error' => (string) ($page['fetch_error'] ?? ''),
                'fetch_error_category' => (string) ($page['fetch_error_category'] ?? ''),
                'fetch_meta' => is_array($page['fetch_meta'] ?? null) ? $page['fetch_meta'] : null,
                'page_type' => (string) ($page['page_type'] ?? self::PAGE_TYPE_SITE_PAGE),
                'publishlayer_article_id' => (string) ($page['publishlayer_article_id'] ?? ''),
            ],
        ];
    }

    /**
     * @return array{page_type:string,publishlayer_article_id:?string}
     */
    private function classifyPage(string $url, string $fetchedUrl, ?string $canonicalUrl, string $html): array
    {
        $canonicalUrl = trim((string) $canonicalUrl);
        if ($this->isSystemPageUrl($url) || $this->isSystemPageUrl($fetchedUrl) || $this->isSystemPageUrl($canonicalUrl)) {
            return [
                'page_type' => self::PAGE_TYPE_SYSTEM_PAGE,
                'publishlayer_article_id' => null,
            ];
        }

        $publishLayerArticleId = $this->resolvePublishLayerArticleId($canonicalUrl, $url, $fetchedUrl);
        if ($publishLayerArticleId !== null) {
            return [
                'page_type' => self::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
                'publishlayer_article_id' => $publishLayerArticleId,
            ];
        }

        if ($this->containsPublishLayerTrackingScript($html)) {
            return [
                'page_type' => self::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
                'publishlayer_article_id' => null,
            ];
        }

        return [
            'page_type' => self::PAGE_TYPE_SITE_PAGE,
            'publishlayer_article_id' => null,
        ];
    }

    private function containsPublishLayerTrackingScript(string $html): bool
    {
        $haystack = strtolower($html);
        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'track.publishlayer');
    }

    private function isSystemPageUrl(string $url): bool
    {
        $value = trim($url);
        if ($value === '') {
            return false;
        }

        $path = strtolower((string) parse_url($value, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        foreach ([
            '/wp-admin',
            '/wp-login',
            '/wp-json',
            '/xmlrpc',
            '/feed',
            '/tag/',
            '/category/',
            '/author/',
        ] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePublishLayerArticleId(?string $canonicalUrl, string $url, string $fetchedUrl): ?string
    {
        $keys = [];

        $canonicalKey = $this->urlKeyForAudit((string) $canonicalUrl);
        if ($canonicalKey !== '') {
            $keys[] = $canonicalKey;
        }

        $urlKey = $this->urlKeyForAudit($url);
        if ($urlKey !== '') {
            $keys[] = $urlKey;
        }

        $fetchedKey = $this->urlKeyForAudit($fetchedUrl);
        if ($fetchedKey !== '') {
            $keys[] = $fetchedKey;
        }

        foreach (array_values(array_unique($keys)) as $key) {
            $contentId = $this->publishLayerContentByUrlKey[$key] ?? null;
            if (is_string($contentId) && $contentId !== '') {
                return $contentId;
            }
        }

        return null;
    }

    private function urlKeyForAudit(string $url): string
    {
        $value = trim($url);
        if ($value === '') {
            return '';
        }

        $key = AnalyticsUrlKey::fromUrl($value);
        if ($key !== '') {
            return $key;
        }

        if ($this->crawlBaseHost !== '') {
            return AnalyticsUrlKey::fromUrlUsingHost($value, $this->crawlBaseHost);
        }

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function buildPublishLayerContentLookup(ClientSite $site): array
    {
        $siteId = (string) $site->id;
        if ($siteId === '') {
            return [];
        }

        $lookup = [];

        Content::query()
            ->where('client_site_id', $siteId)
            ->select(['id', 'publish_url_key', 'canonical_url_key', 'published_url'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$lookup): void {
                foreach ($rows as $row) {
                    $contentId = trim((string) $row->id);
                    if ($contentId === '') {
                        continue;
                    }

                    $keys = [];
                    foreach (['canonical_url_key', 'publish_url_key'] as $column) {
                        $key = trim((string) ($row->{$column} ?? ''));
                        if ($key === '') {
                            continue;
                        }

                        $keys[] = $key;
                    }

                    $publishedUrl = trim((string) ($row->published_url ?? ''));
                    if ($publishedUrl !== '') {
                        $publishedUrlKey = AnalyticsUrlKey::fromUrl($publishedUrl);
                        if ($publishedUrlKey !== '') {
                            $keys[] = $publishedUrlKey;
                        }

                        if ($this->crawlBaseHost !== '') {
                            $publishedUrlForHost = str_starts_with($publishedUrl, '/')
                                ? 'https://' . $this->crawlBaseHost . $publishedUrl
                                : $publishedUrl;
                            $publishedUrlKeyUsingHost = AnalyticsUrlKey::fromUrlUsingHost($publishedUrlForHost, $this->crawlBaseHost);
                            if ($publishedUrlKeyUsingHost !== '') {
                                $keys[] = $publishedUrlKeyUsingHost;
                            }
                        }
                    }

                    foreach (array_values(array_unique($keys)) as $key) {
                        if (! isset($lookup[$key])) {
                            $lookup[$key] = $contentId;
                        }
                    }
                }
            });

        return $lookup;
    }

    private function requestWithLocalFallback(string $url, int $timeout, string $accept, string $method = 'GET'): ?Response
    {
        [$response] = $this->requestWithLocalFallbackWithMeta($url, $timeout, $accept, $method);

        return $response;
    }

    /**
     * @return array{0:?Response,1:?string,2:string,3:array<string,mixed>}
     */
    private function requestWithLocalFallbackWithMeta(string $url, int $timeout, string $accept, string $method = 'GET'): array
    {
        $method = strtoupper(trim($method)) === 'HEAD' ? 'HEAD' : 'GET';
        $initial = $this->sendRequest($url, $timeout, $accept, $method);

        if ($initial['response'] instanceof Response) {
            return [
                $initial['response'],
                null,
                (string) ($initial['effective_url'] ?? $url),
                $initial,
            ];
        }

        if (! $this->shouldTryHttpFallback($url)) {
            return [
                null,
                (string) ($initial['error_message'] ?? 'HTTP request failed.'),
                (string) ($initial['effective_url'] ?? $url),
                $initial,
            ];
        }

        $fallbackUrl = $this->toHttpUrl($url);
        if ($fallbackUrl === '') {
            return [
                null,
                'HTTPS request failed and HTTP fallback URL could not be built.',
                $url,
                array_merge($initial, [
                    'http_fallback_used' => false,
                ]),
            ];
        }

        $fallback = $this->sendRequest($fallbackUrl, $timeout, $accept, $method);
        $fallback['http_fallback_used'] = true;
        $fallback['http_fallback_from'] = $url;
        $fallback['initial_error_category'] = $initial['error_category'] ?? null;
        $fallback['initial_error_message'] = $initial['error_message'] ?? null;

        if ($fallback['response'] instanceof Response) {
            return [
                $fallback['response'],
                null,
                (string) ($fallback['effective_url'] ?? $fallbackUrl),
                $fallback,
            ];
        }

        return [
            null,
            (string) ($fallback['error_message'] ?? 'HTTP request failed.'),
            (string) ($fallback['effective_url'] ?? $fallbackUrl),
            $fallback,
        ];
    }

    private function shouldTryHttpFallback(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    /**
     * @return array<string,mixed>
     */
    private function sendRequest(string $url, int $timeout, string $accept, string $method = 'GET'): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $resolvedHost = $host !== '' ? gethostbyname($host) : '';
        $disableTlsVerify = $this->shouldDisableTlsVerifyFor($url);

        $request = Http::timeout($timeout)
            ->accept($accept)
            ->withUserAgent(self::USER_AGENT)
            ->withOptions([
                'allow_redirects' => [
                    'max' => self::REDIRECT_LIMIT,
                    'track_redirects' => true,
                ],
            ]);

        if ($disableTlsVerify) {
            $request = $request->withoutVerifying();

            Log::debug('SEO audit fetch disabled TLS verification for local domain', [
                'url' => $url,
                'host' => $host,
                'env' => app()->environment(),
            ]);
        }

        try {
            $response = $method === 'HEAD'
                ? $request->head($url)
                : $request->get($url);
            $redirectHistory = $this->extractRedirectHistory($response);
            $effectiveUrl = $redirectHistory !== []
                ? (string) end($redirectHistory)
                : (string) ($response->effectiveUri() ?? $url);
            $redirectCount = count($redirectHistory);
            $contentType = strtolower((string) $response->header('Content-Type'));

            Log::debug('SEO audit fetch completed', [
                'url' => $url,
                'host' => $host,
                'resolved_host' => $resolvedHost,
                'status_code' => $response->status(),
                'method' => $method,
                'redirect_count' => $redirectCount,
                'content_type' => $contentType,
                'effective_url' => $effectiveUrl,
            ]);

            return [
                'response' => $response,
                'requested_url' => $url,
                'effective_url' => $effectiveUrl,
                'status_code' => (int) $response->status(),
                'content_type' => $contentType,
                'redirect_count' => $redirectCount,
                'redirect_history' => $redirectHistory,
                'error_category' => null,
                'error_message' => null,
                'exception_class' => null,
                'exception_message' => null,
                'host' => $host,
                'resolved_host' => $resolvedHost,
                'tls_verify_disabled' => $disableTlsVerify,
                'method' => $method,
            ];
        } catch (\Throwable $exception) {
            [$responseStatus, $responseHeaders] = $this->extractExceptionResponseDetails($exception);
            $category = $this->categorizeException($exception);

            Log::warning('SEO audit fetch failed', [
                'url' => $url,
                'host' => $host,
                'resolved_host' => $resolvedHost,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'error_category' => $category,
                'response_status' => $responseStatus,
            ]);

            return [
                'response' => null,
                'requested_url' => $url,
                'effective_url' => $url,
                'status_code' => $responseStatus,
                'content_type' => '',
                'redirect_count' => 0,
                'redirect_history' => [],
                'error_category' => $category,
                'error_message' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'response_headers' => $responseHeaders,
                'host' => $host,
                'resolved_host' => $resolvedHost,
                'tls_verify_disabled' => $disableTlsVerify,
                'method' => $method,
            ];
        }
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function extractExceptionResponseDetails(\Throwable $exception): array
    {
        if (property_exists($exception, 'response') && $exception->response instanceof Response) {
            return [
                (int) $exception->response->status(),
                $exception->response->headers(),
            ];
        }

        return [0, []];
    }

    /**
     * @return array<int,string>
     */
    private function extractRedirectHistory(Response $response): array
    {
        $historyHeader = (string) $response->header('X-Guzzle-Redirect-History');
        if ($historyHeader !== '') {
            $parts = array_map('trim', explode(',', $historyHeader));

            return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        $stats = $response->handlerStats();
        if (isset($stats['redirect_count']) && (int) $stats['redirect_count'] > 0) {
            $effective = (string) ($response->effectiveUri() ?? '');

            return $effective !== '' ? [$effective] : [];
        }

        return [];
    }

    private function shouldDisableTlsVerifyFor(string $url): bool
    {
        if ($this->isProductionEnvironment()) {
            if (config('publishlayer.http_insecure_local') === true) {
                throw new RuntimeException('PUBLISHLAYER_HTTP_INSECURE_LOCAL must never be enabled in production.');
            }

            return false;
        }

        if (! $this->isLocalDevelopmentEnvironment()) {
            return false;
        }

        if (config('publishlayer.http_insecure_local') !== true) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $this->isLocalHost($host);
    }

    private function isLocalDevelopmentEnvironment(): bool
    {
        if (app()->environment(['local', 'development'])) {
            return true;
        }

        return in_array(strtolower((string) config('app.env', '')), ['local', 'development', 'dev'], true);
    }

    private function isProductionEnvironment(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return strtolower((string) config('app.env', '')) === 'production';
    }

    private function isLocalHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', 'argusly.local', 'wordpress.argusly.local'], true)) {
            return true;
        }

        return str_ends_with($host, '.local');
    }

    private function isHtmlContentType(string $contentType): bool
    {
        $value = strtolower(trim($contentType));

        return str_contains($value, 'text/html') || str_contains($value, 'application/xhtml+xml');
    }

    private function isAuthenticationRedirect(string $requestedUrl, string $finalUrl, int $statusCode): bool
    {
        if ($statusCode < 200 || $statusCode >= 400) {
            return false;
        }

        $requestedPath = strtolower(trim((string) parse_url($requestedUrl, PHP_URL_PATH)));
        $finalPath = strtolower(trim((string) parse_url($finalUrl, PHP_URL_PATH)));

        if ($finalPath === '' || $requestedPath === $finalPath) {
            return false;
        }

        foreach (['/login', '/verify-code'] as $authPath) {
            if ($finalPath === $authPath || str_starts_with($finalPath, $authPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function categorizeStatusCode(int $statusCode): string
    {
        if (in_array($statusCode, [401, 403], true)) {
            return 'auth_error';
        }

        if ($statusCode >= 500) {
            return 'server_error';
        }

        if (in_array($statusCode, [301, 302, 307, 308], true)) {
            return 'redirect_loop';
        }

        return 'http_error';
    }

    private function categorizeException(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'curl error 60') || str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'ssl_error_60';
        }

        if (str_contains($message, 'curl error 7')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')) {
            return 'connection_refused_or_timeout';
        }

        if (str_contains($message, 'too many redirects') || str_contains($message, 'curl error 47')) {
            return 'redirect_loop';
        }

        return 'http_error';
    }

    private function isAllowedRedirectHost(string $host): bool
    {
        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '') {
            return false;
        }

        if ($this->isSameSiteDomain($this->crawlBaseHost, $normalizedHost)) {
            return true;
        }

        foreach ($this->allowedRedirectDomains as $allowedDomain) {
            if ($normalizedHost === $allowedDomain || str_ends_with($normalizedHost, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function isSameSiteDomain(string $leftHost, string $rightHost): bool
    {
        if ($leftHost === '' || $rightHost === '') {
            return false;
        }

        if ($leftHost === $rightHost) {
            return true;
        }

        return $this->baseDomainForHost($leftHost) === $this->baseDomainForHost($rightHost);
    }

    private function baseDomainForHost(string $host): string
    {
        $normalized = strtolower(trim($host));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_IP)) {
            return $normalized;
        }

        $parts = array_values(array_filter(explode('.', $normalized), static fn (string $part): bool => $part !== ''));
        if (count($parts) <= 2) {
            return $normalized;
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * @return array<int,string>
     */
    private function buildAllowedRedirectDomains(ClientSite $site, string $baseHost): array
    {
        $domains = [strtolower($baseHost)];

        foreach ((array) ($site->allowed_domains ?? []) as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $normalized = strtolower(trim($domain));
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, '://')) {
                $normalized = strtolower((string) parse_url($normalized, PHP_URL_HOST));
            }

            if ($normalized !== '') {
                $domains[] = $normalized;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function recordFetchDiagnostic(array $entry): void
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'target_url' => (string) ($entry['target_url'] ?? ''),
            'effective_url' => (string) ($entry['effective_url'] ?? ''),
            'final_url' => (string) ($entry['final_url'] ?? ''),
            'host' => (string) ($entry['host'] ?? ''),
            'resolved_host' => (string) ($entry['resolved_host'] ?? ''),
            'status_code' => (int) ($entry['status_code'] ?? 0),
            'redirect_count' => (int) ($entry['redirect_count'] ?? 0),
            'content_type' => (string) ($entry['content_type'] ?? ''),
            'response_length' => (int) ($entry['response_length'] ?? 0),
            'error_category' => (string) ($entry['error_category'] ?? ''),
            'error_message' => (string) ($entry['error_message'] ?? ''),
            'exception_class' => (string) ($entry['exception_class'] ?? ''),
            'tls_verify_disabled' => (bool) ($entry['tls_verify_disabled'] ?? false),
            'http_fallback_used' => (bool) ($entry['http_fallback_used'] ?? false),
            'http_fallback_from' => (string) ($entry['http_fallback_from'] ?? ''),
        ];

        $this->fetchDiagnostics[] = $payload;

        if (count($this->fetchDiagnostics) > self::FETCH_DIAGNOSTIC_SAMPLE_LIMIT) {
            array_shift($this->fetchDiagnostics);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDiagnosticsSummary(): array
    {
        $errorsByCategory = [];

        foreach ($this->fetchDiagnostics as $entry) {
            $category = trim((string) ($entry['error_category'] ?? ''));
            if ($category === '') {
                continue;
            }

            if (! isset($errorsByCategory[$category])) {
                $errorsByCategory[$category] = 0;
            }

            $errorsByCategory[$category]++;
        }

        return [
            'fetches_count' => count($this->fetchDiagnostics),
            'errors_by_category' => $errorsByCategory,
            'fetch_samples' => array_slice($this->fetchDiagnostics, -25),
        ];
    }

    private function toHttpUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $httpUrl = 'http://' . $parts['host'];

        if (isset($parts['port'])) {
            $httpUrl .= ':' . (int) $parts['port'];
        }

        $httpUrl .= (string) ($parts['path'] ?? '/');

        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            $httpUrl .= '?' . $parts['query'];
        }

        return $httpUrl;
    }

    private function extractFirst(string $html, string $xpathQuery): ?string
    {
        $dom = new \DOMDocument();
        if (! @ $dom->loadHTML($html)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query($xpathQuery);
        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent) ?? '');

        return $text !== '' ? $text : null;
    }

    private function extractMetaContent(string $html, string $metaName): ?string
    {
        $dom = new \DOMDocument();
        if (! @ $dom->loadHTML($html)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]', strtolower($metaName));
        $nodes = $xpath->query($query);

        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $content = trim((string) $nodes->item(0)?->attributes?->getNamedItem('content')?->nodeValue);

        return $content !== '' ? $content : null;
    }

    private function extractCanonical(string $html, string $baseUrl): ?string
    {
        $dom = new \DOMDocument();
        if (! @ $dom->loadHTML($html)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]');

        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $href = trim((string) $nodes->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
        if ($href === '') {
            return null;
        }

        return $this->resolveUrl($baseUrl, $href) ?: null;
    }

    private function countWords(string $html): int
    {
        $text = trim(strip_tags($html));
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text) ?: [];

        return count(array_filter($parts, fn ($value) => trim((string) $value) !== ''));
    }

    private function normalizeUrl(string $url): string
    {
        $value = trim($url);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://' . ltrim($value, '/');
        }

        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        return $scheme . '://' . $host . $path;
    }

    private function resolveUrl(string $baseUrl, string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $this->normalizeUrl($href);
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $this->normalizeUrl($scheme . ':' . $href);
        }

        $baseParts = parse_url($baseUrl);
        if (! is_array($baseParts) || empty($baseParts['host'])) {
            return '';
        }

        $origin = (string) ($baseParts['scheme'] ?? 'https') . '://' . (string) $baseParts['host'];

        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($origin . $href);
        }

        $basePath = (string) ($baseParts['path'] ?? '/');
        $dir = rtrim(dirname($basePath), '/');
        if ($dir === '.') {
            $dir = '';
        }

        return $this->normalizeUrl($origin . ($dir ? '/' . ltrim($dir, '/') : '') . '/' . $href);
    }
}
