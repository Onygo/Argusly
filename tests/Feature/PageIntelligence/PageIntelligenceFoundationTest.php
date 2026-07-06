<?php

use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageContentExtractor;
use App\Services\PageIntelligence\PageFetcher;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function pageIntelligenceWorkspace(string $slug): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Page Intelligence Test Organization '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Page Intelligence Test Workspace '.$slug,
        'display_name' => 'Page Intelligence Test Workspace '.$slug,
    ]);
}

it('creates the canonical page intelligence schema', function (): void {
    foreach ([
        'monitored_sources',
        'monitored_pages',
        'page_snapshots',
        'page_content_extractions',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(Schema::hasColumn($table, 'id'))->toBeTrue();
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
    }

    expect(Schema::hasColumns('monitored_sources', [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'source_type',
        'name',
        'base_url',
        'domain',
        'status',
        'crawl_policy_json',
        'fetch_config_json',
        'discovery_config_json',
    ]))->toBeTrue();

    expect(Schema::hasColumns('monitored_pages', [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_source_id',
        'canonical_url',
        'canonical_url_hash',
        'first_seen_url',
        'final_url',
        'domain',
        'path',
        'source_type',
        'page_type',
        'language_current',
        'title_current',
        'published_at_current',
        'first_seen_at',
        'last_seen_at',
        'last_fetched_at',
        'last_changed_at',
        'crawl_status',
        'metadata_json',
    ]))->toBeTrue();

    expect(Schema::hasColumns('page_snapshots', [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_page_id',
        'snapshot_number',
        'requested_url',
        'final_url',
        'canonical_url',
        'http_status',
        'content_type',
        'response_headers_json',
        'redirect_chain_json',
        'raw_html_path',
        'raw_html',
        'raw_html_hash',
        'text_hash',
        'content_changed',
        'canonical_conflict',
        'fetch_duration_ms',
        'fetched_at',
        'fetcher_version',
        'error_code',
        'error_message',
    ]))->toBeTrue();

    expect(Schema::hasColumns('page_content_extractions', [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'monitored_page_id',
        'page_snapshot_id',
        'title',
        'meta_description',
        'headings_json',
        'author',
        'publisher',
        'published_at',
        'language',
        'summary',
        'main_text',
        'word_count',
        'quality_score',
        'structured_data_json',
        'images_json',
        'media_json',
        'outbound_links_json',
        'metadata_json',
    ]))->toBeTrue();
});

it('casts json date and boolean fields and links source page snapshot and extraction models', function (): void {
    $source = MonitoredSource::factory()->create([
        'crawl_policy_json' => ['respect_robots' => true],
        'fetch_config_json' => ['timeout_seconds' => 5],
        'discovery_config_json' => ['adapter' => 'rss'],
    ]);

    $page = MonitoredPage::factory()->create([
        'organization_id' => $source->organization_id,
        'workspace_id' => $source->workspace_id,
        'client_site_id' => $source->client_site_id,
        'monitored_source_id' => $source->id,
        'source_type' => $source->source_type,
        'metadata_json' => ['canonical_layer' => true],
    ]);

    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'snapshot_number' => 1,
        'response_headers_json' => ['etag' => 'abc123'],
        'redirect_chain_json' => [['from' => $page->first_seen_url, 'to' => $page->final_url]],
        'content_changed' => true,
        'canonical_conflict' => false,
    ]);

    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'headings_json' => [['level' => 1, 'text' => 'Canonical Page Intelligence']],
        'structured_data_json' => [['@type' => 'NewsArticle']],
        'images_json' => [['src' => 'https://example.com/news.jpg']],
        'media_json' => [['type' => 'video']],
        'outbound_links_json' => [['href' => 'https://source.example']],
        'metadata_json' => ['extractor' => 'test'],
        'quality_score' => 92.25,
    ]);

    expect($source->crawl_policy_json)->toBe(['respect_robots' => true]);
    expect($source->fetch_config_json)->toBe(['timeout_seconds' => 5]);
    expect($source->discovery_config_json)->toBe(['adapter' => 'rss']);
    expect($page->metadata_json)->toBe(['canonical_layer' => true]);
    expect($snapshot->response_headers_json)->toBe(['etag' => 'abc123']);
    expect($snapshot->redirect_chain_json)->toBe([['from' => $page->first_seen_url, 'to' => $page->final_url]]);
    expect($snapshot->content_changed)->toBeTrue();
    expect($snapshot->canonical_conflict)->toBeFalse();
    expect($extraction->headings_json)->toBe([['level' => 1, 'text' => 'Canonical Page Intelligence']]);
    expect($extraction->structured_data_json)->toBe([['@type' => 'NewsArticle']]);
    expect($extraction->images_json)->toBe([['src' => 'https://example.com/news.jpg']]);
    expect($extraction->media_json)->toBe([['type' => 'video']]);
    expect($extraction->outbound_links_json)->toBe([['href' => 'https://source.example']]);
    expect($extraction->quality_score)->toBe('92.25');
    expect($page->first_seen_at)->not->toBeNull();
    expect($snapshot->fetched_at)->not->toBeNull();
    expect($extraction->published_at)->not->toBeNull();

    expect($source->pages()->whereKey($page->id)->exists())->toBeTrue();
    expect($page->source()->is($source))->toBeTrue();
    expect($page->snapshots()->whereKey($snapshot->id)->exists())->toBeTrue();
    expect($snapshot->page()->is($page))->toBeTrue();
    expect($snapshot->contentExtraction()->whereKey($extraction->id)->exists())->toBeTrue();
    expect($extraction->snapshot()->is($snapshot))->toBeTrue();
    expect($extraction->page()->is($page))->toBeTrue();
});

