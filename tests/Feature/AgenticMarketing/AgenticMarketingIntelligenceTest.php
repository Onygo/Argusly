<?php

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketPack;
use App\Models\MarketPackInstallation;
use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Intelligence\MarketingReasoningEngine;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\AgenticMarketing\Intelligence\RecommendationLifecycle;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use App\Services\PageIntelligence\ScoreEngineV2;
use App\Support\Intelligence\TimeWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('generates explainable agentic marketing recommendations without execution', function (): void {
    $context = agenticIntelligenceScenario();

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $compound = collect($snapshot->recommendations)->firstWhere('key', 'opportunity:compound-page-improvement:'.$context['page']->id);

    expect($snapshot->recommendations)->not->toBeEmpty()
        ->and($compound)->toBeInstanceOf(MarketingRecommendation::class)
        ->and($compound->priority)->toBeGreaterThan(60)
        ->and($compound->confidence)->toBeGreaterThan(0.6)
        ->and($compound->metadata['automatic_execution'])->toBeFalse();

    $actions = implode(' ', $compound->recommendedActions);

    expect($actions)->toContain('Improve the affected article')
        ->and($actions)->toContain('LinkedIn')
        ->and($actions)->toContain('PR')
        ->and($actions)->toContain('Expand the topic');
});

it('keeps recommendations tied to observations snapshots scores trends and page intelligence evidence', function (): void {
    $context = agenticIntelligenceScenario();

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $recommendation = collect($snapshot->recommendations)->firstWhere('key', 'opportunity:compound-page-improvement:'.$context['page']->id);

    expect($recommendation->evidence->marketingObservationIds)->toHaveCount(8)
        ->and($recommendation->evidence->pageSnapshotIds)->toContain($context['snapshot']->id)
        ->and($recommendation->evidence->pageScoreIds)->toContain(PageScore::query()->where('monitored_page_id', $context['page']->id)->where('score_version', ScoreEngineV2::MODEL_VERSION)->value('id'))
        ->and($recommendation->evidence->trendIds)->not->toBeEmpty()
        ->and($recommendation->evidence->performanceSignalKeys)->not->toBeEmpty()
        ->and(data_get($recommendation->evidence->pageIntelligenceInputIds, 'page_serp_observations'))->not->toBeEmpty()
        ->and(data_get($recommendation->evidence->pageIntelligenceInputIds, 'page_geo_observations'))->not->toBeEmpty()
        ->and(data_get($recommendation->evidence->pageIntelligenceInputIds, 'page_competitor_matches'))->not->toBeEmpty()
        ->and($recommendation->affectedPages[0]['id'])->toBe($context['page']->id)
        ->and($recommendation->affectedTopics)->toContain('AI Visibility')
        ->and($recommendation->affectedChannels)->toContain('Organic Search')
        ->and($recommendation->affectedCompetitors)->toContain('Visible Rival')
        ->and($recommendation->marketPackContext['key'])->toBe('agentic_saas');
});

it('sorts recommendations by priority and exposes bounded confidence', function (): void {
    $context = agenticIntelligenceScenario();

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $priorities = collect($snapshot->recommendations)->pluck('priority')->all();

    expect($priorities)->toBe(collect($priorities)->sortDesc()->values()->all())
        ->and(collect($snapshot->recommendations)->every(fn (MarketingRecommendation $recommendation): bool => $recommendation->priority >= 0 && $recommendation->priority <= 100))->toBeTrue()
        ->and(collect($snapshot->recommendations)->every(fn (MarketingRecommendation $recommendation): bool => $recommendation->confidence >= 0 && $recommendation->confidence <= 1))->toBeTrue();
});

