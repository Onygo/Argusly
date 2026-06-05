<?php

use App\Jobs\SeoAudit\RunSeoAuditJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\SeoAudit;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSeoAuditContext(int $monthlyCap): array
{
    $organization = Organization::query()->create([
        'name' => 'SEO Audit Org',
        'slug' => 'seo-audit-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'SEO Audit Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'SEO Audit Site',
        'site_url' => 'https://seo-audit.example.com',
        'base_url' => 'https://seo-audit.example.com',
        'allowed_domains' => ['seo-audit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'seo-audit-plan-' . Str::random(4),
        'slug' => 'seo-audit-plan-' . Str::random(4),
        'name' => 'SEO Audit Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 3, 'workspaces' => 1],
        'is_active' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'seo_audit_crawl_pages_per_month_limit',
        'value_type' => 'int',
        'value_int' => $monthlyCap,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    return [$workspace, $site];
}

it('job respects monthly page cap and creates audit pages and issues', function () {
    [$workspace, $site] = makeSeoAuditContext(2);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><head><title>Home page title that is intentionally too long for SEO checks</title></head><body><a href="/a">A</a><a href="/b">B</a></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/a' => Http::response('<html><head><title>A</title><meta name="description" content=""></head><body><h1></h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/b' => Http::response('<html><head><title>B</title></head><body><h1>B heading</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 10));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');
    expect($audit->pages_crawled)->toBe(2);

    $pageCount = SeoAuditPage::query()->where('seo_audit_id', $audit->id)->count();
    expect($pageCount)->toBe(2);

    $issueCount = SeoAuditIssue::query()->where('seo_audit_id', $audit->id)->count();
    expect($issueCount)->toBeGreaterThan(0);

    $usage = (int) WorkspaceUsage::query()
        ->where('workspace_id', $workspace->id)
        ->where('site_id', $site->id)
        ->where('period_ym', now()->format('Ym'))
        ->sum('audit_pages_crawled');

    expect($usage)->toBe(2);
});

it('configures queue timeout controls for longer crawl runs', function () {
    $job = new RunSeoAuditJob((string) Str::uuid(), 50);

    expect($job->timeout)->toBe(900)
        ->and($job->backoff)->toBe(30)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('uses sitemap discovery when sitemap is available', function () {
    [, $site] = makeSeoAuditContext(10);

    $sitemap = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://seo-audit.example.com/page-1</loc></url>
  <url><loc>https://seo-audit.example.com/page-2</loc></url>
</urlset>
XML;

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
        'https://seo-audit.example.com/page-1' => Http::response('<html><head><title>Page 1</title><meta name="description" content="Desc 1"><link rel="canonical" href="https://seo-audit.example.com/page-1"></head><body><h1>Page 1</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/page-2' => Http::response('<html><head><title>Page 2</title><meta name="description" content="Desc 2"><link rel="canonical" href="https://seo-audit.example.com/page-2"></head><body><h1>Page 2</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 10));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect(data_get($audit->meta, 'crawl_source'))->toBe('sitemap');

    $urls = SeoAuditPage::query()->where('seo_audit_id', $audit->id)->pluck('url')->all();
    expect($urls)->toContain('https://seo-audit.example.com/page-1');
    expect($urls)->toContain('https://seo-audit.example.com/page-2');
});

it('falls back to http when https fails for local domains', function () {
    [, $site] = makeSeoAuditContext(10);

    $site->update([
        'site_url' => 'https://publishsource.local',
        'base_url' => 'https://publishsource.local',
        'allowed_domains' => ['publishsource.local'],
    ]);

    Http::fake([
        'https://publishsource.local/*' => Http::failedConnection(),
        'http://publishsource.local/sitemap.xml' => Http::response('not found', 404),
        'http://publishsource.local/' => Http::response('<html><head><title>Local Home</title><meta name="description" content="Local description"><link rel="canonical" href="http://publishsource.local/"></head><body><h1>Home</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 1));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');
    expect($audit->pages_crawled)->toBe(1);

    $page = SeoAuditPage::query()->where('seo_audit_id', $audit->id)->first();
    expect($page)->not->toBeNull();
    expect((int) $page->status_code)->toBe(200);

    $httpErrorCount = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'http_error')
        ->count();

    expect($httpErrorCount)->toBe(0);
});

it('disables tls verification only for local development crawler fetches and crawls html pages', function () {
    [, $site] = makeSeoAuditContext(10);

    config()->set('publishlayer.http_insecure_local', true);
    config()->set('app.env', 'local');

    $site->update([
        'site_url' => 'https://wordpress.publishlayer.local',
        'base_url' => 'https://wordpress.publishlayer.local',
        'allowed_domains' => ['wordpress.publishlayer.local'],
    ]);

    Http::fake([
        'https://wordpress.publishlayer.local/sitemap.xml' => Http::response('not found', 404),
        'https://wordpress.publishlayer.local/' => Http::response('<html><head><title>Home</title><meta name="description" content="Home description"><link rel="canonical" href="https://wordpress.publishlayer.local/"></head><body><h1>Home</h1><a href="/about">About</a></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://wordpress.publishlayer.local/about' => Http::response('<html><head><title>About</title><meta name="description" content="About description"><link rel="canonical" href="https://wordpress.publishlayer.local/about"></head><body><h1>About</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 2));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');
    expect($audit->pages_crawled)->toBe(2);

    $httpErrorCount = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'http_error')
        ->count();

    expect($httpErrorCount)->toBe(0);

    $fetchSamples = (array) data_get($audit->meta, 'fetch_diagnostics.fetch_samples', []);
    expect(collect($fetchSamples)->contains(fn (array $sample): bool => (bool) ($sample['tls_verify_disabled'] ?? false)))->toBeTrue();
});

it('captures final redirected url in audit metadata', function () {
    [, $site] = makeSeoAuditContext(10);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><head><title>Home</title><meta name="description" content="Desc"><link rel="canonical" href="https://seo-audit.example.com/home"></head><body><h1>Home</h1></body></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Guzzle-Redirect-History' => 'https://seo-audit.example.com/home',
        ]),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 1));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');

    $fetchSamples = (array) data_get($audit->meta, 'fetch_diagnostics.fetch_samples', []);
    $homepageSample = collect($fetchSamples)->first(fn (array $sample): bool => ($sample['target_url'] ?? '') === 'https://seo-audit.example.com/');

    expect($homepageSample)->toBeArray();
    expect(data_get($homepageSample, 'final_url'))->toBe('https://seo-audit.example.com/home');
    expect((int) data_get($homepageSample, 'redirect_count'))->toBe(1);
});

it('marks the audit as failed when the crawler is redirected to login instead of public content', function () {
    [, $site] = makeSeoAuditContext(10);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><head><title>Login</title></head><body><form action="/login"><input name="email"></form></body></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Guzzle-Redirect-History' => 'https://seo-audit.example.com/login',
        ]),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 1));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('failed');
    expect((string) $audit->error_message)->toContain('redirected to a login');

    $fetchSamples = (array) data_get($audit->meta, 'fetch_diagnostics.fetch_samples', []);
    $homepageSample = collect($fetchSamples)->first(fn (array $sample): bool => ($sample['target_url'] ?? '') === 'https://seo-audit.example.com/');

    expect($homepageSample)->toBeArray();
    expect(data_get($homepageSample, 'error_category'))->toBe('login_redirect');
    expect((int) data_get($homepageSample, 'response_length'))->toBeGreaterThan(0);
});

