<?php

use App\Models\MonitoredPage;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PageContentExtractor;
use App\Services\PageIntelligence\PageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'page_intelligence.safety.dns_overrides' => [
            'example.com' => ['93.184.216.34'],
        ],
        'page_intelligence.fetch.raw_html_storage' => 'inline',
        'page_intelligence.storage.extracted_text_storage' => 'inline',
    ]);
});

it('projects observed metadata from page extraction', function (): void {
    $snapshot = inventoryEnrichmentSnapshot(<<<'HTML'
        <html lang="en">
            <head>
                <title>Inventory Launch</title>
                <link rel="canonical" href="https://example.com/inventory-launch">
                <meta name="description" content="A launch page for inventory.">
                <meta property="og:image" content="/images/inventory.jpg">
                <meta name="robots" content="noindex, nofollow">
                <meta property="article:modified_time" content="2026-07-08T12:00:00Z">
                <script type="application/ld+json">
                    [
                        {"@type": ["Article", "BlogPosting"], "headline": "Inventory Launch"},
                        {"@graph": [{"@type": "Organization", "name": "Argusly"}]}
                    ]
                </script>
                <script type="application/ld+json">{ malformed }</script>
            </head>
            <body><main><h1>Inventory Launch</h1><p>Argusly inventory evidence with useful body copy.</p></main></body>
        </html>
        HTML);

    $result = app(PageContentExtractor::class)->extract($snapshot);
    $extraction = $result->extraction;

    expect($extraction->open_graph_image_url)->toBe('https://example.com/images/inventory.jpg')
        ->and($extraction->schema_types_json)->toContain('Article', 'BlogPosting', 'Organization')
        ->and($extraction->meta_robots)->toContain('noindex')
        ->and($extraction->indexability_status)->toBe('noindex')
        ->and($extraction->canonical_url)->toBe('https://example.com/inventory-launch')
        ->and($extraction->content_fingerprint)->toBe($extraction->main_text_hash)
        ->and($extraction->external_modified_at?->toDateTimeString())->toBe('2026-07-08 12:00:00')
        ->and($result->snapshot->metadata_json['inventory']['change_kind'])->toBe('first_successful_fetch')
        ->and($result->page->indexability_status)->toBe('noindex');
});

it('does not mark the first successful fetch as a content change', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/first-fetch',
        'canonical_url_hash' => hash('sha256', 'https://example.com/first-fetch'),
        'first_seen_url' => 'https://example.com/first-fetch',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/first-fetch'),
        'final_url' => 'https://example.com/first-fetch',
        'final_url_hash' => hash('sha256', 'https://example.com/first-fetch'),
        'domain' => 'example.com',
        'path' => '/first-fetch',
        'last_changed_at' => null,
    ]);

    Http::fake([
        'https://example.com/first-fetch' => Http::response('<html><body>First fetch body.</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(PageFetcher::class)->fetch($page);

    expect($result->successful)->toBeTrue()
        ->and($result->snapshot->content_changed)->toBeFalse()
        ->and($result->page->last_changed_at)->toBeNull();
});

function inventoryEnrichmentSnapshot(string $html): PageSnapshot
{
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/inventory-launch',
        'canonical_url_hash' => hash('sha256', 'https://example.com/inventory-launch'),
        'first_seen_url' => 'https://example.com/inventory-launch',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/inventory-launch'),
        'final_url' => 'https://example.com/inventory-launch',
        'final_url_hash' => hash('sha256', 'https://example.com/inventory-launch'),
        'domain' => 'example.com',
        'path' => '/inventory-launch',
    ]);

    return PageSnapshot::factory()->forPage($page)->create([
        'raw_html' => $html,
        'raw_html_path' => null,
        'raw_html_hash' => hash('sha256', $html),
        'content_changed' => false,
        'fetched_at' => now(),
    ]);
}
