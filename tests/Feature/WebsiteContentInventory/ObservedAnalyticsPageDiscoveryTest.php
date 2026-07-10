<?php

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\WebsiteContentInventory\ObservedAnalyticsPageDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'page_intelligence.safety.dns_overrides' => [
            'example.com' => ['93.184.216.34'],
            'www.example.com' => ['93.184.216.34'],
            'evil.example.net' => ['93.184.216.34'],
        ],
        'website_content_inventory.analytics_observed.automatic_fetch_after_discovery' => true,
    ]);
});

it('discovers eligible analytics-observed pages once and excludes unsafe or managed events', function (): void {
    Queue::fake();
    [$workspace, $site, $analyticsSite] = observedDiscoveryContext('observed-discovery-main');

    observedDiscoveryEvent($analyticsSite, 'https://example.com/pricing?utm_source=ad#hero', 'Pricing');
    observedDiscoveryEvent($analyticsSite, 'https://example.com/pricing?utm_campaign=duplicate', 'Pricing duplicate');
    observedDiscoveryEvent($analyticsSite, 'https://evil.example.net/pricing', 'Foreign');
    observedDiscoveryEvent($analyticsSite, 'https://example.com/login?next=/account', 'Login');
    observedDiscoveryEvent($analyticsSite, 'https://example.com/blog/managed', 'Managed', [
        'page_type' => 'argusly_content',
    ]);

    $result = app(ObservedAnalyticsPageDiscoveryService::class)->discoverForAnalyticsSite($analyticsSite, [
        'limit' => 10,
        'chunk' => 2,
    ]);

    expect($result->processedEvents)->toBe(4)
        ->and($result->consideredUrls)->toBe(2)
        ->and($result->submittedUrls)->toBe(1)
        ->and($result->createdPages)->toBe(1)
        ->and($result->excludedUrls)->toBe(1)
        ->and($result->skipReasons['duplicate_url'] ?? 0)->toBe(1)
        ->and($result->skipReasons['foreign_domain'] ?? 0)->toBe(1)
        ->and(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    $page = MonitoredPage::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($page->canonical_url)->toBe('https://example.com/pricing')
        ->and($page->client_site_id)->toBe($site->id)
        ->and($page->source_type)->toBe('analytics_observed');

    Queue::assertPushed(FetchMonitoredPageJob::class, fn (FetchMonitoredPageJob $job): bool => $job->monitoredPageId === $page->id);
});

it('keeps allowlisted query parameters only when explicitly enabled', function (): void {
    Queue::fake();
    [, , $analyticsSite] = observedDiscoveryContext('observed-discovery-query');

    config([
        'website_content_inventory.analytics_observed.preserve_allowlisted_query_parameters' => true,
        'website_content_inventory.analytics_observed.query_parameter_allowlist' => ['lang'],
    ]);

    observedDiscoveryEvent($analyticsSite, 'https://example.com/pricing?lang=nl&utm_source=ad', 'Prijzen');

    app(ObservedAnalyticsPageDiscoveryService::class)->discoverForAnalyticsSite($analyticsSite, ['limit' => 10]);

    expect(MonitoredPage::query()->where('canonical_url', 'https://example.com/pricing?lang=nl')->exists())->toBeTrue();
});

it('does not write pages during dry runs and reports command statistics', function (): void {
    [$workspace, , $analyticsSite] = observedDiscoveryContext('observed-discovery-dry-run');
    observedDiscoveryEvent($analyticsSite, 'https://example.com/features', 'Features');

    $this->artisan('website-content:discover-observed-pages', [
        '--analytics-site' => $analyticsSite->id,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('considered_urls')
        ->assertExitCode(0);

    expect(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

function observedDiscoveryContext(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => 'Observed Discovery '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Observed Discovery Workspace '.$slug,
        'display_name' => 'Observed Discovery Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Example',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'active',
    ]);

    $analyticsSite = AnalyticsSite::factory()
        ->forClientSite($site)
        ->verified()
        ->create(['allowed_domains' => ['example.com']]);

    return [$workspace, $site, $analyticsSite];
}

function observedDiscoveryEvent(AnalyticsSite $site, string $url, string $title, array $overrides = []): AnalyticsEvent
{
    $path = (string) parse_url($url, PHP_URL_PATH) ?: '/';
    $host = (string) parse_url($url, PHP_URL_HOST) ?: 'example.com';

    return AnalyticsEvent::query()->create(array_merge([
        'analytics_site_id' => $site->id,
        'event_type' => 'page_view',
        'visitor_hash' => hash('sha256', 'visitor-'.Str::random(8)),
        'session_hash' => hash('sha256', 'session-'.Str::random(8)),
        'url' => $url,
        'canonical_url' => $url,
        'url_key' => strtolower($host.$path),
        'canonical_url_hash' => hash('sha256', $url),
        'path' => $path,
        'path_hash' => AnalyticsEvent::computePathHash($path),
        'title' => $title,
        'host' => $host,
        'page_type' => 'other_page',
        'content_id' => null,
        'content_type' => null,
        'meta' => [],
        'event_time' => now(),
        'received_at' => now(),
        'event_hash' => hash('sha256', $url.Str::random(12)),
    ], $overrides));
}