it('explains conflicting performance signals instead of hiding them', function (): void {
    $context = agenticIntelligenceScenario();

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $conflict = collect($snapshot->insights)->firstWhere('type', 'conflicting_signals');
    $recommendation = collect($snapshot->recommendations)->firstWhere('key', 'opportunity:compound-page-improvement:'.$context['page']->id);

    expect($conflict)->not->toBeNull()
        ->and($conflict->metadata['traffic_growth_percent'])->toBeGreaterThan(0)
        ->and($conflict->metadata['engagement_growth_percent'])->toBeLessThan(0)
        ->and($recommendation->metadata['reasoning_pattern'])->toBe('traffic_rising_engagement_falling_competitor_ai_gap')
        ->and($recommendation->supportingInsightKeys)->toContain($conflict->key);
});

it('handles missing data with a lower confidence measurement recommendation', function (): void {
    [$organization, $workspace, $site] = agenticIntelligenceTenant('agentic-missing');

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $workspace,
        $site,
        ['from' => '2026-07-02', 'to' => '2026-07-02']
    );
    $recommendation = collect($snapshot->recommendations)->firstWhere('type', 'measurement_risk');

    expect($organization)->toBeInstanceOf(Organization::class)
        ->and($snapshot->missingData)->toContain('canonical_marketing_observations', 'intelligence_score_v2', 'page_mapping')
        ->and($recommendation)->not->toBeNull()
        ->and($recommendation->confidence)->toBeLessThan(0.5)
        ->and($recommendation->evidence->marketingObservationIds)->toBeEmpty();
});

it('aggregates provider agnostic observations without provider-specific recommendations', function (): void {
    $context = agenticIntelligenceScenario(withSecondaryConnector: true);

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $payload = json_encode(collect($snapshot->recommendations)->map(fn (MarketingRecommendation $recommendation): array => $recommendation->toArray())->all(), JSON_THROW_ON_ERROR);

    expect($snapshot->evidence->marketingObservationIds)->toHaveCount(12)
        ->and($payload)->not->toContain('canonical_agentic_primary')
        ->and($payload)->not->toContain('canonical_agentic_secondary')
        ->and($payload)->not->toContain('google')
        ->and($payload)->not->toContain('linkedin');
});

it('accepts unified time engine windows for agentic marketing reasoning', function (): void {
    $context = agenticIntelligenceScenario();
    $window = app(TimeWindowResolver::class)->resolve('custom_range', [
        'from' => '2026-07-02',
        'to' => '2026-07-02',
    ]);

    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        [
            'from' => $window->start,
            'to' => $window->end,
            'market_pack_key' => 'agentic_saas',
        ]
    );

    expect($snapshot->periodStart->toDateString())->toBe('2026-07-02')
        ->and($snapshot->periodEnd->toDateString())->toBe('2026-07-02')
        ->and($snapshot->recommendations)->not->toBeEmpty()
        ->and($snapshot->evidence->reportIds)->toContain($context['report']->id)
        ->and($snapshot->toArray())->toHaveKeys([
            'workspace_id',
            'client_site_id',
            'period_start',
            'period_end',
            'insights',
            'recommendations',
            'evidence',
        ]);
});

it('persists recommendations as opportunities and sends a traceable workspace notification', function (): void {
    $context = agenticIntelligenceScenario();
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'Agentic Marketing Intelligence',
        'goal' => 'Turn explainable evidence into recommendations.',
        'locale' => 'en',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
    $snapshot = app(MarketingReasoningEngine::class)->reason(
        $context['workspace'],
        $context['site'],
        ['from' => '2026-07-02', 'to' => '2026-07-02', 'market_pack_key' => 'agentic_saas']
    );
    $lifecycle = app(RecommendationLifecycle::class);

    $result = $lifecycle->persistRecommendations($objective, $snapshot);
    $notification = $lifecycle->notifyWorkspace($context['workspace'], $snapshot, ['force' => true]);

    expect($result['created'])->toBe(count($snapshot->recommendations))
        ->and(AgenticMarketingOpportunity::query()->where('objective_id', $objective->id)->count())->toBe(count($snapshot->recommendations))
        ->and(AgenticMarketingAction::query()->count())->toBe(0)
        ->and($notification)->toBeInstanceOf(WorkspaceNotification::class)
        ->and($notification->type)->toBe(WorkspaceNotification::TYPE_ACTION_REQUIRED)
        ->and(data_get($notification->meta, 'source'))->toBe('agentic_marketing_intelligence')
        ->and(data_get($notification->meta, 'automatic_execution'))->toBeFalse()
        ->and(data_get($notification->meta, 'evidence.report_ids'))->toContain($context['report']->id)
        ->and(data_get($notification->meta, 'evidence.scheduled_briefing_ids'))->toContain($context['briefing']->id)
        ->and(data_get($notification->meta, 'top_recommendation.evidence.marketing_observation_ids'))->not->toBeEmpty();
});

