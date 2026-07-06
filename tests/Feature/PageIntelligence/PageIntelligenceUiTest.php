<?php

use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('loads the Page Intelligence monitored pages index', function (): void {
    [$workspace, $user] = pageIntelligenceUiWorkspace();
    pageIntelligenceUiPage($workspace, ['title_current' => 'Dashboard Page']);

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', ['workspace' => $workspace->id]))
        ->assertOk()
        ->assertSee('Page Intelligence')
        ->assertSee('Monitored Pages')
        ->assertSee('Dashboard Page');
});

it('filters monitored pages by source type', function (): void {
    [$workspace, $user] = pageIntelligenceUiWorkspace();
    pageIntelligenceUiPage($workspace, ['title_current' => 'RSS Filtered Page', 'source_type' => 'rss', 'domain' => 'rss.example.com']);
    pageIntelligenceUiPage($workspace, ['title_current' => 'SERP Hidden Page', 'source_type' => 'serp', 'domain' => 'serp.example.com']);

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', [
            'workspace' => $workspace->id,
            'source_type' => 'rss',
        ]))
        ->assertOk()
        ->assertSee('RSS Filtered Page')
        ->assertDontSee('SERP Hidden Page');
});

it('opens the monitored page detail drawer from the index', function (): void {
    [$workspace, $user] = pageIntelligenceUiWorkspace();
    $page = pageIntelligenceUiPage($workspace, ['title_current' => 'Drawer Evidence Page']);
    $snapshot = PageSnapshot::factory()->forPage($page, 1)->create(['http_status' => 200]);
    PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Drawer Evidence Page',
        'summary' => 'Drawer-ready extraction summary.',
        'extraction_method' => 'deterministic',
        'extractor_version' => 'test',
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'monitored_page_id' => $page->id,
        'query' => 'drawer serp query',
        'visibility_score' => 81,
    ]);
    PageGeoObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'monitored_page_id' => $page->id,
        'query' => 'drawer geo query',
        'geo_visibility_score' => 76,
    ]);

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', [
            'workspace' => $workspace->id,
            'drawer' => $page->id,
        ]))
        ->assertOk()
        ->assertSee('Drawer Evidence Page')
        ->assertSee('Latest extraction')
        ->assertSee('SERP visibility')
        ->assertSee('GEO visibility')
        ->assertSee('data-drawer="monitored-page.inspect"', false);
});

it('shows Intelligence Score on the monitored pages index and drawer', function (): void {
    [$workspace, $user] = pageIntelligenceUiWorkspace('Score UI Workspace');
    $page = pageIntelligenceUiPage($workspace, ['title_current' => 'Scored Drawer Page']);
    $snapshot = PageSnapshot::factory()->forPage($page, 1)->create();
    PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Scored Drawer Page',
        'summary' => 'Score drawer extraction summary.',
    ]);

    PageScore::query()->create(pageIntelligenceUiAnalysisAttributes($page, $snapshot) + [
        'score_type' => PageIntelligenceScoreCalculator::SCORE_TYPE,
        'score' => 91.25,
        'score_version' => PageIntelligenceScoreCalculator::MODEL_VERSION,
        'calculation_method' => 'deterministic_composite',
        'model_used' => PageIntelligenceScoreCalculator::MODEL_KEY,
        'explanation' => 'Argusly Intelligence Score combines page value signals.',
        'breakdown_json' => [
            'components' => [
                'pr_value' => ['score' => 88, 'weight' => 0.14, 'available' => true],
                'geo_visibility' => ['score' => null, 'weight' => 0.07, 'available' => false],
            ],
        ],
        'evidence_json' => [],
        'computed_at' => now(),
        'metadata_json' => [
            'model_key' => PageIntelligenceScoreCalculator::MODEL_KEY,
            'model_version' => PageIntelligenceScoreCalculator::MODEL_VERSION,
            'confidence' => 83.33,
            'missing_inputs' => ['geo_visibility'],
        ],
    ]);

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', [
            'workspace' => $workspace->id,
            'drawer' => $page->id,
        ]))
        ->assertOk()
        ->assertSee('Intelligence')
        ->assertSee('91.3')
        ->assertSee('Intelligence Score breakdown')
        ->assertSee('Geo Visibility');
});