it('enforces canonical page uniqueness per workspace', function (): void {
    $workspace = pageIntelligenceWorkspace('page-intelligence-dedupe');
    $url = 'https://news.example.com/articles/argusly-launch';
    $hash = hash('sha256', $url);

    MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'canonical_url' => $url,
        'canonical_url_hash' => $hash,
        'first_seen_url' => $url,
        'first_seen_url_hash' => $hash,
        'domain' => 'news.example.com',
        'source_type' => 'manual',
    ]);

    expect(fn () => MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'canonical_url' => $url,
        'canonical_url_hash' => $hash,
        'first_seen_url' => $url.'?duplicate=1',
        'first_seen_url_hash' => hash('sha256', $url.'?duplicate=1'),
        'domain' => 'news.example.com',
        'source_type' => 'manual',
    ]))->toThrow(QueryException::class);

    $otherWorkspace = pageIntelligenceWorkspace('page-intelligence-dedupe-other');

    MonitoredPage::factory()->create([
        'organization_id' => $otherWorkspace->organization_id,
        'workspace_id' => $otherWorkspace->id,
        'canonical_url' => $url,
        'canonical_url_hash' => $hash,
        'first_seen_url' => $url,
        'first_seen_url_hash' => $hash,
        'domain' => 'news.example.com',
        'source_type' => 'manual',
    ]);

    expect(MonitoredPage::query()->where('canonical_url_hash', $hash)->count())->toBe(2);
});

it('versions snapshots per monitored page', function (): void {
    $page = MonitoredPage::factory()->create();
    $otherPage = MonitoredPage::factory()->create();

    PageSnapshot::factory()->forPage($page, 1)->create();
    PageSnapshot::factory()->forPage($page, 2)->create();
    PageSnapshot::factory()->forPage($otherPage, 1)->create();

    expect($page->snapshots()->orderBy('snapshot_number')->pluck('snapshot_number')->all())->toBe([1, 2]);
    expect($otherPage->snapshots()->pluck('snapshot_number')->all())->toBe([1]);

    expect(fn () => PageSnapshot::factory()->forPage($page, 2)->create())->toThrow(QueryException::class);
});

