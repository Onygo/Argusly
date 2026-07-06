<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;
use App\Services\PageIntelligence\PageCrawlerSafetyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class RssDiscoveryAdapter implements DiscoveryAdapter
{
    public function __construct(private readonly PageCrawlerSafetyService $safety)
    {
    }

    public function discover(MonitoredSource $source): iterable
    {
        $config = (array) ($source->discovery_config_json ?? []);
        $feedUrl = (string) ($config['feed_url'] ?? $config['url'] ?? $source->base_url);

        if (trim($feedUrl) === '') {
            throw new RuntimeException('RSS discovery requires a feed URL.');
        }

        $feedUrl = $this->safety->normalizeAndValidate($feedUrl, $source);

        $response = Http::timeout($this->timeout($source))
            ->withUserAgent($this->userAgent())
            ->withOptions(array_replace_recursive($this->safety->guardedHttpOptions($feedUrl, $source), [
                'allow_redirects' => [
                    'max' => $this->redirectLimit(),
                    'track_redirects' => true,
                    'on_redirect' => function ($request, $response, $uri) use ($source): void {
                        unset($request, $response);
                        $this->safety->validateRedirectTarget((string) $uri, $source);
                    },
                ],
            ]))
            ->get($feedUrl);

        if (! $response->successful()) {
            throw new RuntimeException('RSS discovery failed with HTTP '.$response->status().'.');
        }

        $this->safety->assertResponseAllowed(
            response: $response,
            url: $feedUrl,
            allowedContentTypes: ['application/rss+xml', 'application/xml', 'text/xml', 'text/plain'],
            maxBytes: (int) config('page_intelligence.fetch.max_html_bytes', 3000000),
            source: $source,
        );

        $xml = $this->parseXml($response->body());
        $items = $xml->channel->item ?? $xml->entry ?? [];

        foreach ($items as $item) {
            $url = $this->itemUrl($item);

            if ($url === '') {
                continue;
            }

            yield new DiscoveredUrl(
                url: $url,
                title: $this->text($item->title ?? null),
                publishedAt: $this->date($this->text($item->pubDate ?? $item->published ?? $item->updated ?? null)),
                priority: (int) ($config['priority'] ?? 70),
                pageType: (string) ($config['page_type'] ?? 'article'),
                metadata: ['discovery_adapter' => 'rss', 'feed_url' => $feedUrl],
            );
        }
    }

    private function itemUrl(SimpleXMLElement $item): string
    {
        $link = $item->link ?? null;

        if ($link instanceof SimpleXMLElement && isset($link['href'])) {
            return trim((string) $link['href']);
        }

        return $this->text($link);
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('RSS discovery returned invalid XML.');
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
