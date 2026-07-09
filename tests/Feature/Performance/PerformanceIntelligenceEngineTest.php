<?php

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageEntity;
use App\Models\PageMarketPackMatch;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\Workspace;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Services\PerformanceIntelligence\PerformanceTrendService;
use App\Support\Intelligence\TimeWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('calculates daily trends and detects growth', function (): void {
    $context = phase35PerformanceContext();

    phase35Observation($context, 'sessions', 10, '2026-07-01');
    phase35Observation($context, 'sessions', 20, '2026-07-02');
    phase35Observation($context, 'sessions', 30, '2026-07-03');
    phase35Observation($context, 'sessions', 40, '2026-07-04');

    $trend = app(PerformanceTrendService::class)->trend(
        MarketingObservation::query()->with(['dimensions', 'attributions', 'metricDefinition'])->get(),
        'sessions',
        Carbon::parse('2026-07-03'),
        Carbon::parse('2026-07-04'),
        MarketingObservation::GRANULARITY_DAILY,
    );

    expect($trend->status)->toBe('sufficient_data')
        ->and($trend->direction)->toBe('growth')
        ->and($trend->currentValue)->toBe(70.0)
        ->and($trend->previousValue)->toBe(30.0)
        ->and($trend->growthPercent)->toBe(133.3333)
        ->and($trend->confidence)->toBeGreaterThan(0.9)
        ->and($trend->observationIds)->toHaveCount(4);
});

it('detects decline against the previous period', function (): void {
    $context = phase35PerformanceContext();

    phase35Observation($context, 'clicks', 60, '2026-07-01');
    phase35Observation($context, 'clicks', 40, '2026-07-02');
    phase35Observation($context, 'clicks', 20, '2026-07-03');
    phase35Observation($context, 'clicks', 30, '2026-07-04');

    $trend = app(PerformanceTrendService::class)->trend(
        MarketingObservation::query()->with(['dimensions', 'attributions', 'metricDefinition'])->get(),
        'clicks',
        Carbon::parse('2026-07-03'),
        Carbon::parse('2026-07-04'),
        MarketingObservation::GRANULARITY_DAILY,
    );

    expect($trend->direction)->toBe('decline')
        ->and($trend->currentValue)->toBe(50.0)
        ->and($trend->previousValue)->toBe(100.0)
        ->and($trend->growthPercent)->toBe(-50.0);
});

it('calculates rolling averages for moving trends', function (): void {
    $context = phase35PerformanceContext();

    foreach ([
        '2026-07-02' => 20,
        '2026-07-03' => 30,
        '2026-07-04' => 40,
        '2026-07-05' => 50,
    ] as $date => $value) {
        phase35Observation($context, 'sessions', $value, $date);
    }

    $trend = app(PerformanceTrendService::class)->trend(
        MarketingObservation::query()->with(['dimensions', 'attributions', 'metricDefinition'])->get(),
        'sessions',
        Carbon::parse('2026-07-04'),
        Carbon::parse('2026-07-05'),
        MarketingObservation::GRANULARITY_DAILY,
    );

    expect($trend->rollingAverages)->toHaveCount(4)
        ->and($trend->movingAverage)->toBe(40.0)
        ->and($trend->points[0]['bucket'])->toBe('2026-07-02')
        ->and($trend->points[3]['value'])->toBe(50.0);
});

it('marks trends with missing comparison data as insufficient', function (): void {
    $context = phase35PerformanceContext();

    phase35Observation($context, 'sessions', 42, '2026-07-04');

    $trend = app(PerformanceTrendService::class)->trend(
        MarketingObservation::query()->with(['dimensions', 'attributions', 'metricDefinition'])->get(),
        'sessions',
        Carbon::parse('2026-07-04'),
        Carbon::parse('2026-07-04'),
        MarketingObservation::GRANULARITY_DAILY,
    );

    expect($trend->status)->toBe('insufficient_data')
        ->and($trend->direction)->toBe('insufficient_data')
        ->and($trend->currentValue)->toBe(42.0)
        ->and($trend->previousValue)->toBeNull()
        ->and($trend->confidence)->toBeLessThan(0.5);
});