it('keeps extraction one to one with a snapshot', function (): void {
    $snapshot = PageSnapshot::factory()->create();

    PageContentExtraction::factory()->forSnapshot($snapshot)->create();

    expect(fn () => PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Duplicate extraction',
    ]))->toThrow(QueryException::class);
});

it('soft deletes the page intelligence foundation records', function (): void {
    $source = MonitoredSource::factory()->create();
    $page = MonitoredPage::factory()->create([
        'organization_id' => $source->organization_id,
        'workspace_id' => $source->workspace_id,
        'monitored_source_id' => $source->id,
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create();

    $source->delete();
    $page->delete();
    $snapshot->delete();
    $extraction->delete();

    expect(MonitoredSource::query()->find($source->id))->toBeNull();
    expect(MonitoredPage::query()->find($page->id))->toBeNull();
    expect(PageSnapshot::query()->find($snapshot->id))->toBeNull();
    expect(PageContentExtraction::query()->find($extraction->id))->toBeNull();

    expect(MonitoredSource::withTrashed()->find($source->id))->not->toBeNull();
    expect(MonitoredPage::withTrashed()->find($page->id))->not->toBeNull();
    expect(PageSnapshot::withTrashed()->find($snapshot->id))->not->toBeNull();
    expect(PageContentExtraction::withTrashed()->find($extraction->id))->not->toBeNull();
});

it('dedupes the same submitted url when only tracking parameters differ', function (): void {
    $workspace = pageIntelligenceWorkspace('page-intelligence-submit-dedupe');
    $action = app(SubmitMonitoredPageAction::class);

    Carbon::setTestNow(Carbon::parse('2026-07-03 10:00:00'));
    $created = $action->execute(
        $workspace,
        'https://Example.com/news/Launch/?utm_source=newsletter&utm_campaign=launch&keep=1',
        sourceType: 'manual',
    );

    Carbon::setTestNow(Carbon::parse('2026-07-03 11:15:00'));
    $updated = $action->execute(
        $workspace,
        'https://example.com/news/Launch?keep=1&utm_medium=social',
        sourceType: 'manual',
    );

    expect($created->created)->toBeTrue();
    expect($updated->created)->toBeFalse();
    expect($updated->page->id)->toBe($created->page->id);
    expect(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
    expect($updated->page->canonical_url)->toBe('https://example.com/news/Launch?keep=1');
    expect($updated->page->first_seen_url)->toBe('https://example.com/news/Launch?keep=1&utm_campaign=launch&utm_source=newsletter');
});

it('keeps different canonical domains separate', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', [
        'news.example.com' => ['93.184.216.34'],
        'blog.example.com' => ['93.184.216.34'],
    ]);

    $workspace = pageIntelligenceWorkspace('page-intelligence-submit-domains');
    $action = app(SubmitMonitoredPageAction::class);

    $first = $action->execute($workspace, 'https://news.example.com/story?utm_source=x');
    $second = $action->execute($workspace, 'https://blog.example.com/story?utm_source=x');

    expect($first->created)->toBeTrue();
    expect($second->created)->toBeTrue();
    expect($first->page->id)->not->toBe($second->page->id);
    expect(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
});

it('does not change first seen timestamp and updates last seen timestamp on resubmission', function (): void {
    $workspace = pageIntelligenceWorkspace('page-intelligence-submit-timestamps');
    $action = app(SubmitMonitoredPageAction::class);

    Carbon::setTestNow(Carbon::parse('2026-07-03 09:00:00'));
    $created = $action->execute($workspace, 'https://example.com/research/page?utm_source=a');

    Carbon::setTestNow(Carbon::parse('2026-07-03 13:30:00'));
    $updated = $action->execute($workspace, 'https://example.com/research/page?utm_source=b');

    expect($updated->page->id)->toBe($created->page->id);
    expect($updated->page->first_seen_at?->toDateTimeString())->toBe('2026-07-03 09:00:00');
    expect($updated->page->last_seen_at?->toDateTimeString())->toBe('2026-07-03 13:30:00');
});

it('rejects invalid private and internal urls', function (string $url): void {
    $workspace = pageIntelligenceWorkspace('page-intelligence-submit-rejects-'.md5($url));

    expect(fn () => app(SubmitMonitoredPageAction::class)->execute($workspace, $url))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'invalid scheme' => ['ftp://example.com/file'],
    'localhost' => ['http://localhost/private'],
    'loopback ip' => ['http://127.0.0.1/private'],
    'private ip' => ['http://10.0.0.5/private'],
    'local tld' => ['https://cms.local/article'],
    'internal tld' => ['https://cms.internal/article'],
]);

it('submits a monitored page from the artisan command', function (): void {
    $workspace = pageIntelligenceWorkspace('page-intelligence-submit-command');

    $this->artisan('page-intelligence:submit-url', [
        'url' => 'https://example.com/news/command?utm_source=test',
        '--workspace' => $workspace->id,
        '--source-type' => 'manual',
        '--page-type' => 'news_article',
    ])
        ->expectsOutput('Monitored page created.')
        ->expectsOutput('Canonical URL: https://example.com/news/command')
        ->expectsOutput('State: created')
        ->assertExitCode(0);

    $page = MonitoredPage::query()
        ->where('workspace_id', $workspace->id)
        ->where('domain', 'example.com')
        ->firstOrFail();

    expect($page->page_type)->toBe('news_article');
    expect($page->source_type)->toBe('manual');

    $this->artisan('page-intelligence:submit-url', [
        'url' => 'https://example.com/news/command?utm_campaign=again',
        '--workspace' => $workspace->id,
    ])
        ->expectsOutput('Monitored page updated.')
        ->expectsOutput('State: updated')
        ->assertExitCode(0);

    expect(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('fetches a monitored page and creates a versioned snapshot', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/news/fetch',
        'canonical_url_hash' => hash('sha256', 'https://example.com/news/fetch'),
        'first_seen_url' => 'https://example.com/news/fetch',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/news/fetch'),
        'final_url' => 'https://example.com/news/fetch',
        'final_url_hash' => hash('sha256', 'https://example.com/news/fetch'),
        'domain' => 'example.com',
        'path' => '/news/fetch',
        'crawl_status' => MonitoredPage::CRAWL_STATUS_DISCOVERED,
    ]);
    $html = '<html><body><h1>Fetch evidence</h1></body></html>';

    Http::fake([
        'https://example.com/news/fetch' => Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Test' => 'snapshot',
        ]),
    ]);

    $result = app(PageFetcher::class)->fetch($page);
    $snapshot = $result->snapshot;

    expect($result->successful)->toBeTrue();
    expect($snapshot->snapshot_number)->toBe(1);
    expect($snapshot->requested_url)->toBe('https://example.com/news/fetch');
    expect($snapshot->final_url)->toBe('https://example.com/news/fetch');
    expect($snapshot->http_status)->toBe(200);
    expect($snapshot->content_type)->toContain('text/html');
    expect($snapshot->raw_html)->toBeNull();
    expect($snapshot->raw_html_path)->not->toBeNull();
    expect($snapshot->raw_html_preview)->toContain('Fetch evidence');
    expect($snapshot->raw_html_hash)->toBe(hash('sha256', $html));
    expect($snapshot->content_changed)->toBeTrue();
    expect($snapshot->error_code)->toBeNull();
    expect($result->page->crawl_status)->toBe(MonitoredPage::CRAWL_STATUS_FETCHED);
    expect($result->page->last_fetched_at)->not->toBeNull();
});

