<?php

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Jobs\PageIntelligence\GeneratePageIntelligenceReportArtifactJob;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\MarketPack;
use App\Models\MarketPackInstallation;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageAlert;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\RecommendedAction;
use App\Models\SignalEvent;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\PageIntelligence\Reports\PageIntelligenceReportArtifactGenerator;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('generates a weekly intelligence briefing snapshot', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    expect($report)->toBeInstanceOf(PageIntelligenceReport::class)
        ->and($report->report_type)->toBe(ReportBuilder::TYPE_WEEKLY)
        ->and($report->snapshot_version)->toBe(1)
        ->and($report->identity_hash)->not->toBeEmpty()
        ->and($report->artifact_type)->toBe(PageIntelligenceReport::ARTIFACT_TYPE_PDF)
        ->and($report->artifact_status)->toBe(PageIntelligenceReport::ARTIFACT_STATUS_PENDING)
        ->and($report->artifact_checksum)->toBeNull()
        ->and($report->artifact_source_checksum)->not->toBeEmpty()
        ->and(data_get($report->payload_json, 'label'))->toBe('Weekly Intelligence Briefing')
        ->and(data_get($report->payload_json, 'export.ready'))->toBeTrue()
        ->and(data_get($report->payload_json, 'provenance.direct_fetching'))->toBeFalse();
});

it('includes opportunities and risks in generated reports', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    expect(data_get($report->payload_json, 'sections.top_opportunities'))->not->toBeEmpty()
        ->and(data_get($report->payload_json, 'sections.top_risks'))->not->toBeEmpty()
        ->and(data_get($report->payload_json, 'sections.recommended_actions'))->not->toBeEmpty();
});

it('includes SERP and GEO movement sections', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_VISIBILITY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $serpMovement = data_get($report->payload_json, 'sections.serp_movements.0');
    $geoMovement = data_get($report->payload_json, 'sections.geo_ai_visibility_movements.0');

    expect($serpMovement)->not->toBeNull()
        ->and($serpMovement['position_delta'])->toBe(5)
        ->and($geoMovement)->not->toBeNull()
        ->and($geoMovement['score_delta'])->toBe(35);
});

it('links report evidence back to monitored pages', function (): void {
    [$workspace, $user, $page] = pageIntelligenceReportScenario();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $links = collect(data_get($report->payload_json, 'evidence_links', []));

    expect($links)->not->toBeEmpty()
        ->and($links->pluck('page_id')->contains($page->id))->toBeTrue()
        ->and($links->pluck('url')->implode(' '))->toContain($page->id);
});

it('generates market-pack-aware report summaries', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario(marketPack: true);

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_MONTHLY, [
        'market_pack_key' => 'automotive',
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    expect(data_get($report->payload_json, 'market_pack.key'))->toBe('automotive')
        ->and(data_get($report->payload_json, 'sections.market_pack_summary.active'))->toBeTrue()
        ->and(data_get($report->payload_json, 'sections.market_pack_summary.summary'))->toContain('Automotive');
});

it('fails clearly when a market pack key is not installed for the workspace', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();

    app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_MONTHLY, [
        'market_pack_key' => 'unknown-pack',
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);
})->throws(InvalidArgumentException::class, 'Market pack [unknown-pack] is not installed');

it('allocates snapshot versions safely and enforces idempotent request keys', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();
    $builder = app(ReportBuilder::class);
    $options = [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ];

    $first = $builder->generate($workspace, ReportBuilder::TYPE_WEEKLY, $options + ['idempotency_key' => 'request-1'], $user);
    $retry = $builder->generate($workspace, ReportBuilder::TYPE_WEEKLY, $options + ['idempotency_key' => 'request-1'], $user);
    $second = $builder->generate($workspace, ReportBuilder::TYPE_WEEKLY, $options + ['idempotency_key' => 'request-2'], $user);

    expect($retry->id)->toBe($first->id)
        ->and($first->snapshot_version)->toBe(1)
        ->and($second->snapshot_version)->toBe(2)
        ->and(PageIntelligenceReport::query()->where('identity_hash', $first->identity_hash)->pluck('snapshot_version')->sort()->values()->all())->toBe([1, 2]);
});