it('aggregates performance by page topic channel market pack and keeps observation traces', function (): void {
    $context = phase35PerformanceContext();
    [$page] = phase35PerformancePage($context, 'https://example.com/pricing', '/pricing', 'Pricing Page');

    phase35Observation($context, 'sessions', 50, '2026-07-01', [
        'pagePath' => '/pricing',
        'defaultChannelGroup' => 'Organic Search',
    ]);
    phase35Observation($context, 'sessions', 100, '2026-07-02', [
        'pagePath' => '/pricing',
        'defaultChannelGroup' => 'Organic Search',
    ]);
    phase35Observation($context, 'engagementRate', 0.5, '2026-07-02', [
        'pagePath' => '/pricing',
        'defaultChannelGroup' => 'Organic Search',
    ], ['unit' => 'ratio']);

    $snapshot = app(PerformanceIntelligenceEngine::class)->snapshot(
        $context['workspace'],
        $context['site'],
        Carbon::parse('2026-07-02'),
        Carbon::parse('2026-07-02'),
    );

    expect($snapshot->observationsCount)->toBe(2)
        ->and($snapshot->pages)->toHaveCount(1)
        ->and($snapshot->pages[0]->pageId)->toBe($page->id)
        ->and($snapshot->pages[0]->metrics['sessions']['value'])->toBe(100.0)
        ->and($snapshot->pages[0]->topics[0]['key'])->toBe('ai_visibility')
        ->and($snapshot->pages[0]->entities[0]['key'])->toBe('argusly')
        ->and($snapshot->topics)->toHaveCount(1)
        ->and(collect($snapshot->topics)->firstWhere('topicKey', 'ai_visibility')->metrics['sessions']['value'])->toBe(100.0)
        ->and($snapshot->channels)->toHaveCount(1)
        ->and($snapshot->channels[0]->channelKey)->toBe('organic_search')
        ->and($snapshot->channels[0]->trends['sessions']->direction)->toBe('growth')
        ->and($snapshot->marketPacks)->toHaveCount(1)
        ->and($snapshot->marketPacks[0]->marketPackKey)->toBe('b2b_saas')
        ->and($snapshot->marketPacks[0]->pageIds)->toContain($page->id);

    $organicGrowth = collect($snapshot->signals)->firstWhere('type', 'organic_growth');

    expect($organicGrowth)->not->toBeNull()
        ->and($organicGrowth->observationIds)->toHaveCount(2)
        ->and($organicGrowth->sourceMetrics['current']['observation_ids'])->toHaveCount(1)
        ->and($organicGrowth->sourceMetrics['previous']['observation_ids'])->toHaveCount(1)
        ->and($organicGrowth->periodStart->toDateString())->toBe('2026-07-02')
        ->and($organicGrowth->periodEnd->toDateString())->toBe('2026-07-02')
        ->and($organicGrowth->confidence)->toBeGreaterThan(0.9);
});

it('aggregates canonical observations across providers without provider specific analytics', function (): void {
    $context = phase35PerformanceContext();
    phase35PerformancePage($context, 'https://example.com/demo', '/demo', 'Demo Page');
    $secondary = phase35Connector($context, 'fictional_analytics');

    phase35Observation($context, 'sessions', 50, '2026-07-01', [
        'pagePath' => '/demo',
        'defaultChannelGroup' => 'Organic Search',
    ]);
    phase35Observation($context, 'sessions', 25, '2026-07-01', [
        'pagePath' => '/demo',
        'defaultChannelGroup' => 'Organic Search',
    ], ['connector' => $secondary]);
    phase35Observation($context, 'sessions', 60, '2026-07-02', [
        'pagePath' => '/demo',
        'defaultChannelGroup' => 'Organic Search',
    ]);
    phase35Observation($context, 'sessions', 40, '2026-07-02', [
        'pagePath' => '/demo',
        'defaultChannelGroup' => 'Organic Search',
    ], ['connector' => $secondary]);

    $snapshot = app(PerformanceIntelligenceEngine::class)->snapshot(
        $context['workspace'],
        $context['site'],
        '2026-07-02',
        '2026-07-02',
    );

    expect($snapshot->metrics['sessions']['value'])->toBe(100.0)
        ->and($snapshot->metrics['sessions']['observation_count'])->toBe(2)
        ->and($snapshot->channels[0]->metrics['sessions']['value'])->toBe(100.0)
        ->and($snapshot->channels[0]->trends['sessions']->previousValue)->toBe(75.0)
        ->and($snapshot->channels[0]->trends['sessions']->currentValue)->toBe(100.0)
        ->and($snapshot->channels[0]->trends['sessions']->observationIds)->toHaveCount(4)
        ->and(collect($snapshot->signals)->pluck('sourceMetrics')->flatten(2)->filter()->isNotEmpty())->toBeTrue();
});