function agenticIntelligenceScenario(bool $withSecondaryConnector = false): array
{
    [$organization, $workspace, $site, $user] = agenticIntelligenceTenant('agentic-intelligence');
    $context = compact('organization', 'workspace', 'site', 'user');
    $context['connector'] = agenticIntelligenceConnector($context, 'canonical_agentic_primary');
    $secondaryConnector = $withSecondaryConnector ? agenticIntelligenceConnector($context, 'canonical_agentic_secondary') : null;
    $pack = MarketPack::query()->create([
        'key' => 'agentic_saas',
        'name' => 'Agentic SaaS',
        'description' => 'Agentic SaaS intelligence pack.',
        'market_category' => 'saas',
        'status' => MarketPack::STATUS_ACTIVE,
        'version' => 'test',
        'locale' => 'en',
        'defaults_json' => [],
        'metadata_json' => [],
    ]);
    MarketPackInstallation::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'market_pack_id' => $pack->id,
        'status' => MarketPackInstallation::STATUS_ACTIVE,
        'installed_at' => Carbon::parse('2026-07-01 09:00:00'),
    ]);
    [$page, $snapshot, $extraction] = agenticIntelligencePage($context, 'https://example.com/agentic-intelligence', '/agentic-intelligence', 'Agentic Intelligence Guide');

    agenticIntelligencePageInputs($page, $snapshot, $extraction);

    foreach ([
        ['sessions', 50, '2026-07-01', 'count'],
        ['sessions', 150, '2026-07-02', 'count'],
        ['engagementRate', 0.82, '2026-07-01', 'ratio'],
        ['engagementRate', 0.28, '2026-07-02', 'ratio'],
        ['impressions', 900, '2026-07-01', 'count'],
        ['impressions', 600, '2026-07-02', 'count'],
        ['ai_visibility_score', 44, '2026-07-01', 'score'],
        ['ai_visibility_score', 30, '2026-07-02', 'score'],
    ] as $row) {
        agenticIntelligenceObservation($context, $row[0], $row[1], $row[2], [
            'pagePath' => '/agentic-intelligence',
            'defaultChannelGroup' => 'Organic Search',
            'topic' => 'AI Visibility',
            'market_pack' => 'agentic_saas',
        ], ['unit' => $row[3]]);
    }

    if ($secondaryConnector) {
        foreach ([
            ['sessions', 20, '2026-07-01', 'count'],
            ['sessions', 45, '2026-07-02', 'count'],
            ['engagementRate', 0.78, '2026-07-01', 'ratio'],
            ['engagementRate', 0.32, '2026-07-02', 'ratio'],
        ] as $row) {
            agenticIntelligenceObservation($context, $row[0], $row[1], $row[2], [
                'pagePath' => '/agentic-intelligence',
                'defaultChannelGroup' => 'Organic Search',
                'topic' => 'AI Visibility',
                'market_pack' => 'agentic_saas',
            ], ['unit' => $row[3], 'connector' => $secondaryConnector]);
        }
    }

    app(ScoreEngineV2::class)->calculate($snapshot, '2026-07-02', '2026-07-02');
    $briefing = ScheduledPageIntelligenceBriefing::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'market_pack_key' => 'agentic_saas',
        'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
        'day_of_week' => 1,
        'timezone' => 'UTC',
        'recipients_json' => ['ops@example.test'],
        'delivery_channels_json' => ['email_placeholder'],
        'delivery_state_json' => [],
        'is_active' => true,
        'next_run_at' => Carbon::parse('2026-07-06 09:00:00'),
        'created_by' => $user->id,
    ]);
    $report = PageIntelligenceReport::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'market_pack_key' => 'agentic_saas',
        'report_type' => ReportBuilder::TYPE_WEEKLY,
        'identity_hash' => hash('sha256', 'agentic-intelligence-report'),
        'title' => 'Agentic SaaS Weekly Intelligence Briefing',
        'status' => PageIntelligenceReport::STATUS_GENERATED,
        'snapshot_version' => 1,
        'template_version' => ReportBuilder::TEMPLATE_VERSION,
        'period_start' => Carbon::parse('2026-07-02')->startOfDay(),
        'period_end' => Carbon::parse('2026-07-02')->endOfDay(),
        'summary' => 'Traffic is rising while engagement needs review.',
        'payload_json' => ['sections' => ['recommended_actions' => []]],
        'provenance_json' => ['source' => 'test'],
        'generated_by' => $user->id,
        'generated_at' => Carbon::parse('2026-07-02 13:00:00'),
        'artifact_type' => PageIntelligenceReport::ARTIFACT_TYPE_PDF,
        'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_PENDING,
        'scheduled_page_intelligence_briefing_id' => $briefing->id,
    ]);

    return $context + compact('pack', 'page', 'snapshot', 'extraction', 'report', 'briefing');
}

