<?php

use App\Http\Controllers\App\AppMonitoredPageController;
use App\Jobs\PageIntelligence\EvaluatePageAlertRulesJob;
use App\Models\AlertRule;
use App\Models\ClientSite;
use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\PageScore;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\SerpQuery;
use App\Models\SerpQuerySet;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\PageIntelligence\Serp\ImportSerpObservationsAction;
use App\Services\PageIntelligence\Serp\RecordSerpObservationAction;
use App\Services\PageIntelligence\Serp\SerpObservationResult;
use App\Services\PageIntelligence\Serp\SerpProviderRegistry;
use App\Services\PageIntelligence\Alerts\PageAlertRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates SERP query sets with durable queries', function (): void {
    $querySet = SerpQuerySet::factory()->create(['name' => 'Commercial SERP Set']);
    $query = SerpQuery::factory()->create([
        'organization_id' => $querySet->organization_id,
        'workspace_id' => $querySet->workspace_id,
        'client_site_id' => $querySet->client_site_id,
        'serp_query_set_id' => $querySet->id,
        'query' => 'best media monitoring software',
        'keyword_intent' => 'commercial',
    ]);

    expect($querySet->queries()->count())->toBe(1)
        ->and($query->query_hash)->toBe(hash('sha256', 'best media monitoring software'))
        ->and($query->querySet->is($querySet))->toBeTrue();
});

it('records imported SERP observations through the manual provider', function (): void {
    $workspace = serpTrackingWorkspace();
    $querySet = SerpQuerySet::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'name' => 'Brand SERP Imports',
    ]);

    $observations = app(ImportSerpObservationsAction::class)->execute($workspace, 'manual', [
        'query' => 'argusly page intelligence',
        'country' => 'US',
        'results' => [[
            'page_url' => 'https://example.com/page-intelligence',
            'position' => 2,
            'title' => 'Page Intelligence',
            'serp_features' => ['featured_snippet'],
        ]],
    ], querySet: $querySet);

    $observation = $observations->first();

    expect(app(SerpProviderRegistry::class)->has('manual'))->toBeTrue()
        ->and($observations)->toHaveCount(1)
        ->and($observation)->toBeInstanceOf(PageSerpObservation::class)
        ->and($observation->serp_query_set_id)->toBe($querySet->id)
        ->and($observation->serp_query_id)->not->toBeNull()
        ->and($observation->provider_key)->toBe('manual');
});

it('dispatches alert evaluation after SERP imports commit', function (): void {
    Queue::fake([EvaluatePageAlertRulesJob::class]);
    $workspace = serpTrackingWorkspace();

    app(ImportSerpObservationsAction::class)->execute($workspace, 'manual', [
        'query' => 'argusly after commit alerts',
        'country' => 'US',
        'results' => [[
            'page_url' => 'https://example.com/after-commit-alerts',
            'position' => 4,
        ]],
    ]);

    Queue::assertPushed(
        EvaluatePageAlertRulesJob::class,
        fn (EvaluatePageAlertRulesJob $job): bool => $job->afterCommit === true
    );
});

it('fires top 10 gained alerts from SERP-only imports', function (): void {
    $workspace = serpTrackingWorkspace();
    AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_TOP_10_GAIN,
        'conditions_json' => ['window_minutes' => 1440],
    ]);

    app(ImportSerpObservationsAction::class)->execute($workspace, 'manual', [
        'query' => 'argusly serp only alert',
        'country' => 'US',
        'provider_key' => 'manual',
        'observed_at' => Carbon::parse('2026-07-04 10:00:00'),
        'results' => [[
            'page_url' => 'https://example.com/serp-only-alert',
            'position' => 12,
        ]],
    ]);

    app(ImportSerpObservationsAction::class)->execute($workspace, 'manual', [
        'query' => 'argusly serp only alert',
        'country' => 'US',
        'provider_key' => 'manual',
        'observed_at' => Carbon::parse('2026-07-05 10:00:00'),
        'results' => [[
            'page_url' => 'https://example.com/serp-only-alert',
            'position' => 7,
        ]],
    ]);

    app(EvaluatePageAlertRulesJob::class)->handle(app(PageAlertRuleEvaluator::class));

    expect(PageAlert::query()
        ->where('workspace_id', $workspace->id)
        ->where('trigger', AlertRule::TRIGGER_SERP_TOP_10_GAIN)
        ->count())->toBe(1);
});

