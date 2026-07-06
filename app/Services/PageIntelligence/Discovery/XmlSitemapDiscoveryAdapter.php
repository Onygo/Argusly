<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;
use App\Services\PageIntelligence\PageCrawlerSafetyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class XmlSitemapDiscoveryAdapter implements DiscoveryAdapter
{
    public function __construct(private readonly PageCrawlerSafetyService $safety)
    {
    }

    public function discover(MonitoredSource $source): iterable
    {
        $config = (array) ($source->discovery_config_json ?? []);
        $sitemapUrl = (string) ($config['sitemap_url'] ?? $config['url'] ?? $source->base_url);

        if (trim($sitemapUrl) === '') {
            throw new RuntimeException('XML sitemap discovery requires a sitemap URL.');
        }

        yield from $this->discoverSitemap($source, $sitemapUrl, 0);
    }

    private function discoverSitemap(MonitoredSource $source, string $sitemapUrl, int $depth): iterable
    {
        if ($depth > 1) {
            return;
        }

        $sitemapUrl = $this->safety->normalizeAndValidate($sitemapUrl, $source);

        $response = Http::timeout($this->timeout($source))
            ->withUserAgent($this->userAgent())
            ->withOptions(array_replace_recursive($this->safety->guardedHttpOptions($sitemapUrl, $source), [
                'allow_redirects' => [
                    'max' => $this->redirectLimit(),
                    'track_redirects' => true,
                    'on_redirect' => function ($request, $response, $uri) use ($source): void {
                        unset($request, $response);
                        $this->safety->validateRedirectTarget((string) $uri, $source);
                    },
                ],
            ]))
            ->get($sitemapUrl);

        if (! $response->successful()) {
            throw new RuntimeException('XML sitemap discovery failed with HTTP '.$response->status().'.');
        }

        $this->safety->assertResponseAllowed(
            response: $response,
            url: $sitemapUrl,
            allowedContentTypes: ['application/xml', 'text/xml', 'text/plain'],
            maxBytes: (int) config('page_intelligence.fetch.max_html_bytes', 3000000),
            source: $source,
        );

        $xml = $this->parseXml($response->body());
        $config = (array) ($source->discovery_config_json ?? []);

        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $loc = $this->text($sitemap->loc ?? null);

                if ($loc !== '') {
                    yield from $this->discoverSitemap($source, $loc, $depth + 1);
                }
            }

            return;
        }

        foreach ($xml->url as $url) {
            $loc = $this->text($url->loc ?? null);

            if ($loc === '') {
                continue;
            }

            yield new DiscoveredUrl(
                url: $loc,
                publishedAt: $this->date($this->text($url->lastmod ?? null)),
                priority: (int) ($config['priority'] ?? 60),
                pageType: (string) ($config['page_type'] ?? 'page'),
                metadata: ['discovery_adapter' => 'xml_sitemap', 'sitemap_url' => $sitemapUrl],
            );
        }
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('XML sitemap discovery returned invalid XML.');
        }

        return $xml;
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function date(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function timeout(MonitoredSource $source): int
    {
        $config = (array) ($source->fetch_config_json ?? []);

        return max(1, (int) ($config['timeout_seconds'] ?? config('page_intelligence.discovery.timeout_seconds', 15)));
    }

    private function userAgent(): string
    {
        return (string) config('page_intelligence.fetch.user_agent', 'ArguslyPageIntelligence/1.0 (+https://argusly.com)');
    }

    private function redirectLimit(): int
    {
        return max(0, (int) config('page_intelligence.fetch.redirect_limit', 5));
    }
}