it('captures audit-grade provenance source ids and fingerprints', function (): void {
    [$workspace, $user, $page] = pageIntelligenceReportScenario();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $provenance = $report->provenance_json;

    expect(data_get($provenance, 'source_row_ids.monitored_pages'))->toContain($page->id)
        ->and(data_get($provenance, 'source_row_ids.page_snapshots'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_content_extractions'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_scores'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_pr_values'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_serp_observations'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_geo_observations'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.page_alerts'))->not->toBeEmpty()
        ->and(data_get($provenance, 'source_row_ids.signal_events'))->not->toBeEmpty()
        ->and(data_get($provenance, 'scorer_versions.page_scores'))->toContain(PageIntelligenceScoreCalculator::MODEL_VERSION)
        ->and(data_get($provenance, 'template_version'))->toBe(ReportBuilder::TEMPLATE_VERSION)
        ->and(data_get($provenance, 'generated_by'))->toBe($user->id)
        ->and(data_get($provenance, 'data_fingerprint'))->not->toBeEmpty();
});

it('renders the reports overview generate action and detail page', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.reports.index', ['workspace' => $workspace->id]))
        ->assertOk()
        ->assertSee('Page Intelligence Reports')
        ->assertSee('Weekly Intelligence Briefing')
        ->assertSee('Generate');

    $response = $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.reports.store'), [
            'workspace' => $workspace->id,
            'report_type' => ReportBuilder::TYPE_WEEKLY,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-08',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $report = PageIntelligenceReport::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $response->assertRedirect(route('app.page-intelligence.reports.show', $report));

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.reports.show', $report))
        ->assertOk()
        ->assertSee('Executive Summary')
        ->assertSee('Delivery Status')
        ->assertSee('Evidence Links')
        ->assertSee('Data Provenance')
        ->assertSee('Generate PDF');

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.reports.export', $report))
        ->assertOk()
        ->assertSee('<main>', false)
        ->assertSee('Data Provenance')
        ->assertDontSee('data-app-sidebar');
});

it('creates a durable PDF artifact for a report snapshot', function (): void {
    Storage::fake('local');
    [$workspace, $user] = pageIntelligenceReportScenario();
    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    (new GeneratePageIntelligenceReportArtifactJob((string) $report->id))->handle(app(PageIntelligenceReportArtifactGenerator::class));

    $report->refresh();

    $bytes = Storage::disk('local')->get($report->artifact_storage_path);

    expect($report->artifact_status)->toBe(PageIntelligenceReport::ARTIFACT_STATUS_READY)
        ->and($report->artifact_storage_path)->not->toBeEmpty()
        ->and($report->artifact_generated_at)->not->toBeNull()
        ->and($report->artifact_attempt_count)->toBe(1)
        ->and($report->artifact_checksum)->toBe(hash('sha256', $bytes))
        ->and($report->artifact_source_checksum)->not->toBeEmpty()
        ->and(Storage::disk('local')->exists($report->artifact_storage_path))->toBeTrue()
        ->and($bytes)->toStartWith('%PDF');
});

it('keeps artifact generation idempotent for the same report snapshot', function (): void {
    Storage::fake('local');
    Carbon::setTestNow('2026-07-06 12:00:00');
    [$workspace, $user] = pageIntelligenceReportScenario();
    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);
    $job = new GeneratePageIntelligenceReportArtifactJob((string) $report->id);

    $job->handle(app(PageIntelligenceReportArtifactGenerator::class));
    $first = $report->refresh();
    $path = $first->artifact_storage_path;
    $checksum = $first->artifact_checksum;
    $sourceChecksum = $first->artifact_source_checksum;
    $attemptCount = $first->artifact_attempt_count;
    $generatedAt = $first->artifact_generated_at?->toIso8601String();

    Carbon::setTestNow('2026-07-06 13:00:00');
    $job->handle(app(PageIntelligenceReportArtifactGenerator::class));
    $second = $report->refresh();

    expect($second->artifact_storage_path)->toBe($path)
        ->and($second->artifact_checksum)->toBe($checksum)
        ->and($second->artifact_source_checksum)->toBe($sourceChecksum)
        ->and($second->artifact_attempt_count)->toBe($attemptCount)
        ->and($second->artifact_generated_at?->toIso8601String())->toBe($generatedAt);

    Carbon::setTestNow();
});