it('creates and safely links monitored pages for imported result URLs', function (): void {
    $workspace = serpTrackingWorkspace();

    $observation = app(ImportSerpObservationsAction::class)->execute($workspace, 'import', [
        'query' => 'safe serp import',
        'results' => [[
            'page_url' => 'https://example.com/safe-result?utm_source=serp',
            'position' => 4,
        ]],
    ])->first();

    expect($observation->page)->toBeInstanceOf(MonitoredPage::class)
        ->and($observation->page->canonical_url)->toBe('https://example.com/safe-result')
        ->and($observation->page->source_type)->toBe('serp');
});

it('rejects unsafe imported SERP URLs', function (): void {
    $workspace = serpTrackingWorkspace();

    expect(fn () => app(ImportSerpObservationsAction::class)->execute($workspace, 'manual', [
        'query' => 'unsafe import',
        'results' => [[
            'page_url' => 'http://127.0.0.1/admin',
            'position' => 1,
        ]],
    ]))->toThrow(InvalidArgumentException::class);

    expect(PageSerpObservation::query()->where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(MonitoredPage::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('persists explainable visibility score breakdowns and competitor links', function (): void {
    $workspace = serpTrackingWorkspace();
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'manual',
        'name' => 'Example Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);
    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Competitor',
        'domain' => 'competitor.example',
        'is_active' => true,
    ]);

    $observation = app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'media intelligence platform',
        pageUrl: 'https://example.com/media-intelligence',
        resultType: 'featured_snippet',
        position: 1,
        competitorPresence: [['domain' => 'competitor.example', 'position' => 3]],
        searchVolume: 5000,
        keywordIntent: 'commercial',
        providerKey: 'manual',
    ));

    expect((float) $observation->visibility_score)->toBeGreaterThan(0)
        ->and($observation->breakdown_json)->toHaveKey('model')
        ->and(data_get($observation->metadata_json, 'score_input_provenance.serp_visibility_score_model'))->toBe('argusly_serp_visibility_mvp')
        ->and(data_get($observation->competitor_presence_json, '0.linked'))->toBeTrue()
        ->and(data_get($observation->competitor_presence_json, '0.site_competitor_id'))->not->toBeNull();
});

it('fires SERP ranking gain and loss alerts', function (): void {
    $workspace = serpTrackingWorkspace();
    $page = serpTrackingPageWithObservations($workspace, 12, 7);
    $gainRule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_TOP_10_GAIN,
    ]);
    $lossRule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_TOP_10_LOSS,
    ]);

    $gainAlerts = app(PageAlertRuleEvaluator::class)->evaluateRule($gainRule);

    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'serp alert query',
        'query_hash' => hash('sha256', 'serp alert query'),
        'absolute_position' => 14,
        'position' => 14,
        'observed_at' => now()->addMinute(),
    ]);

    $lossAlerts = app(PageAlertRuleEvaluator::class)->evaluateRule($lossRule);

    expect($gainAlerts)->toHaveCount(1)
        ->and($gainAlerts->first()->trigger)->toBe(AlertRule::TRIGGER_SERP_TOP_10_GAIN)
        ->and($lossAlerts)->toHaveCount(1)
        ->and($lossAlerts->first()->trigger)->toBe(AlertRule::TRIGGER_SERP_TOP_10_LOSS);
});

it('fires competitor top 10 and featured snippet alerts', function (): void {
    $workspace = serpTrackingWorkspace();
    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);

    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'featured alert query',
        'query_hash' => hash('sha256', 'featured alert query'),
        'absolute_position' => 4,
        'position' => 4,
        'result_type' => 'organic',
        'serp_features_json' => [],
        'competitor_presence_json' => [],
        'observed_at' => now()->subDay(),
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'featured alert query',
        'query_hash' => hash('sha256', 'featured alert query'),
        'absolute_position' => 2,
        'position' => 2,
        'result_type' => 'featured_snippet',
        'serp_features_json' => ['featured_snippet'],
        'competitor_presence_json' => [['domain' => 'competitor.example', 'position' => 5]],
        'observed_at' => now(),
    ]);

    $competitorRule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_COMPETITOR_TOP_10_GAIN,
    ]);
    $snippetRule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_FEATURED_SNIPPET_GAIN,
    ]);

    expect(app(PageAlertRuleEvaluator::class)->evaluateRule($competitorRule))->toHaveCount(1)
        ->and(app(PageAlertRuleEvaluator::class)->evaluateRule($snippetRule))->toHaveCount(1);
});