it('marks repeated unchanged fetches as not changed', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/news/unchanged',
        'canonical_url_hash' => hash('sha256', 'https://example.com/news/unchanged'),
        'first_seen_url' => 'https://example.com/news/unchanged',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/news/unchanged'),
        'final_url' => 'https://example.com/news/unchanged',
        'final_url_hash' => hash('sha256', 'https://example.com/news/unchanged'),
        'domain' => 'example.com',
        'path' => '/news/unchanged',
    ]);
    $html = '<html><body>Stable page evidence.</body></html>';

    Http::fake([
        'https://example.com/news/unchanged' => Http::sequence()
            ->push($html, 200, ['Content-Type' => 'text/html'])
            ->push($html, 200, ['Content-Type' => 'text/html']),
    ]);

    $first = app(PageFetcher::class)->fetch($page);
    $second = app(PageFetcher::class)->fetch($page->refresh());

    expect($first->snapshot->content_changed)->toBeTrue();
    expect($second->snapshot->snapshot_number)->toBe(2);
    expect($second->snapshot->raw_html_hash)->toBe($first->snapshot->raw_html_hash);
    expect($second->snapshot->content_changed)->toBeFalse();
});

it('marks changed html as changed on the next snapshot', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/news/changed',
        'canonical_url_hash' => hash('sha256', 'https://example.com/news/changed'),
        'first_seen_url' => 'https://example.com/news/changed',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/news/changed'),
        'final_url' => 'https://example.com/news/changed',
        'final_url_hash' => hash('sha256', 'https://example.com/news/changed'),
        'domain' => 'example.com',
        'path' => '/news/changed',
    ]);

    Http::fake([
        'https://example.com/news/changed' => Http::sequence()
            ->push('<html><body>First version.</body></html>', 200, ['Content-Type' => 'text/html'])
            ->push('<html><body>Second version.</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $first = app(PageFetcher::class)->fetch($page);
    Carbon::setTestNow(Carbon::parse('2026-07-03 16:00:00'));
    $second = app(PageFetcher::class)->fetch($page->refresh());

    expect($second->snapshot->snapshot_number)->toBe(2);
    expect($second->snapshot->raw_html_hash)->not->toBe($first->snapshot->raw_html_hash);
    expect($second->snapshot->content_changed)->toBeTrue();
    expect($second->page->last_changed_at?->toDateTimeString())->toBe('2026-07-03 16:00:00');
});

it('stores diagnostics when a fetch fails', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/news/fails',
        'canonical_url_hash' => hash('sha256', 'https://example.com/news/fails'),
        'first_seen_url' => 'https://example.com/news/fails',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/news/fails'),
        'final_url' => 'https://example.com/news/fails',
        'final_url_hash' => hash('sha256', 'https://example.com/news/fails'),
        'domain' => 'example.com',
        'path' => '/news/fails',
    ]);

    Http::fake([
        'https://example.com/news/fails' => Http::response('Server error', 500, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $result = app(PageFetcher::class)->fetch($page);

    expect($result->successful)->toBeFalse();
    expect($result->snapshot->snapshot_number)->toBe(1);
    expect($result->snapshot->http_status)->toBe(500);
    expect($result->snapshot->error_code)->toBe('PAGE_FETCH_SERVER_ERROR');
    expect($result->snapshot->error_message)->toContain('HTTP 500');
    expect($result->snapshot->raw_html)->toBeNull();
    expect($result->snapshot->content_changed)->toBeFalse();
    expect($result->page->crawl_status)->toBe(MonitoredPage::CRAWL_STATUS_FAILED);
});

it('blocks private and local monitored page fetch urls', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'http://127.0.0.1/private',
        'canonical_url_hash' => hash('sha256', 'http://127.0.0.1/private'),
        'first_seen_url' => 'http://127.0.0.1/private',
        'first_seen_url_hash' => hash('sha256', 'http://127.0.0.1/private'),
        'final_url' => 'http://127.0.0.1/private',
        'final_url_hash' => hash('sha256', 'http://127.0.0.1/private'),
        'domain' => '127.0.0.1',
        'path' => '/private',
    ]);

    Http::fake();

    $result = app(PageFetcher::class)->fetch($page);

    expect($result->successful)->toBeFalse();
    expect($result->snapshot->error_code)->toBe('PAGE_FETCH_BLOCKED');
    expect($result->snapshot->http_status)->toBeNull();
    expect($result->page->crawl_status)->toBe(MonitoredPage::CRAWL_STATUS_FAILED);

    Http::assertNothingSent();
});