it('records non_html http errors and skips h1 detector', function () {
    [, $site] = makeSeoAuditContext(10);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 1));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('failed');
    expect((string) $audit->error_message)->toContain('not parseable HTML');

    $httpIssue = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'http_error')
        ->first();

    expect($httpIssue)->not->toBeNull();
    expect((string) data_get($httpIssue?->context_json, 'fetch_error_category'))->toBe('non_html');

    $h1MissingCount = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'h1_missing')
        ->count();

    expect($h1MissingCount)->toBe(0);
});

it('records server errors and skips content detectors for failed pages', function () {
    [, $site] = makeSeoAuditContext(10);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><body><h1>Broken</h1></body></html>', 500, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 1));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('failed');
    expect((string) $audit->error_message)->toContain('server error');

    $httpIssue = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'http_error')
        ->first();

    expect($httpIssue)->not->toBeNull();
    expect((string) data_get($httpIssue?->context_json, 'fetch_error_category'))->toBe('server_error');

    $h1MissingCount = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('code', 'h1_missing')
        ->count();

    expect($h1MissingCount)->toBe(0);
});

it('classifies publishlayer articles, site pages, and system pages', function () {
    [, $site] = makeSeoAuditContext(20);

    $publishLayerUrl = 'https://seo-audit.example.com/pl-article';
    $publishLayerKey = AnalyticsUrlKey::fromUrl($publishLayerUrl);

    $content = Content::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'PublishLayer Article',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'published_url' => $publishLayerUrl,
        'publish_url_key' => $publishLayerKey,
        'canonical_url_key' => $publishLayerKey,
    ]);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><head><title>Home</title><meta name="description" content="Home desc"><link rel="canonical" href="https://seo-audit.example.com/"></head><body><h1>Home</h1><a href="/pl-article">PL</a><a href="/about">About</a><a href="/wp-admin/tools.php">Admin</a></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/pl-article' => Http::response('<html><head><title>PL</title><meta name="description" content="PL desc"><link rel="canonical" href="https://seo-audit.example.com/pl-article"></head><body><h1>PL Article</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/about' => Http::response('<html><head><title>About</title><meta name="description" content="About desc"><link rel="canonical" href="https://seo-audit.example.com/about"></head><body><h1>About</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/wp-admin/tools.php' => Http::response('<html><head><title>Admin</title></head><body><h1>Admin</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 4));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');

    $plPage = SeoAuditPage::query()
        ->where('seo_audit_id', $audit->id)
        ->where('url', 'https://seo-audit.example.com/pl-article')
        ->first();
    expect($plPage)->not->toBeNull();
    expect($plPage->page_type)->toBe('publishlayer_article');
    expect((string) $plPage->publishlayer_article_id)->toBe((string) $content->id);

    $sitePage = SeoAuditPage::query()
        ->where('seo_audit_id', $audit->id)
        ->where('url', 'https://seo-audit.example.com/about')
        ->first();
    expect($sitePage)->not->toBeNull();
    expect($sitePage->page_type)->toBe('site_page');

    $systemPage = SeoAuditPage::query()
        ->where('seo_audit_id', $audit->id)
        ->where('url', 'https://seo-audit.example.com/wp-admin/tools.php')
        ->first();
    expect($systemPage)->not->toBeNull();
    expect($systemPage->page_type)->toBe('system_page');

    $systemIssuesCount = SeoAuditIssue::query()
        ->where('seo_audit_id', $audit->id)
        ->where('seo_audit_page_id', $systemPage->id)
        ->count();

    expect($systemIssuesCount)->toBe(0);
});

