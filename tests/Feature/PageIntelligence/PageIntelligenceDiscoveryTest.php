<?php

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Services\PageIntelligence\Discovery\MonitoredSourceUrlDiscoverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('discovers RSS URLs and creates monitored pages', function (): void {
    Queue::fake();
    Http::fake([
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
              <channel>
                <item>
                  <title>Argusly launches Page Intelligence</title>
                  <link>https://example.com/news/page-intelligence?utm_source=rss</link>
                  <pubDate>Fri, 03 Jul 2026 10:00:00 GMT</pubDate>
                </item>
              </channel>
            </rss>
            XML, 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'base_url' => 'https://example.com/feed.xml',
        'discovery_config_json' => [
            'priority' => 90,
            'fetch_priority_threshold' => 80,
            'max_urls' => 10,
        ],
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->successful)->toBeTrue()
        ->and($result->created)->toBe(1)
        ->and(MonitoredPage::query()->where('monitored_source_id', $source->id)->count())->toBe(1);

    $page = MonitoredPage::query()->where('monitored_source_id', $source->id)->firstOrFail();

    expect($page->canonical_url)->toBe('https://example.com/news/page-intelligence')
        ->and($page->first_seen_url)->toBe('https://example.com/news/page-intelligence?utm_source=rss')
        ->and($page->title_current)->toBe('Argusly launches Page Intelligence')
        ->and($source->refresh()->metadata_json['last_discovery_run']['created'])->toBe(1);

    Queue::assertPushed(FetchMonitoredPageJob::class, fn (FetchMonitoredPageJob $job): bool => $job->monitoredPageId === $page->id);
});

it('discovers XML sitemap URLs and creates monitored pages', function (): void {
    Queue::fake();
    Http::fake([
        'https://example.com/sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url>
                <loc>https://example.com/blog/market-map</loc>
                <lastmod>2026-07-02</lastmod>
              </url>
              <url>
                <loc>https://example.com/blog/launch</loc>
              </url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'xml_sitemap',
        'base_url' => 'https://example.com/sitemap.xml',
        'discovery_config_json' => ['priority' => 40],
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->successful)->toBeTrue()
        ->and($result->created)->toBe(2)
        ->and(MonitoredPage::query()->where('monitored_source_id', $source->id)->pluck('canonical_url')->all())
        ->toContain('https://example.com/blog/market-map', 'https://example.com/blog/launch');

    Queue::assertNotPushed(FetchMonitoredPageJob::class);
});

it('blocks unsafe RSS discovery URLs before sending HTTP requests', function (): void {
    Queue::fake();
    Http::fake();

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'base_url' => 'http://127.0.0.1/feed.xml',
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->successful)->toBeFalse()
        ->and($source->refresh()->last_error)->toContain('not public');

    Http::assertNothingSent();
});

it('blocks unsafe XML sitemap discovery URLs before sending HTTP requests', function (): void {
    Queue::fake();
    Http::fake();

    $source = MonitoredSource::factory()->create([
        'source_type' => 'xml_sitemap',
        'base_url' => 'http://10.0.0.12/sitemap.xml',
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->successful)->toBeFalse()
        ->and($source->refresh()->last_error)->toContain('not public');

    Http::assertNothingSent();
});

it('dedupes duplicate discovered URLs into one monitored page', function (): void {
    Queue::fake();
    Http::fake([
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
              <channel>
                <item><title>One</title><link>https://example.com/post?utm_source=a</link></item>
                <item><title>Two</title><link>https://example.com/post?utm_source=b</link></item>
              </channel>
            </rss>
            XML, 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'base_url' => 'https://example.com/feed.xml',
    ]);

    app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect(MonitoredPage::query()->where('workspace_id', $source->workspace_id)->where('canonical_url', 'https://example.com/post')->count())->toBe(1);
});

it('rejects unsafe RSS item URLs before persistence', function (): void {
    Queue::fake();
    config()->set('page_intelligence.safety.dns_overrides', ['example.com' => ['93.184.216.34']]);
    Http::fake([
        'https://example.com/feed.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
              <channel>
                <item><title>Unsafe</title><link>http://127.0.0.1/private</link></item>
              </channel>
            </rss>
            XML, 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'base_url' => 'https://example.com/feed.xml',
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->created)->toBe(0)
        ->and($result->failedUrls)->toBe(1)
        ->and(MonitoredPage::query()->where('workspace_id', $source->workspace_id)->count())->toBe(0);
});

it('rejects unsafe sitemap item URLs before persistence', function (): void {
    Queue::fake();
    config()->set('page_intelligence.safety.dns_overrides', ['example.com' => ['93.184.216.34']]);
    Http::fake([
        'https://example.com/sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <url><loc>http://10.0.0.7/private</loc></url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'xml_sitemap',
        'base_url' => 'https://example.com/sitemap.xml',
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->created)->toBe(0)
        ->and($result->failedUrls)->toBe(1)
        ->and(MonitoredPage::query()->where('workspace_id', $source->workspace_id)->count())->toBe(0);
});

it('rejects unsafe manual discovered URLs before persistence', function (): void {
    Queue::fake();
    Http::fake();
    $source = MonitoredSource::factory()->create([
        'source_type' => 'manual',
        'discovery_config_json' => [
            'urls' => [['url' => 'http://169.254.169.254/latest', 'priority' => 95]],
        ],
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->created)->toBe(0)
        ->and($result->failedUrls)->toBe(1)
        ->and(MonitoredPage::query()->where('workspace_id', $source->workspace_id)->count())->toBe(0);

    Http::assertNothingSent();
});

it('records source failure diagnostics', function (): void {
    Queue::fake();
    Http::fake([
        'https://example.com/feed.xml' => Http::response('not xml', 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'base_url' => 'https://example.com/feed.xml',
        'failure_count' => 0,
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);
    $source->refresh();

    expect($result->successful)->toBeFalse()
        ->and($source->failure_count)->toBe(1)
        ->and($source->last_error)->toContain('invalid XML')
        ->and($source->metadata_json['last_discovery_run']['status'])->toBe('failed');
});

it('skips inactive monitored sources', function (): void {
    Queue::fake();
    Http::fake();

    $source = MonitoredSource::factory()->create([
        'source_type' => 'rss',
        'status' => MonitoredSource::STATUS_PAUSED,
        'base_url' => 'https://example.com/feed.xml',
    ]);

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->skipped)->toBeTrue()
        ->and($result->message)->toBe('inactive_source')
        ->and(MonitoredPage::query()->where('monitored_source_id', $source->id)->count())->toBe(0)
        ->and($source->refresh()->metadata_json['last_discovery_run']['status'])->toBe('skipped');

    Http::assertNothingSent();
});

it('discovers monitored source URLs from the artisan command synchronously', function (): void {
    Queue::fake();

    $source = MonitoredSource::factory()->create([
        'source_type' => 'manual',
        'discovery_config_json' => [
            'urls' => [
                ['url' => 'https://example.com/manual-page', 'priority' => 95, 'page_type' => 'campaign_page'],
            ],
        ],
    ]);

    $this->artisan('page-intelligence:discover', [
        'sourceId' => $source->id,
        '--sync' => true,
    ])
        ->expectsOutput('Monitored source discovery completed.')
        ->assertSuccessful();

    expect(MonitoredPage::query()->where('monitored_source_id', $source->id)->where('canonical_url', 'https://example.com/manual-page')->exists())->toBeTrue();
});
