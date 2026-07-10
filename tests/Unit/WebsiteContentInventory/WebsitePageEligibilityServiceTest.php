<?php

use App\Models\MonitoredPage;
use App\Models\PageSnapshot;
use App\Services\WebsiteContentInventory\WebsitePageEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes inventory URLs with the query allowlist', function (): void {
    $service = app(WebsitePageEligibilityService::class);

    expect($service->normalizeUrl('https://Example.com/Blog/Launch/?utm_source=news&page=2&token=secret'))
        ->toBe('https://example.com/Blog/Launch?page=2');
});

it('marks excluded paths ineligible', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/login',
        'canonical_url_hash' => hash('sha256', 'https://example.com/login'),
        'first_seen_url' => 'https://example.com/login?utm_source=tracking',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/login?utm_source=tracking'),
        'final_url' => 'https://example.com/login',
        'final_url_hash' => hash('sha256', 'https://example.com/login'),
        'path' => '/login',
    ]);

    $result = app(WebsitePageEligibilityService::class)->evaluate($page);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('excluded_path');
});

it('uses HTTP status, indexability, and robots metadata', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/private-report',
        'canonical_url_hash' => hash('sha256', 'https://example.com/private-report'),
        'indexability_status' => 'noindex',
        'metadata_json' => ['robots_allowed' => false],
    ]);

    PageSnapshot::factory()->forPage($page)->create([
        'http_status' => 404,
    ]);

    $result = app(WebsitePageEligibilityService::class)->evaluate($page);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('http_status_ineligible')
        ->and($result->reasons)->toContain('indexability_ineligible')
        ->and($result->reasons)->toContain('robots_disallowed');
});

it('supports review overrides centrally', function (): void {
    $included = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/admin/case-study',
        'canonical_url_hash' => hash('sha256', 'https://example.com/admin/case-study'),
        'path' => '/admin/case-study',
        'metadata_json' => ['review_override' => 'include'],
    ]);

    $excluded = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/launch',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/launch'),
        'metadata_json' => ['review_override' => 'excluded'],
    ]);

    $service = app(WebsitePageEligibilityService::class);

    expect($service->evaluate($included)->eligible)->toBeTrue()
        ->and($service->evaluate($excluded)->eligible)->toBeFalse()
        ->and($service->evaluate($excluded)->reasons)->toContain('review_excluded');
});

it('rejects non-public URLs', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'http://127.0.0.1/report',
        'canonical_url_hash' => hash('sha256', 'http://127.0.0.1/report'),
        'first_seen_url' => 'http://127.0.0.1/report',
        'first_seen_url_hash' => hash('sha256', 'http://127.0.0.1/report'),
        'final_url' => 'http://127.0.0.1/report',
        'final_url_hash' => hash('sha256', 'http://127.0.0.1/report'),
    ]);

    $result = app(WebsitePageEligibilityService::class)->evaluate($page);

    expect($result->eligible)->toBeFalse()
        ->and($result->reasons)->toContain('not_public');
});