function agenticIntelligenceTenant(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => Str::headline($slug).' Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function agenticIntelligenceConnector(array $context, string $providerKey): array
{
    $provider = ConnectorProvider::factory()->create([
        'provider_key' => $providerKey,
        'name' => Str::headline($providerKey),
        'category' => ConnectorProvider::CATEGORY_OTHER,
    ]);
    $account = ConnectorAccount::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => $provider->name.' Account',
        'external_account_id' => $providerKey.'-account',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);
    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => $providerKey.':performance',
        'dataset_type' => 'performance',
        'external_dataset_id' => $providerKey.'-performance',
        'display_name' => $provider->name.' Performance',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'capabilities_json' => ['keys' => ['performance.observations'], 'definitions' => []],
        'sync_config_json' => [],
        'config_json' => [],
        'metadata_json' => [],
    ]);
    $syncRun = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    return compact('provider', 'account', 'dataset', 'syncRun');
}

function agenticIntelligencePage(array $context, string $url, string $path, string $title): array
{
    $source = MonitoredSource::factory()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'source_type' => 'owned',
        'base_url' => 'https://example.com',
        'domain' => 'example.com',
        'authority_score' => 82,
        'trust_level' => 4,
    ]);
    $page = MonitoredPage::factory()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'monitored_source_id' => $source->id,
        'canonical_url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'first_seen_url' => $url,
        'first_seen_url_hash' => hash('sha256', $url),
        'final_url' => $url,
        'final_url_hash' => hash('sha256', $url),
        'domain' => 'example.com',
        'path' => $path,
        'title_current' => $title,
        'published_at_current' => Carbon::parse('2026-06-30'),
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'canonical_url' => $url,
        'final_url' => $url,
        'fetched_at' => Carbon::parse('2026-07-02 12:00:00'),
    ]);
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'content_depth_score' => 76,
        'word_count' => 1300,
    ]);

    return [$page, $snapshot, $extraction];
}

