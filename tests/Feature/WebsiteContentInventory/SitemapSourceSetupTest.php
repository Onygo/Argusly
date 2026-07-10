<?php

use App\Jobs\PageIntelligence\DiscoverMonitoredSourceUrlsJob;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\WebsiteContentInventory\WebsiteSitemapSourceSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'page_intelligence.safety.dns_overrides' => [
            'example.com' => ['93.184.216.34'],
            'evil.example.net' => ['93.184.216.34'],
        ],
    ]);
});

it('creates an idempotent XML sitemap monitored source for a verified site', function (): void {
    Queue::fake();
    $site = sitemapSetupSite('sitemap-setup-standard');

    $first = app(WebsiteSitemapSourceSetupService::class)->ensureForSite($site, ['dispatch_discovery' => true]);
    $second = app(WebsiteSitemapSourceSetupService::class)->ensureForSite($site, ['dispatch_discovery' => true]);

    expect($first->sourcesCreated)->toBe(1)
        ->and($second->sourcesCreated)->toBe(0)
        ->and(MonitoredSource::query()->where('client_site_id', $site->id)->where('source_type', 'xml_sitemap')->count())->toBe(1);

    $source = MonitoredSource::query()->where('client_site_id', $site->id)->firstOrFail();

    expect($source->base_url)->toBe('https://example.com/sitemap.xml')
        ->and($source->discovery_config_json['adapter'])->toBe('xml_sitemap')
        ->and($source->crawl_policy_json['allow_domains'])->toContain('example.com');

    Queue::assertPushed(DiscoverMonitoredSourceUrlsJob::class, 2);
});

it('rejects cross-domain sitemap URLs without an explicit override', function (): void {
    $site = sitemapSetupSite('sitemap-setup-cross-domain', [
        'connector_meta' => ['sitemap_url' => 'https://evil.example.net/sitemap.xml'],
    ]);

    $result = app(WebsiteSitemapSourceSetupService::class)->ensureForSite($site);

    expect($result->sourcesRejected)->toBe(1)
        ->and(MonitoredSource::query()->where('client_site_id', $site->id)->count())->toBe(0);
});

function sitemapSetupSite(string $slug, array $overrides = []): ClientSite
{
    $organization = Organization::query()->create([
        'name' => 'Sitemap Setup '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Sitemap Workspace '.$slug,
        'display_name' => 'Sitemap Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Example',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'active',
    ], $overrides));

    AnalyticsSite::factory()
        ->forClientSite($site)
        ->verified()
        ->create(['allowed_domains' => ['example.com']]);

    return $site;
}