it('enforces source-level allow policy during fetch', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', [
        'allowed.example' => ['93.184.216.34'],
        'denied.example' => ['93.184.216.34'],
    ]);
    $source = MonitoredSource::factory()->create([
        'crawl_policy_json' => ['allow_domains' => ['allowed.example']],
    ]);
    $page = MonitoredPage::factory()->create([
        'workspace_id' => $source->workspace_id,
        'organization_id' => $source->organization_id,
        'monitored_source_id' => $source->id,
        'canonical_url' => 'https://denied.example/news',
        'canonical_url_hash' => hash('sha256', 'https://denied.example/news'),
        'first_seen_url' => 'https://denied.example/news',
        'first_seen_url_hash' => hash('sha256', 'https://denied.example/news'),
        'final_url' => 'https://denied.example/news',
        'final_url_hash' => hash('sha256', 'https://denied.example/news'),
        'domain' => 'denied.example',
    ]);

    Http::fake();

    $result = app(PageFetcher::class)->fetch($page);

    expect($result->successful)->toBeFalse()
        ->and($result->snapshot->error_code)->toBe('PAGE_FETCH_BLOCKED');
    Http::assertNothingSent();
});

it('validates redirect targets against source-level allow policy', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', [
        'allowed.example' => ['93.184.216.34'],
        'denied.example' => ['93.184.216.34'],
    ]);
    $source = MonitoredSource::factory()->create([
        'crawl_policy_json' => ['allow_domains' => ['allowed.example']],
    ]);
    $page = MonitoredPage::factory()->create([
        'workspace_id' => $source->workspace_id,
        'organization_id' => $source->organization_id,
        'monitored_source_id' => $source->id,
        'canonical_url' => 'https://allowed.example/news',
        'canonical_url_hash' => hash('sha256', 'https://allowed.example/news'),
        'first_seen_url' => 'https://allowed.example/news',
        'first_seen_url_hash' => hash('sha256', 'https://allowed.example/news'),
        'final_url' => 'https://allowed.example/news',
        'final_url_hash' => hash('sha256', 'https://allowed.example/news'),
        'domain' => 'allowed.example',
    ]);

    Http::fake([
        'https://allowed.example/news' => Http::response('<html>Moved</html>', 200, [
            'Content-Type' => 'text/html',
            'X-Guzzle-Redirect-History' => 'https://denied.example/news',
        ]),
    ]);

    $result = app(PageFetcher::class)->fetch($page);

    expect($result->successful)->toBeFalse()
        ->and($result->snapshot->error_code)->toBe('PAGE_FETCH_REDIRECT_BLOCKED');
});

