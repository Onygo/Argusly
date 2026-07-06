<?php

use App\Models\AlertRule;
use App\Models\ClientSite;
use App\Models\MarketPack;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageMarketPackMatch;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\MarketPacks\MarketPackInstaller;
use App\Services\PageIntelligence\Matching\PageMarketPackMatcher;
use App\Services\PageIntelligence\PageAnalysisService;
use Database\Seeders\MarketPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('installs the Automotive operational market pack from its manifest', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace] = operationalMarketPackWorkspace('Automotive Manifest');
    $installation = app(MarketPackInstaller::class)->install($workspace, 'automotive');

    expect($installation->marketPack->key)->toBe('automotive')
        ->and(MarketPack::query()->where('key', 'automotive')->firstOrFail()->metadata_json['manifest'])->toBe('database/market-packs/automotive.php');
});

it('installs the Telecom operational market pack from its manifest', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace] = operationalMarketPackWorkspace('Telecom Manifest');
    $installation = app(MarketPackInstaller::class)->install($workspace, 'telecom');

    expect($installation->marketPack->key)->toBe('telecom')
        ->and(MarketPack::query()->where('key', 'telecom')->firstOrFail()->metadata_json['manifest'])->toBe('database/market-packs/telecom.php');
});

it('creates operational sources site competitors themes and alert rules for an installed pack', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace] = operationalMarketPackWorkspace('Automotive Operational');
    $site = operationalMarketPackSite($workspace);
    app(MarketPackInstaller::class)->install($workspace, 'automotive', $site);
    $pack = MarketPack::query()->where('key', 'automotive')->firstOrFail();

    expect(MonitoredSource::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'automotive')->count())->toBeGreaterThanOrEqual(3)
        ->and(SiteCompetitor::query()->where('workspace_id', $workspace->id)->where('client_site_id', $site->id)->count())->toBeGreaterThanOrEqual(3)
        ->and($pack->themes()->count())->toBeGreaterThanOrEqual(3)
        ->and(AlertRule::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'automotive')->count())->toBeGreaterThanOrEqual(3);
});

it('filters monitored pages by market pack source or match', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace, $user] = operationalMarketPackWorkspace('Market Pack Filter');
    app(MarketPackInstaller::class)->install($workspace, 'automotive');
    app(MarketPackInstaller::class)->install($workspace, 'telecom');

    $automotiveSource = MonitoredSource::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'automotive')->firstOrFail();
    $telecomSource = MonitoredSource::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'telecom')->firstOrFail();

    operationalMarketPackPage($workspace, $automotiveSource, 'Automotive Branch Page');
    operationalMarketPackPage($workspace, $telecomSource, 'Telecom Branch Page');

    expect(MonitoredPage::query()
        ->where('workspace_id', $workspace->id)
        ->whereHas('source', fn ($source) => $source->where('metadata_json->market_pack_key', 'automotive'))
        ->count())->toBe(1);

    $this->withoutMiddleware(operationalMarketPackDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.index', [
            'workspace' => $workspace->id,
            'tab' => 'pages',
            'market_pack' => 'automotive',
        ]))
        ->assertOk()
        ->assertSee('Automotive Branch Page')
        ->assertDontSee('Telecom Branch Page');
});

it('classifies relevant pages with installed pack themes and market pack matches', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace] = operationalMarketPackWorkspace('Automotive Classification');
    app(MarketPackInstaller::class)->install($workspace, 'automotive');
    $source = MonitoredSource::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'automotive')->firstOrFail();
    [$page, $snapshot] = operationalMarketPackExtractedPage($workspace, $source, 'Automotive EV Launch', 'The EV launch expands electric vehicle availability and connected car services.');

    app(PageAnalysisService::class)->classifyTopics($snapshot);
    app(PageMarketPackMatcher::class)->match($page->refresh());

    expect(PageTopic::query()
        ->where('monitored_page_id', $page->id)
        ->where('source_type', 'market_pack')
        ->where('topic_name', 'Electric vehicles')
        ->exists())->toBeTrue()
        ->and(PageMarketPackMatch::query()
            ->where('monitored_page_id', $page->id)
            ->where('market_pack_key', 'automotive')
            ->exists())->toBeTrue();
});

it('uses installed pack competitors as deterministic competitor entity candidates', function (): void {
    $this->seed(MarketPackSeeder::class);

    [$workspace] = operationalMarketPackWorkspace('Automotive Competitors');
    app(MarketPackInstaller::class)->install($workspace, 'automotive');
    $source = MonitoredSource::query()->where('workspace_id', $workspace->id)->where('metadata_json->market_pack_key', 'automotive')->firstOrFail();
    [, $snapshot] = operationalMarketPackExtractedPage($workspace, $source, 'Tesla Coverage', 'Tesla announced a fleet software update for connected mobility customers.');

    app(PageAnalysisService::class)->analyzeEntities($snapshot);

    expect(PageEntity::query()
        ->where('workspace_id', $workspace->id)
        ->where('entity_type', PageEntity::TYPE_COMPETITOR)
        ->where('entity_name', 'Tesla')
        ->where('source_type', 'market_pack_competitor')
        ->exists())->toBeTrue();
});

function operationalMarketPackWorkspace(string $name): array
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

function operationalMarketPackSite(Workspace $workspace): ClientSite
{
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'name' => $workspace->name.' Site',
        'site_url' => 'https://'.$workspace->id.'.example.test',
        'base_url' => 'https://'.$workspace->id.'.example.test',
        'allowed_domains' => [$workspace->id.'.example.test'],
        'type' => ClientSite::TYPE_WORDPRESS,
        'is_active' => true,
    ]);
}

function operationalMarketPackPage(Workspace $workspace, MonitoredSource $source, string $title): MonitoredPage
{
    $url = 'https://'.$source->domain.'/'.str($title)->slug().'-'.str()->random(6);

    return MonitoredPage::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $source->client_site_id,
        'monitored_source_id' => $source->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => $source->domain,
        'path' => parse_url($url, PHP_URL_PATH),
        'source_type' => $source->source_type,
        'page_type' => 'article',
        'title_current' => $title,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_DISCOVERED,
        'metadata_json' => [],
    ]);
}

function operationalMarketPackExtractedPage(Workspace $workspace, MonitoredSource $source, string $title, string $mainText): array
{
    $page = operationalMarketPackPage($workspace, $source, $title);
    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => $title,
        'summary' => $mainText,
        'main_text' => $mainText,
        'main_text_hash' => hash('sha256', $mainText),
        'main_text_bytes' => strlen($mainText),
        'main_text_preview' => $mainText,
    ]);

    return [$page, $snapshot, $extraction];
}

function operationalMarketPackDisabledMiddleware(): array
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