it('classifies publishlayer article when only published_url is available on content', function () {
    [, $site] = makeSeoAuditContext(10);

    $content = Content::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'PublishLayer Article (Published URL only)',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'published_url' => '/pl-article',
        'publish_url_key' => null,
        'canonical_url_key' => null,
    ]);

    Http::fake([
        'https://seo-audit.example.com/sitemap.xml' => Http::response('not found', 404),
        'https://seo-audit.example.com/' => Http::response('<html><head><title>Home</title><meta name="description" content="Home desc"><link rel="canonical" href="https://seo-audit.example.com/"></head><body><h1>Home</h1><a href="/pl-article">PL</a></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://seo-audit.example.com/pl-article' => Http::response('<html><head><title>PL</title><meta name="description" content="PL desc"><link rel="canonical" href="https://seo-audit.example.com/pl-article"></head><body><h1>PL Article</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    Bus::dispatchSync(new RunSeoAuditJob((string) $site->id, 2));

    $audit = SeoAudit::query()->where('client_site_id', $site->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->status)->toBe('completed');

    $plPage = SeoAuditPage::query()
        ->where('seo_audit_id', $audit->id)
        ->where('url', 'https://seo-audit.example.com/pl-article')
        ->first();
    expect($plPage)->not->toBeNull();
    expect($plPage->page_type)->toBe('publishlayer_article');
    expect((string) $plPage->publishlayer_article_id)->toBe((string) $content->id);
});