it('fetches a monitored page from the artisan command synchronously', function (): void {
    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/news/command-fetch',
        'canonical_url_hash' => hash('sha256', 'https://example.com/news/command-fetch'),
        'first_seen_url' => 'https://example.com/news/command-fetch',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/news/command-fetch'),
        'final_url' => 'https://example.com/news/command-fetch',
        'final_url_hash' => hash('sha256', 'https://example.com/news/command-fetch'),
        'domain' => 'example.com',
        'path' => '/news/command-fetch',
    ]);

    Http::fake([
        'https://example.com/news/command-fetch' => Http::response('<html>Command fetch.</html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $this->artisan('page-intelligence:fetch', [
        'monitoredPageId' => $page->id,
        '--sync' => true,
    ])
        ->expectsOutput('Monitored page fetch changed.')
        ->expectsOutput('Snapshot number: 1')
        ->expectsOutput('Content changed: yes')
        ->assertExitCode(0);
});

it('extracts basic article metadata from a page snapshot', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <!doctype html>
        <html lang="en">
            <head>
                <title>Argusly launches Page Intelligence</title>
                <meta name="description" content="Argusly introduces durable page monitoring evidence.">
                <meta name="author" content="Rhea Signals">
                <meta property="og:site_name" content="Argusly Newsroom">
                <meta property="article:published_time" content="2026-07-03T10:15:00+00:00">
                <link rel="canonical" href="https://example.com/news/page-intelligence">
                <script type="application/ld+json">{"@type":"NewsArticle","headline":"Argusly launches Page Intelligence"}</script>
            </head>
            <body>
                <article>
                    <h1>Argusly launches Page Intelligence</h1>
                    <p>Argusly now stores durable page evidence for monitoring teams and communications workflows.</p>
                    <p>The page intelligence layer helps teams compare versions, cite sources, and keep external page assets reusable.</p>
                    <h2>Monitoring foundation</h2>
                    <p>Every fetched page snapshot keeps normalized evidence for later extraction and review.</p>
                    <h3>Evidence model</h3>
                    <p>The model stays reusable for news, blogs, competitor pages, and campaign pages.</p>
                    <img src="/images/page-intelligence.jpg" alt="Page Intelligence">
                    <a href="https://example.com/about">Internal about</a>
                    <a href="https://source.example/report">External report</a>
                </article>
            </body>
        </html>
    HTML);

    $result = app(PageContentExtractor::class)->extract($snapshot);

    expect($result->created)->toBeTrue();
    expect($result->extraction->title)->toBe('Argusly launches Page Intelligence');
    expect($result->extraction->meta_description)->toBe('Argusly introduces durable page monitoring evidence.');
    expect($result->extraction->h1)->toBe('Argusly launches Page Intelligence');
    expect($result->extraction->author)->toBe('Rhea Signals');
    expect($result->extraction->publisher)->toBe('Argusly Newsroom');
    expect($result->extraction->published_at?->toDateString())->toBe('2026-07-03');
    expect($result->extraction->language)->toBe('en');
    expect($result->extraction->structured_data_json[0]['@type'])->toBe('NewsArticle');
    expect($result->extraction->images_json[0]['src'])->toBe('https://example.com/images/page-intelligence.jpg');
    expect($result->extraction->internal_links_json[0]['href'])->toBe('https://example.com/about');
    expect($result->extraction->outbound_links_json[0]['href'])->toBe('https://source.example/report');
});