it('records artifact failure metadata when PDF rendering fails', function (): void {
    Storage::fake('local');
    [$workspace, $user] = pageIntelligenceReportScenario();
    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    Pdf::shouldReceive('setOption')->once();
    Pdf::shouldReceive('loadView')->once()->andThrow(new RuntimeException('PDF render broke.'));

    expect(fn () => (new GeneratePageIntelligenceReportArtifactJob((string) $report->id))->handle(app(PageIntelligenceReportArtifactGenerator::class)))
        ->toThrow(RuntimeException::class, 'PDF render broke');

    $report->refresh();

    expect($report->artifact_status)->toBe(PageIntelligenceReport::ARTIFACT_STATUS_FAILED)
        ->and($report->artifact_failed_at)->not->toBeNull()
        ->and($report->artifact_error)->toContain('PDF render broke')
        ->and($report->artifact_attempt_count)->toBe(1)
        ->and($report->artifact_checksum)->toBeNull()
        ->and($report->artifact_source_checksum)->not->toBeEmpty();
});

it('does not enqueue duplicate manual artifact jobs while generation is pending', function (): void {
    Bus::fake([GeneratePageIntelligenceReportArtifactJob::class]);
    [$workspace, $user] = pageIntelligenceReportScenario();
    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.reports.artifact.generate', $report))
        ->assertRedirect();

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->post(route('app.page-intelligence.reports.artifact.generate', $report))
        ->assertRedirect();

    Bus::assertDispatchedTimes(GeneratePageIntelligenceReportArtifactJob::class, 1);
    expect($report->refresh()->artifact_status)->toBe(PageIntelligenceReport::ARTIFACT_STATUS_GENERATING);
});

it('guards report artifact downloads with policy authorization', function (): void {
    Storage::fake('local');
    [$workspace, $user] = pageIntelligenceReportScenario();
    [, $otherUser] = pageIntelligenceReportWorkspace('Other Artifact Workspace');
    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);
    $path = 'page-intelligence/reports/test.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\n%%EOF\n");
    $report->forceFill([
        'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_READY,
        'artifact_storage_path' => $path,
        'artifact_generated_at' => now(),
    ])->save();

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($user)
        ->get(route('app.page-intelligence.reports.artifact.download', $report))
        ->assertOk()
        ->assertDownload();

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($otherUser)
        ->get(route('app.page-intelligence.reports.artifact.download', $report))
        ->assertNotFound();
});

it('keeps report detail pages tenant safe', function (): void {
    [$workspace, $user] = pageIntelligenceReportScenario();
    [, $otherUser] = pageIntelligenceReportWorkspace('Other Report Workspace');

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $this->withoutMiddleware(pageIntelligenceReportDisabledMiddleware())
        ->actingAs($otherUser)
        ->get(route('app.page-intelligence.reports.show', $report))
        ->assertNotFound();
});

it('keeps report generation query counts bounded when alerts have recommended actions', function (): void {
    [$workspace, $user, $page] = pageIntelligenceReportScenario();
    $action = RecommendedAction::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'source_type' => RecommendedAction::SOURCE_AI_VISIBILITY,
        'source_id' => $page->id,
        'source_signature' => 'report-action-'.$page->id,
        'source_group' => 'page_intelligence',
        'action_type' => 'review',
        'status' => RecommendedAction::STATUS_OPEN,
        'title' => 'Review customer briefing risk',
        'summary' => 'Review the risk before sharing.',
        'estimated_effort' => RecommendedAction::EFFORT_LOW,
        'priority_score' => 80,
        'confidence_score' => 80,
        'expected_impact_score' => 80,
    ]);
    PageAlert::query()->where('workspace_id', $workspace->id)->update(['recommended_action_id' => $action->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $report = app(ReportBuilder::class)->generate($workspace, ReportBuilder::TYPE_WEEKLY, [
        'period_start' => Carbon::parse('2026-07-01'),
        'period_end' => Carbon::parse('2026-07-08'),
    ], $user);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(data_get($report->payload_json, 'sections.top_risks.0.recommended_action'))->toBe('Review customer briefing risk')
        ->and(count($queries))->toBeLessThan(80);
});