it('accepts unified time engine windows in performance intelligence without changing snapshot shape', function (): void {
    $context = phase35PerformanceContext();

    phase35Observation($context, 'sessions', 15, '2026-07-01');
    phase35Observation($context, 'sessions', 25, '2026-07-08');

    $window = app(TimeWindowResolver::class)->resolve('last_7_days', ['to' => '2026-07-08']);
    $snapshot = app(PerformanceIntelligenceEngine::class)->snapshot(
        $context['workspace'],
        $context['site'],
        $window->start,
        $window->end,
    );

    expect($snapshot->periodStart->toDateString())->toBe('2026-07-02')
        ->and($snapshot->periodEnd->toDateString())->toBe('2026-07-08')
        ->and($snapshot->metrics['sessions']['value'])->toBe(25.0)
        ->and($snapshot->observationIds)->toHaveCount(1)
        ->and($snapshot->toArray())->toHaveKeys([
            'workspace_id',
            'client_site_id',
            'period_start',
            'period_end',
            'granularity',
            'metrics',
            'signals',
        ]);
});

function phase35PerformanceContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 35 Organization',
        'slug' => 'phase-35-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 35 Workspace',
        'display_name' => 'Phase 35 Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 35 Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $context = compact('organization', 'workspace', 'site');
    $context['connector'] = phase35Connector($context, 'canonical_analytics');

    return $context;
}

function phase35Connector(array $context, string $providerKey): array
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

function phase35Observation(array $context, string $metricKey, float|int $value, string $date, array $dimensions = [], array $overrides = []): MarketingObservation
{
    $connector = $overrides['connector'] ?? $context['connector'];
    $unit = $overrides['unit'] ?? 'count';
    $metric = MarketingMetricDefinition::query()->firstOrCreate(
        ['metric_key' => $metricKey],
        [
            'display_name' => Str::headline($metricKey),
            'description' => 'Phase 35 test metric.',
            'value_type' => $unit === 'ratio' ? MarketingMetricDefinition::VALUE_TYPE_PERCENT : MarketingMetricDefinition::VALUE_TYPE_DECIMAL,
            'default_unit' => $unit,
            'aggregation' => $overrides['aggregation'] ?? MarketingMetricDefinition::AGGREGATION_SUM,
            'direction' => $overrides['direction'] ?? 'up',
            'is_active' => true,
            'metadata_json' => [],
        ],
    );

    foreach (array_keys($dimensions) as $dimensionKey) {
        MarketingDimensionDefinition::query()->firstOrCreate(
            ['dimension_key' => $dimensionKey],
            [
                'display_name' => Str::headline($dimensionKey),
                'description' => 'Phase 35 test dimension.',
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
        'confidence_score' => $overrides['confidence_score'] ?? 1,
        'quality_score' => $overrides['quality_score'] ?? 1,
        'external_id' => (string) Str::uuid(),
        'source_metadata_json' => ['phase' => 35],
        'quality_metadata_json' => [],
        'raw_metadata_json' => [],
    ], $dimensions, $overrides['attributions'] ?? []);
}

function phase35PerformancePage(array $context, string $url, string $path, string $title): array
{
    $source = MonitoredSource::factory()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'source_type' => 'owned',
        'base_url' => 'https://example.com',
        'domain' => 'example.com',
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
    ]);

    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'canonical_url' => $url,
        'final_url' => $url,
    ]);

    PageTopic::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'topic_key' => 'ai_visibility',
        'topic_name' => 'AI Visibility',
        'topic_type' => 'theme',
        'mention_count' => 3,
        'prominence_score' => 88,
        'confidence_score' => 95,
        'keywords_json' => ['AI visibility'],
        'evidence_json' => ['title' => $title],
        'classified_at' => now(),
    ]);

    PageEntity::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'entity_type' => PageEntity::TYPE_BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
        'mention_count' => 2,
        'prominence_score' => 90,
        'confidence_score' => 96,
        'evidence_json' => ['title' => $title],
        'observed_at' => now(),
    ]);

    PageMarketPackMatch::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'market_pack_key' => 'b2b_saas',
        'market_pack_name' => 'B2B SaaS',
        'match_type' => 'topic',
        'match_score' => 0.92,
        'evidence_json' => ['topic' => 'AI Visibility'],
        'observed_at' => now(),
    ]);

    return [$page, $snapshot];
}