it('extracts main text from snapshot html', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <html lang="en"><body>
            <nav>This navigation should not dominate extraction.</nav>
            <main>
                <h1>Competitor campaign page</h1>
                <p>This campaign page explains the product launch and the customer problem in practical language.</p>
                <p>Teams can inspect the campaign claims, compare changes, and preserve the source text for review.</p>
            </main>
        </body></html>
    HTML, 'https://example.com/campaign');

    $extraction = app(PageContentExtractor::class)->extract($snapshot)->extraction;

    $mainText = $extraction->mainTextForAnalysis();

    expect($mainText)->toContain('This campaign page explains the product launch');
    expect($mainText)->toContain('preserve the source text for review');
    expect($mainText)->not->toContain('This navigation should not dominate extraction');
    expect($extraction->word_count)->toBeGreaterThan(15);
    expect($extraction->char_count)->toBe(mb_strlen($mainText));
    expect($extraction->estimated_tokens)->toBeGreaterThan(0);
});

it('handles missing metadata gracefully during extraction', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <html><body>
            <article>
                <p>A short page can still become normalized extraction evidence.</p>
                <p>The extractor stores available text and leaves absent metadata empty.</p>
            </article>
        </body></html>
    HTML, 'https://example.com/plain');

    $extraction = app(PageContentExtractor::class)->extract($snapshot)->extraction;

    expect($extraction->title)->toBeNull();
    expect($extraction->meta_description)->toBeNull();
    expect($extraction->author)->toBeNull();
    expect($extraction->published_at)->toBeNull();
    expect($extraction->mainTextForAnalysis())->toContain('normalized extraction evidence');
    expect($extraction->quality_score)->not->toBeNull();
});