/**
 * @return array{0:Workspace,1:User,2:MonitoredPage}
 */
function pageIntelligenceReportScenario(bool $marketPack = false): array
{
    [$workspace, $user] = pageIntelligenceReportWorkspace();
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Report Site',
        'site_url' => 'https://reports.example.com',
        'allowed_domains' => ['reports.example.com'],
        'is_active' => true,
    ]);
    $source = MonitoredSource::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'source_type' => 'manual',
        'name' => 'Report Source',
        'base_url' => 'https://reports.example.com',
        'domain' => 'reports.example.com',
        'status' => MonitoredSource::STATUS_ACTIVE,
        'authority_score' => 92,
    ]);
    $url = 'https://reports.example.com/customer-briefing';
    $page = MonitoredPage::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_source_id' => $source->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => 'reports.example.com',
        'path' => '/customer-briefing',
        'source_type' => 'manual',
        'page_type' => 'article',
        'title_current' => 'Customer Briefing Evidence',
        'first_seen_at' => Carbon::parse('2026-07-02 09:00:00'),
        'last_seen_at' => Carbon::parse('2026-07-06 09:00:00'),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_FETCHED,
        'metadata_json' => [],
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'fetched_at' => Carbon::parse('2026-07-06 09:15:00'),
    ]);
    PageContentExtraction::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'extraction_method' => 'test',
        'extractor_version' => 'test-extractor-v1',
        'title' => 'Customer Briefing Evidence',
        'language' => 'en',
        'summary' => 'Extracted customer briefing evidence.',
        'main_text' => 'Customer briefing evidence for report generation.',
        'main_text_hash' => hash('sha256', 'Customer briefing evidence for report generation.'),
        'word_count' => 7,
    ]);

    PageScore::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
        'score_type' => PageIntelligenceScoreCalculator::SCORE_TYPE,
        'score' => 91,
        'previous_score' => 80,
        'delta' => 11,
        'score_version' => PageIntelligenceScoreCalculator::MODEL_VERSION,
        'calculation_method' => 'deterministic',
        'model_used' => PageIntelligenceScoreCalculator::MODEL_KEY,
        'explanation' => 'High opportunity page.',
        'breakdown_json' => ['pr_value' => 88],
        'evidence_json' => [],
        'computed_at' => Carbon::parse('2026-07-06 10:00:00'),
        'metadata_json' => ['confidence' => 89],
    ]);

    PageSentiment::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
        'target_type' => 'page',
        'target_key' => 'page:'.$page->id,
        'target_name' => $page->title_current,
        'compound_score' => -0.42,
        'label' => 'negative',
        'confidence_score' => 0.91,
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'explanation' => 'Negative framing in market coverage.',
        'evidence_json' => [],
        'analyzed_at' => Carbon::parse('2026-07-06 11:00:00'),
    ]);

    PageAlert::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'severity' => 'high',
        'title' => 'High-risk page detected',
        'summary' => 'A high-risk page needs customer review.',
        'fired_at' => Carbon::parse('2026-07-06 12:00:00'),
    ]);

    PagePrValue::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'test',
        'score' => 88,
        'estimated_value_amount' => 12500,
        'currency' => 'EUR',
        'confidence' => 90,
        'breakdown_json' => ['authority' => 92],
        'calculated_at' => Carbon::parse('2026-07-06 13:00:00'),
    ]);

    foreach ([[8, 45, '2026-07-02 09:00:00'], [3, 81, '2026-07-06 09:00:00']] as [$position, $score, $observedAt]) {
        PageSerpObservation::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'query' => 'customer intelligence briefing',
            'query_hash' => hash('sha256', 'customer intelligence briefing'),
            'position' => $position,
            'absolute_position' => $position,
            'visibility_score' => $score,
            'competitor_presence_json' => [['domain' => 'competitor.example', 'position' => 1]],
            'observed_at' => Carbon::parse($observedAt),
        ]);
    }

    foreach ([[40, false, '2026-07-02 10:00:00'], [75, true, '2026-07-06 10:00:00']] as [$score, $clientCited, $observedAt]) {
        PageGeoObservation::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'query' => 'best briefing platform',
            'query_hash' => hash('sha256', 'best briefing platform'),
            'geo_visibility_score' => $score,
            'client_cited' => $clientCited,
            'competitors_cited' => ! $clientCited,
            'mentioned_competitors_json' => $clientCited ? [] : [['term' => 'Competitor']],
            'observed_at' => Carbon::parse($observedAt),
        ]);
    }

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Competitor Example',
        'domain' => 'competitor.example',
        'is_active' => true,
    ]);
    PageCompetitorMatch::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
        'site_competitor_id' => $competitor->id,
        'match_type' => 'mention',
        'match_score' => 87,
        'evidence_json' => ['snippet' => 'Competitor Example appeared in the page.'],
        'observed_at' => Carbon::parse('2026-07-06 14:00:00'),
    ]);

    $campaign = Campaign::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'Customer Proof Campaign',
    ]);
    PageCampaignMatch::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
        'campaign_id' => $campaign->id,
        'match_type' => 'topic',
        'match_score' => 84,
        'evidence_json' => ['snippet' => 'Campaign topic matched.'],
        'observed_at' => Carbon::parse('2026-07-06 15:00:00'),
    ]);

    SignalEvent::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'category' => SignalCategory::BRAND_VISIBILITY,
        'type' => SignalType::BRAND_MENTIONED,
        'severity' => SignalSeverity::INFO,
        'status' => SignalStatus::DETECTED,
        'topic' => 'Briefings',
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 74,
        'confidence_score' => 82,
        'impact_score' => 61,
        'urgency_score' => 44,
        'observed_at' => Carbon::parse('2026-07-06 16:00:00'),
        'evidence' => [['label' => 'Report scenario']],
        'metrics' => ['mentions' => 1],
        'metadata' => ['source' => 'report_test'],
        'dedupe_hash' => hash('sha256', $page->id.'report-signal'),
    ]);

    if ($marketPack) {
        $pack = MarketPack::query()->create([
            'key' => 'automotive',
            'name' => 'Automotive',
            'description' => 'Automotive market pack',
            'market_category' => 'automotive',
            'status' => MarketPack::STATUS_ACTIVE,
            'version' => '1.0',
            'locale' => 'en',
            'defaults_json' => [],
            'metadata_json' => [],
        ]);
        MarketPackInstallation::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'market_pack_id' => $pack->id,
            'status' => MarketPackInstallation::STATUS_ACTIVE,
            'installed_at' => Carbon::parse('2026-07-01 08:00:00'),
        ]);
        $pack->themes()->create([
            'key' => 'ev',
            'name' => 'EV Launches',
            'weight' => 1,
            'keywords_json' => ['ev'],
        ]);
        $pack->competitors()->create([
            'key' => 'tesla',
            'name' => 'Tesla',
            'domain' => 'tesla.example',
            'aliases_json' => ['Tesla'],
        ]);
        PageMarketPackMatch::query()->create(pageIntelligenceReportAnalysisAttributes($page, $snapshot) + [
            'market_pack_key' => 'automotive',
            'market_pack_name' => 'Automotive',
            'match_type' => 'theme',
            'match_score' => 93,
            'evidence_json' => ['keyword' => 'ev'],
            'observed_at' => Carbon::parse('2026-07-06 17:00:00'),
        ]);
    }

    return [$workspace, $user, $page];
}

/**
 * @return array{0:Workspace,1:User}
 */
function pageIntelligenceReportWorkspace(string $name = 'Report Workspace'): array
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
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $user];
}

function pageIntelligenceReportAnalysisAttributes(MonitoredPage $page, PageSnapshot $snapshot): array
{
    return [
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'page_content_extraction_id' => PageContentExtraction::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->value('id'),
    ];
}

function pageIntelligenceReportDisabledMiddleware(): array
{
    return [
        \App\Http\Middleware\SetAppLocale::class,
        \App\Http\Middleware\EnsureSupportModeContext::class,
        \App\Http\Middleware\DenyWriteActionsInSupportMode::class,
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
        \App\Http\Middleware\ProtectHeavyEndpoints::class,
    ];
}
