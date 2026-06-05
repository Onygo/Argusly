<?php

namespace App\Services\OnboardingScan;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebsiteCrawlerService
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const USER_AGENT = 'PublishLayerOnboardingScan/1.0 (+https://publishlayer.com)';

    private const SYSTEM_PATH_PATTERNS = [
        '/wp-admin',
        '/wp-login',
        '/wp-content',
        '/wp-includes',
        '/tag/',
        '/category/',
        '/author/',
        '/cart',
        '/checkout',
        '/my-account',
        '/login',
        '/register',
        '/admin',
        '/feed',
        '/xmlrpc',
        '/trackback',
        '?',
        '#',
    ];

    private const PRIORITY_PATH_PATTERNS = [
        '/about' => 100,
        '/over-ons' => 100,
        '/services' => 90,
        '/diensten' => 90,
        '/products' => 85,
        '/producten' => 85,
        '/solutions' => 85,
        '/oplossingen' => 85,
        '/team' => 80,
        '/contact' => 75,
        '/blog' => 70,
        '/news' => 70,
        '/nieuws' => 70,
        '/pricing' => 65,
        '/features' => 65,
        '/how-it-works' => 60,
        '/case' => 55,
        '/portfolio' => 55,
        '/faq' => 50,
    ];

    /** @var array<string, mixed> */
    private array $diagnostics = [];

    /**
     * Crawl a website and return the homepage plus up to maxPages internal pages.
     *
     * @return array{homepage: array, internal_pages: array<string, array>, diagnostics: array}
     */
    public function crawl(string $url, int $maxPages = 5): array
    {
        $this->diagnostics = [
            'started_at' => now()->toIso8601String(),
            'base_url' => $url,
            'max_pages' => $maxPages,
            'pages_fetched' => 0,
            'pages_failed' => 0,
            'links_discovered' => 0,
        ];

        $maxPages = max(1, min($maxPages, 10));
        $baseUrl = $this->normalizeUrl($url);
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($baseHost === '') {
            $this->diagnostics['error'] = 'Invalid URL: could not parse host';

            return [
                'homepage' => $this->createFailedPage($baseUrl, 'Invalid URL'),
                'internal_pages' => [],
                'diagnostics' => $this->diagnostics,
            ];
        }

        $homepage = $this->fetchPage($baseUrl);
        $this->diagnostics['pages_fetched']++;

        if (! $homepage['success']) {
            $this->diagnostics['pages_failed']++;
            $this->diagnostics['error'] = $homepage['error'] ?? 'Failed to fetch homepage';

            return [
                'homepage' => $homepage,
                'internal_pages' => [],
                'diagnostics' => $this->diagnostics,
            ];
        }

        $internalLinks = $this->extractInternalLinks($homepage['html'], $baseUrl, $baseHost);
        $this->diagnostics['links_discovered'] = count($internalLinks);

        $prioritizedLinks = $this->prioritizePages($internalLinks);
        $pagesToFetch = array_slice($prioritizedLinks, 0, $maxPages);

        $internalPages = [];
        foreach ($pagesToFetch as $pageUrl) {
            $page = $this->fetchPage($pageUrl);
            $this->diagnostics['pages_fetched']++;

            if (! $page['success']) {
                $this->diagnostics['pages_failed']++;
                continue;
            }

            $internalPages[$pageUrl] = $page;
        }

        $this->diagnostics['completed_at'] = now()->toIso8601String();

        return [
            'homepage' => $homepage,
            'internal_pages' => $internalPages,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * Fetch a single page and return its details.
     *
     * @return array{url: string, success: bool, html: string|null, status_code: int|null, error: string|null, content_type: string|null}
     */
    public function fetchPage(string $url): array
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,nl;q=0.8',
                ])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => true,
                        'track_redirects' => true,
                    ],
                    'verify' => true,
                ])
                ->get($url);

            $statusCode = $response->status();
            $contentType = strtolower((string) $response->header('Content-Type'));

            if ($statusCode >= 400) {
                return [
                    'url' => $url,
                    'success' => false,
                    'html' => null,
                    'status_code' => $statusCode,
                    'error' => "HTTP error: {$statusCode}",
                    'content_type' => $contentType,
                ];
            }

            if (! $this->isHtmlContentType($contentType)) {
                return [
                    'url' => $url,
                    'success' => false,
                    'html' => null,
                    'status_code' => $statusCode,
                    'error' => 'Content is not HTML',
                    'content_type' => $contentType,
                ];
            }

            $body = $response->body();

            return [
                'url' => $url,
                'success' => true,
                'html' => $body,
                'status_code' => $statusCode,
                'error' => null,
                'content_type' => $contentType,
            ];
        } catch (ConnectionException $e) {
            Log::warning('WebsiteCrawlerService: Connection error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'url' => $url,
                'success' => false,
                'html' => null,
                'status_code' => null,
                'error' => 'Connection error: ' . $e->getMessage(),
                'content_type' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('WebsiteCrawlerService: Fetch error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'url' => $url,
                'success' => false,
                'html' => null,
                'status_code' => null,
                'error' => 'Fetch error: ' . $e->getMessage(),
                'content_type' => null,
            ];
        }
    }

    /**
     * Extract internal links from HTML content.
     *
     * @return array<int, string>
     */
    public function extractInternalLinks(string $html, string $baseUrl, string $baseHost): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (! @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            libxml_clear_errors();

            return [];
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');

        if (! $nodes) {
            return [];
        }

        $urls = [];

        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);

            if ($href === '' || $this->isIgnoredLink($href)) {
                continue;
            }

            $resolved = $this->resolveUrl($baseUrl, $href);

            if ($resolved === '') {
                continue;
            }

            $resolvedHost = strtolower((string) parse_url($resolved, PHP_URL_HOST));
            if ($resolvedHost !== $baseHost) {
                continue;
            }

            if ($this->isSystemPath($resolved)) {
                continue;
            }

            $urls[] = $this->normalizeUrl($resolved);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Prioritize pages based on path patterns, returning most valuable pages first.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    public function prioritizePages(array $urls): array
    {
        $scored = [];

        foreach ($urls as $url) {
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            $score = 0;

            foreach (self::PRIORITY_PATH_PATTERNS as $pattern => $patternScore) {
                if (str_contains($path, $pattern)) {
                    $score = max($score, $patternScore);
                }
            }

            // Prefer shorter paths (likely more important pages)
            $pathDepth = substr_count(trim($path, '/'), '/');
            $score -= $pathDepth * 2;

            $scored[$url] = $score;
        }

        arsort($scored);

        return array_keys($scored);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // Add scheme if missing
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        // Parse and rebuild to normalize
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        // Remove trailing slash
        if (str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Don't append path if it's empty (root)
        $pathPart = $path !== '' ? $path : '';

        return "{$scheme}://{$host}{$port}{$pathPart}";
    }

    private function resolveUrl(string $baseUrl, string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$port}{$href}";
        }

        // Relative path
        $basePath = $parts['path'] ?? '/';
        $baseDir = dirname($basePath);
        $baseDir = $baseDir === '.' ? '/' : $baseDir;

        return "{$scheme}://{$host}{$port}" . rtrim($baseDir, '/') . '/' . $href;
    }

    private function isIgnoredLink(string $href): bool
    {
        return str_starts_with($href, '#')
            || str_starts_with($href, 'mailto:')
            || str_starts_with($href, 'tel:')
            || str_starts_with($href, 'javascript:')
            || str_starts_with($href, 'data:');
    }

    private function isSystemPath(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = parse_url($url, PHP_URL_QUERY);

        foreach (self::SYSTEM_PATH_PATTERNS as $pattern) {
            if ($pattern === '?' && $query !== null) {
                return true;
            }
            if ($pattern !== '?' && $pattern !== '#' && str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isHtmlContentType(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');
    }

    /**
     * @return array{url: string, success: bool, html: null, status_code: null, error: string, content_type: null}
     */
    private function createFailedPage(string $url, string $error): array
    {
        return [
            'url' => $url,
            'success' => false,
            'html' => null,
            'status_code' => null,
            'error' => $error,
            'content_type' => null,
        ];
    }
}