it('updates monitored page current fields and canonical evidence from extraction', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <html lang="nl">
            <head>
                <title>Nieuwe monitoring pagina</title>
                <meta property="article:published_time" content="2026-07-02T09:00:00+00:00">
                <link rel="canonical" href="https://example.com/nieuws/monitoring">
            </head>
            <body>
                <article>
                    <h1>Nieuwe monitoring pagina</h1>
                    <p>Dit is een uitgebreide Nederlandse tekst met veel woorden voor betrouwbare detectie.</p>
                    <p>De pagina beschrijft hoe teams bronnen volgen en veranderingen beoordelen.</p>
                </article>
            </body>
        </html>
    HTML, 'https://example.com/nieuws/monitoring?utm_source=test');

    $result = app(PageContentExtractor::class)->extract($snapshot);
    $page = $result->page;

    expect($page->title_current)->toBe('Nieuwe monitoring pagina');
    expect($page->language_current)->toBe('nl');
    expect($page->published_at_current?->toDateString())->toBe('2026-07-02');
    expect($page->canonical_url)->toBe('https://example.com/nieuws/monitoring');
    expect($result->snapshot->canonical_conflict)->toBeFalse();
});

it('stores extraction quality metrics', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <html lang="en">
            <head><meta name="description" content="A useful evidence page for quality metrics."></head>
            <body>
                <article>
                    <h1>Quality metrics</h1>
                    <h2>Depth</h2>
                    <p>This evidence page has enough words to produce stable normalized metrics for downstream review.</p>
                    <p>It includes headings, paragraphs, image evidence, structured data, and readable main text.</p>
                    <h2>Signals</h2>
                    <p>The extractor should calculate quality and content depth without using AI analysis.</p>
                    <img src="quality.jpg" alt="Quality">
                    <script type="application/ld+json">{"@type":"Article","headline":"Quality metrics"}</script>
                </article>
            </body>
        </html>
    HTML, 'https://example.com/quality');

    $extraction = app(PageContentExtractor::class)->extract($snapshot)->extraction;

    expect((float) $extraction->quality_score)->toBeGreaterThan(0);
    expect((float) $extraction->content_depth_score)->toBeGreaterThan(0);
    expect($extraction->word_count)->toBeGreaterThan(20);
    expect($extraction->char_count)->toBeGreaterThan(100);
    expect($extraction->estimated_tokens)->toBeGreaterThan(20);
    expect($extraction->extraction_method)->not->toBeNull();
    expect($extraction->extractor_version)->toBe('page-content-extractor-v1');
});

it('extracts page content from the artisan command synchronously', function (): void {
    $snapshot = pageIntelligenceSnapshotWithHtml(<<<'HTML'
        <html lang="en"><head><title>Command extraction</title></head><body>
            <article><h1>Command extraction</h1><p>The command can normalize a stored snapshot.</p></article>
        </body></html>
    HTML, 'https://example.com/command-extract');

    $this->artisan('page-intelligence:extract', [
        'pageSnapshotId' => $snapshot->id,
        '--sync' => true,
    ])
        ->expectsOutput('Page content extraction created.')
        ->expectsOutput('Title: Command extraction')
        ->assertExitCode(0);
});

function pageIntelligenceSnapshotWithHtml(string $html, string $url = 'https://example.com/news/page-intelligence'): PageSnapshot
{
    $canonical = preg_replace('/\\?.*$/', '', $url) ?: $url;
    $page = MonitoredPage::factory()->create([
        'canonical_url' => $canonical,
        'canonical_url_hash' => hash('sha256', $canonical),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => (string) parse_url($canonical, PHP_URL_HOST),
        'path' => (string) parse_url($canonical, PHP_URL_PATH),
        'title_current' => null,
        'language_current' => null,
        'published_at_current' => null,
    ]);

    return PageSnapshot::factory()->forPage($page)->create([
        'snapshot_number' => 1,
        'requested_url' => $url,
        'final_url' => $url,
        'canonical_url' => $canonical,
        'raw_html' => $html,
        'raw_html_path' => null,
        'raw_html_hash' => hash('sha256', $html),
        'content_changed' => true,
        'error_code' => null,
        'error_message' => null,
    ]);
}