it('does not cross-trigger SERP movement alerts across locale and country variants', function (): void {
    $workspace = serpTrackingWorkspace();
    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);
    $rule = AlertRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'trigger' => AlertRule::TRIGGER_SERP_TOP_10_GAIN,
    ]);

    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'localized serp alert',
        'query_hash' => hash('sha256', 'localized serp alert'),
        'locale' => 'en_US',
        'country' => 'US',
        'provider_key' => 'manual',
        'absolute_position' => 12,
        'position' => 12,
        'observed_at' => Carbon::parse('2026-07-04 10:00:00'),
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'localized serp alert',
        'query_hash' => hash('sha256', 'localized serp alert'),
        'locale' => 'en_GB',
        'country' => 'GB',
        'provider_key' => 'manual',
        'absolute_position' => 7,
        'position' => 7,
        'observed_at' => Carbon::parse('2026-07-05 10:00:00'),
    ]);

    expect(app(PageAlertRuleEvaluator::class)->evaluateRule($rule))->toHaveCount(0);
});

it('rejects mismatched SERP query and query set references', function (): void {
    $workspace = serpTrackingWorkspace();
    $querySet = SerpQuerySet::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);
    $otherQuerySet = SerpQuerySet::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);
    $query = SerpQuery::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'serp_query_set_id' => $querySet->id,
        'query' => 'mismatched serp reference',
    ]);

    expect(fn () => app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'mismatched serp reference',
        pageUrl: 'https://example.com/mismatched-reference',
        position: 3,
        providerKey: 'manual',
        serpQuerySetId: (string) $otherQuerySet->id,
        serpQueryId: (string) $query->id,
    )))->toThrow(InvalidArgumentException::class, 'The SERP query does not belong to the selected query set.');
});

it('batches SERP query history observations', function (): void {
    $workspace = serpTrackingWorkspace();
    $querySet = SerpQuerySet::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);

    $queries = SerpQuery::factory()
        ->count(3)
        ->sequence(
            ['query' => 'batched query one'],
            ['query' => 'batched query two'],
            ['query' => 'batched query three'],
        )
        ->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'serp_query_set_id' => $querySet->id,
        ]);

    $queries->each(function (SerpQuery $query) use ($workspace, $querySet): void {
        PageSerpObservation::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'serp_query_set_id' => $querySet->id,
            'serp_query_id' => $query->id,
            'query' => $query->query,
            'query_hash' => $query->query_hash,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $method = new ReflectionMethod(AppMonitoredPageController::class, 'serpQueryHistory');
    $method->setAccessible(true);
    $history = $method->invoke(app(AppMonitoredPageController::class), $workspace, ['serp_query_set' => (string) $querySet->id]);

    $observationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'page_serp_observations'))
        ->count();

    DB::disableQueryLog();

    expect($history)->toHaveCount(3)
        ->and($observationQueries)->toBe(1);
});

it('updates Argusly Intelligence Score when SERP evidence changes', function (): void {
    $workspace = serpTrackingWorkspace();
    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'canonical_url' => 'https://example.com/intelligence-score',
        'canonical_url_hash' => hash('sha256', 'https://example.com/intelligence-score'),
        'first_seen_url' => 'https://example.com/intelligence-score',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/intelligence-score'),
        'domain' => 'example.com',
        'path' => '/intelligence-score',
    ]);
    PageSnapshot::factory()->forPage($page, 1)->create();

    app(RecordSerpObservationAction::class)->execute($workspace, new SerpObservationResult(
        query: 'argusly intelligence score',
        pageUrl: 'https://example.com/intelligence-score',
        position: 2,
        providerKey: 'manual',
    ));

    $score = PageScore::query()
        ->where('workspace_id', $workspace->id)
        ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
        ->first();

    expect($score)->not->toBeNull()
        ->and(data_get($score->breakdown_json, 'components.serp_visibility.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.serp_visibility.source'))->toBe('serp_observations');
});

function serpTrackingWorkspace(): Workspace
{
    config()->set('page_intelligence.safety.dns_overrides', [
        'example.com' => ['93.184.216.34'],
        'competitor.example' => ['93.184.216.34'],
    ]);

    return SerpQuerySet::factory()->create()->workspace;
}

function serpTrackingPageWithObservations(Workspace $workspace, int $previousPosition, int $currentPosition): MonitoredPage
{
    $page = MonitoredPage::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'serp alert query',
        'query_hash' => hash('sha256', 'serp alert query'),
        'absolute_position' => $previousPosition,
        'position' => $previousPosition,
        'observed_at' => Carbon::now()->subDay(),
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'monitored_page_id' => $page->id,
        'query' => 'serp alert query',
        'query_hash' => hash('sha256', 'serp alert query'),
        'absolute_position' => $currentPosition,
        'position' => $currentPosition,
        'observed_at' => Carbon::now(),
    ]);

    return $page;
}