it('blocks users from viewing monitored pages in another organization', function (): void {
    [$workspace] = pageIntelligenceUiWorkspace('Owner Workspace');
    $page = pageIntelligenceUiPage($workspace, ['title_current' => 'Private Page']);
    [, $otherUser] = pageIntelligenceUiWorkspace('Other Workspace');

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($otherUser)
        ->get(route('app.page-intelligence.monitored-pages.show', $page))
        ->assertNotFound();
});

it('renders the monitored page index without N+1 query growth', function (): void {
    [$workspace, $user] = pageIntelligenceUiWorkspace('N Plus One Workspace');

    $createPageWithEvidence = function (int $index) use ($workspace): void {
        $page = pageIntelligenceUiPage($workspace, ['title_current' => 'N1 Page '.$index]);
        $snapshot = PageSnapshot::factory()->forPage($page, $index)->create();
        $extraction = PageContentExtraction::query()->create([
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'extraction_method' => 'test',
            'extractor_version' => 'test',
            'title' => $page->title_current,
            'summary' => 'Summary '.$index,
            'main_text' => 'A monitored page summary for query count verification.',
            'word_count' => 8,
            'quality_score' => 80,
        ]);
        PageSentiment::query()->create(pageIntelligenceUiAnalysisAttributes($page, $snapshot) + [
            'page_content_extraction_id' => $extraction->id,
            'target_type' => PageSentiment::TARGET_PAGE,
            'target_key' => 'page:'.$page->id,
            'target_name' => $page->title_current,
            'compound_score' => 0.2,
            'label' => 'neutral',
            'confidence_score' => 0.7,
            'analysis_method' => 'deterministic',
            'model_used' => 'lexicon',
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);
        PagePrValue::query()->create(pageIntelligenceUiAnalysisAttributes($page, $snapshot) + [
            'page_content_extraction_id' => $extraction->id,
            'model_key' => 'argusly',
            'model_version' => '1.0',
            'score' => 70 + $index,
            'estimated_value_amount' => 1000,
            'currency' => 'EUR',
            'confidence' => 0.7,
            'breakdown_json' => ['source_authority' => 0.7],
            'calculated_at' => now(),
        ]);
        PageAlert::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'monitored_page_id' => $page->id,
        ]);
    };

    $createPageWithEvidence(1);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', ['workspace' => $workspace->id]))
        ->assertOk();

    $baselineQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(2, 8) as $index) {
        $createPageWithEvidence($index);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->withoutMiddleware(pageIntelligenceUiDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', ['workspace' => $workspace->id]))
        ->assertOk();

    $expandedQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expandedQueryCount)->toBeLessThanOrEqual($baselineQueryCount + 12);
});

function pageIntelligenceUiWorkspace(string $name = 'Page Intelligence UI Workspace'): array
{
    $organization = Organization::query()->create([
        'name' => $name.' Organization',
        'slug' => str($name)->slug().'-'.str()->random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => $name,
        'display_name' => $name,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $user];
}

function pageIntelligenceUiDisabledMiddleware(): array
{
    return [
        \App\Http\Middleware\SetAppLocale::class,
        \App\Http\Middleware\EnsureSupportModeContext::class,
        \App\Http\Middleware\DenyWriteActionsInSupportMode::class,
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ];
}

function pageIntelligenceUiPage(Workspace $workspace, array $attributes = []): MonitoredPage
{
    $sourceType = (string) ($attributes['source_type'] ?? 'manual');
    $domain = (string) ($attributes['domain'] ?? 'ui.example.com');
    $source = MonitoredSource::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'source_type' => $sourceType,
        'name' => str($sourceType)->headline().' Source '.str()->random(4),
        'base_url' => 'https://'.$domain,
        'domain' => $domain,
        'status' => MonitoredSource::STATUS_ACTIVE,
    ]);
    $slug = str((string) ($attributes['title_current'] ?? 'ui page'))->slug().'-'.str()->random(6);
    $url = 'https://'.$domain.'/'.$slug;

    return MonitoredPage::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_source_id' => $source->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'domain' => $domain,
        'path' => parse_url($url, PHP_URL_PATH),
        'source_type' => $sourceType,
        'page_type' => 'article',
        'title_current' => 'UI Page',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_DISCOVERED,
        'metadata_json' => [],
    ], $attributes));
}

function pageIntelligenceUiAnalysisAttributes(MonitoredPage $page, PageSnapshot $snapshot): array
{
    return [
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
    ];
}