function agenticIntelligencePageInputs(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): void
{
    PagePrValue::query()->create(agenticPageInputAttributes($page, $snapshot, $extraction) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'v1',
        'score' => 88,
        'estimated_value_amount' => 18000,
        'currency' => 'USD',
        'confidence' => 92,
        'breakdown_json' => ['factors' => ['source_authority' => ['score' => 86]]],
        'calculated_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageTopic::query()->create(agenticPageInputAttributes($page, $snapshot, $extraction) + [
        'topic_key' => 'ai_visibility',
        'topic_name' => 'AI Visibility',
        'topic_type' => 'market_pack_theme',
        'source_type' => 'market_pack',
        'mention_count' => 5,
        'prominence_score' => 84,
        'confidence_score' => 90,
        'keywords_json' => ['AI visibility'],
        'evidence_json' => [],
        'classified_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageMarketPackMatch::query()->create(agenticPageInputAttributes($page, $snapshot, $extraction) + [
        'market_pack_key' => 'agentic_saas',
        'market_pack_name' => 'Agentic SaaS',
        'match_type' => 'theme',
        'match_score' => 91,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'name' => 'Visible Rival',
        'domain' => 'visible-rival.example.test',
        'is_active' => true,
    ]);
    PageCompetitorMatch::query()->create(agenticPageInputAttributes($page, $snapshot, $extraction) + [
        'site_competitor_id' => $competitor->id,
        'match_type' => 'topic_overlap',
        'match_score' => 82,
        'evidence_json' => ['reason' => 'High overlap'],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'query' => 'agentic marketing intelligence',
        'visibility_score' => 34,
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageGeoObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'query' => 'agentic marketing intelligence',
        'geo_visibility_score' => 28,
        'competitors_cited' => true,
        'mentioned_competitors_json' => ['Visible Rival'],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
}

function agenticIntelligenceObservation(array $context, string $metricKey, float|int $value, string $date, array $dimensions = [], array $overrides = []): MarketingObservation
{
    $connector = $overrides['connector'] ?? $context['connector'];
    $unit = $overrides['unit'] ?? 'count';
    $metric = MarketingMetricDefinition::query()->firstOrCreate(
        ['metric_key' => $metricKey],
        [
            'display_name' => Str::headline($metricKey),
            'description' => 'Agentic intelligence test metric.',
            'value_type' => $unit === 'ratio' ? MarketingMetricDefinition::VALUE_TYPE_PERCENT : MarketingMetricDefinition::VALUE_TYPE_DECIMAL,
            'default_unit' => $unit,
            'aggregation' => $unit === 'ratio' ? MarketingMetricDefinition::AGGREGATION_AVERAGE : MarketingMetricDefinition::AGGREGATION_SUM,
            'direction' => 'up',
            'is_active' => true,
            'metadata_json' => [],
        ],
    );

    foreach (array_keys($dimensions) as $dimensionKey) {
        MarketingDimensionDefinition::query()->firstOrCreate(
            ['dimension_key' => $dimensionKey],
            [
                'display_name' => Str::headline($dimensionKey),
                'description' => 'Agentic intelligence test dimension.',
                'value_type' => MarketingDimensionDefinition::VALUE_TYPE_STRING,
                'is_active' => true,
                'metadata_json' => [],
            ],
        );
    }

    $periodStart = Carbon::parse($date)->startOfDay();

    return MarketingObservation::upsertByFingerprint([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $connector['provider']->id,
        'connector_account_id' => $connector['account']->id,
        'connector_dataset_id' => $connector['dataset']->id,
        'connector_sync_run_id' => $connector['syncRun']->id,
        'marketing_metric_definition_id' => $metric->id,
        'metric_key' => $metric->metric_key,
        'metric_value' => $value,
        'unit' => $unit,
        'period_start' => $periodStart,
        'period_end' => $periodStart->copy()->endOfDay(),
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'observed_at' => $periodStart->copy()->addDay(),
        'confidence_score' => 1,
        'quality_score' => 1,
        'external_id' => (string) Str::uuid(),
        'source_metadata_json' => ['phase' => 'agentic_marketing_intelligence'],
        'quality_metadata_json' => [],
        'raw_metadata_json' => [],
    ], $dimensions);
}

function agenticPageInputAttributes(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): array
{
    return [
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'page_content_extraction_id' => $extraction->id,
    ];
}
