<?php

use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketPack;
use App\Models\MarketPackScoringModel;
use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageGeoObservation;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\PageIntelligence\ScoreEngineV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps v1 compatible while generating a separate v2 score row', function (): void {
    $context = phase36ScoreScenario();

    $v1 = app(PageIntelligenceScoreCalculator::class)->calculate($context['snapshot']);
    $v2 = app(ScoreEngineV2::class)->calculate($context['snapshot'], '2026-07-02', '2026-07-02');

    expect($v1->score_version)->toBe(PageIntelligenceScoreCalculator::MODEL_VERSION)
        ->and($v1->model_used)->toBe(PageIntelligenceScoreCalculator::MODEL_KEY)
        ->and($v2->score_version)->toBe(ScoreEngineV2::MODEL_VERSION)
        ->and($v2->model_used)->toBe(ScoreEngineV2::MODEL_KEY)
        ->and($v2->id)->not->toBe($v1->id)
        ->and(PageScore::query()->where('page_snapshot_id', $context['snapshot']->id)->where('score_type', ScoreEngineV2::SCORE_TYPE)->count())->toBe(2);
});

it('generates a v2 score from performance page pr serp geo competitor market and canonical evidence', function (): void {
    $context = phase36ScoreScenario();

    $score = app(ScoreEngineV2::class)->calculate($context['snapshot'], '2026-07-02', '2026-07-02');

    expect($score)->toBeInstanceOf(PageScore::class)
        ->and((float) $score->score)->toBeGreaterThan(40)
        ->and(data_get($score->breakdown_json, 'components.organic_growth.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.traffic_trend.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.engagement_trend.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.search_visibility.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.ai_visibility.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.pr_value.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.competitor_pressure.available'))->toBeTrue()
        ->and(data_get($score->breakdown_json, 'components.market_pack_relevance.available'))->toBeTrue()
        ->and(data_get($score->metadata_json, 'phase'))->toBe('phase_36_intelligence_score_v2');
});

it('is reproducible for the same snapshot and period', function (): void {
    $context = phase36ScoreScenario();
    $engine = app(ScoreEngineV2::class);

    $first = $engine->calculate($context['snapshot'], '2026-07-02', '2026-07-02')->fresh();
    $second = $engine->calculate($context['snapshot'], '2026-07-02', '2026-07-02')->fresh();

    expect($second->id)->toBe($first->id)
        ->and((float) $second->score)->toBe((float) $first->score)
        ->and(data_get($second->breakdown_json, 'components'))->toBe(data_get($first->breakdown_json, 'components'))
        ->and(data_get($second->evidence_json, 'marketing_observation_ids'))->toBe(data_get($first->evidence_json, 'marketing_observation_ids'))
        ->and(data_get($second->evidence_json, 'trend_ids'))->toBe(data_get($first->evidence_json, 'trend_ids'))
        ->and(data_get($second->evidence_json, 'performance_signal_keys'))->toBe(data_get($first->evidence_json, 'performance_signal_keys'));
});

it('stores explainable evidence that references observations snapshots trends and performance signals', function (): void {
    $context = phase36ScoreScenario();

    $score = app(ScoreEngineV2::class)->calculate($context['snapshot'], '2026-07-02', '2026-07-02');

    expect(data_get($score->evidence_json, 'marketing_observation_ids'))->toHaveCount(8)
        ->and(data_get($score->evidence_json, 'page_snapshot_ids'))->toContain($context['snapshot']->id)
        ->and(data_get($score->evidence_json, 'trend_ids'))->not->toBeEmpty()
        ->and(data_get($score->evidence_json, 'performance_signal_keys'))->not->toBeEmpty()
        ->and(data_get($score->evidence_json, 'score_explanation.component_explanations.pr_value'))->toContain('PR value')
        ->and(data_get($score->breakdown_json, 'components.pr_value.evidence.page_intelligence_input_ids.page_pr_values'))->not->toBeEmpty()
        ->and(data_get($score->breakdown_json, 'components.search_visibility.evidence.page_intelligence_input_ids.page_serp_observations'))->not->toBeEmpty()
        ->and(data_get($score->breakdown_json, 'components.ai_visibility.evidence.page_intelligence_input_ids.page_geo_observations'))->not->toBeEmpty();
});

it('handles missing data with explicit missing inputs and lower confidence', function (): void {
    $context = phase36ScoreContext();
    [, $snapshot] = phase36Page($context, 'https://example.com/missing', '/missing', 'Missing Inputs Page');

    $score = app(ScoreEngineV2::class)->calculate($snapshot, '2026-07-02', '2026-07-02');

    expect((float) $score->score)->toBe(0.0)
        ->and(data_get($score->metadata_json, 'missing_inputs'))->toContain('organic_growth', 'traffic_trend', 'search_visibility', 'pr_value')
        ->and((float) data_get($score->metadata_json, 'confidence'))->toBe(0.0)
        ->and(data_get($score->evidence_json, 'page_snapshot_ids'))->toContain($snapshot->id);
});

it('applies market pack score weight overrides without hardcoding weights in the engine', function (): void {
    $context = phase36ScoreScenario(marketPackKey: 'phase36_market');
    $default = app(ScoreEngineV2::class)->calculate($context['snapshot'], '2026-07-02', '2026-07-02');
    $pack = MarketPack::query()->create([
        'key' => 'phase36_market',
        'name' => 'Phase 36 Market',
        'description' => 'A phase 36 scoring market.',
        'market_category' => 'test',
        'status' => MarketPack::STATUS_ACTIVE,
        'version' => 'test',
        'locale' => 'en',
        'defaults_json' => [],
        'metadata_json' => [],
    ]);
    MarketPackScoringModel::query()->create([
        'market_pack_id' => $pack->id,
        'key' => ScoreEngineV2::MODEL_KEY,
        'name' => 'Phase 36 Score v2',
        'model_type' => 'page_score',
        'model_version' => ScoreEngineV2::MODEL_VERSION,
        'weights_json' => [
            'search_visibility' => 0.6,
            'pr_value' => 0.04,
            'organic_growth' => 0.02,
        ],
        'defaults_json' => [],
        'metadata_json' => [],
    ]);

    $overridden = app(ScoreEngineV2::class)->calculate($context['snapshot'], '2026-07-02', '2026-07-02');

    expect(data_get($overridden->metadata_json, 'market_pack_key'))->toBe('phase36_market')
        ->and(data_get($overridden->breakdown_json, 'components.search_visibility.weight'))
        ->toBeGreaterThan(data_get($default->breakdown_json, 'components.search_visibility.weight'))
        ->and(data_get($overridden->breakdown_json, 'components.pr_value.weight'))
        ->toBeLessThan(data_get($default->breakdown_json, 'components.pr_value.weight'));
});

it('keeps old snapshot scoring auditable after newer evidence arrives', function (): void {
    $context = phase36ScoreScenario();
    $engine = app(ScoreEngineV2::class);
    $before = $engine->calculate($context['snapshot'], '2026-07-02', '2026-07-02')->fresh();
    $newSnapshot = PageSnapshot::factory()->forPage($context['page'], 2)->create([
        'fetched_at' => Carbon::parse('2026-07-03 12:00:00'),
    ]);
    $newExtraction = PageContentExtraction::factory()->forSnapshot($newSnapshot)->create([
        'content_depth_score' => 10,
        'word_count' => 80,
    ]);

    PagePrValue::query()->create(phase36PageInputAttributes($context['page'], $newSnapshot, $newExtraction) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'v1',
        'score' => 5,
        'estimated_value_amount' => 10,
        'currency' => 'USD',
        'confidence' => 40,
        'breakdown_json' => ['future' => true],
        'calculated_at' => Carbon::parse('2026-07-03 12:00:00'),
    ]);
    phase36Observation($context, 'sessions', 1, '2026-07-03', [
        'pagePath' => '/score-v2',
        'defaultChannelGroup' => 'Organic Search',
    ]);

    $after = $engine->calculate($context['snapshot']->fresh(), '2026-07-02', '2026-07-02')->fresh();

    expect((float) $after->score)->toBe((float) $before->score)
        ->and(data_get($after->evidence_json, 'marketing_observation_ids'))->toBe(data_get($before->evidence_json, 'marketing_observation_ids'))
        ->and(data_get($after->breakdown_json, 'components.pr_value.evidence.page_intelligence_input_ids.page_pr_values'))
        ->toBe(data_get($before->breakdown_json, 'components.pr_value.evidence.page_intelligence_input_ids.page_pr_values'));
});

function phase36ScoreScenario(string $marketPackKey = 'b2b_saas'): array
{
    $context = phase36ScoreContext();
    [$page, $snapshot, $extraction] = phase36Page($context, 'https://example.com/score-v2', '/score-v2', 'Score v2 Page');
    phase36PageInputs($page, $snapshot, $extraction, $marketPackKey);

    foreach ([
        ['sessions', 50, '2026-07-01', 'Organic Search'],
        ['sessions', 110, '2026-07-02', 'Organic Search'],
        ['engagementRate', 0.35, '2026-07-01', 'Organic Search', 'ratio'],
        ['engagementRate', 0.7, '2026-07-02', 'Organic Search', 'ratio'],
        ['impressions', 1000, '2026-07-01', 'Organic Search'],
        ['impressions', 1800, '2026-07-02', 'Organic Search'],
        ['ai_visibility_score', 55, '2026-07-01', 'AI Visibility'],
        ['ai_visibility_score', 82, '2026-07-02', 'AI Visibility'],
    ] as $row) {
        phase36Observation($context, $row[0], $row[1], $row[2], [
            'pagePath' => '/score-v2',
            'defaultChannelGroup' => $row[3],
            'topic' => 'AI Visibility',
            'market_pack' => $marketPackKey,
        ], ['unit' => $row[4] ?? 'count']);
    }

    return $context + compact('page', 'snapshot', 'extraction');
}

function phase36ScoreContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 36 Organization',
        'slug' => 'phase-36-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 36 Workspace',
        'display_name' => 'Phase 36 Workspace',
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 36 Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $context = compact('organization', 'workspace', 'site');
    $context['connector'] = phase36Connector($context, 'canonical_phase36');

    return $context;
}

function phase36Connector(array $context, string $providerKey): array
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

function phase36Page(array $context, string $url, string $path, string $title): array
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

function phase36PageInputs(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction, string $marketPackKey): void
{
    PagePrValue::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'model_key' => 'argusly_pr_value',
        'model_version' => 'v1',
        'score' => 86,
        'estimated_value_amount' => 14000,
        'currency' => 'USD',
        'confidence' => 92,
        'breakdown_json' => ['factors' => ['source_authority' => ['score' => 82]]],
        'calculated_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageSentiment::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'target_type' => PageSentiment::TARGET_PAGE,
        'target_key' => 'page:'.$page->id,
        'target_name' => $page->title_current,
        'compound_score' => 0.5,
        'label' => 'positive',
        'confidence_score' => 0.9,
        'analysis_method' => 'test',
        'model_used' => 'test',
        'analyzer_version' => 'test',
        'explanation' => 'Positive context.',
        'evidence_json' => [],
        'analyzed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageEntity::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
        'source_type' => 'test',
        'mention_count' => 3,
        'prominence_score' => 82,
        'confidence_score' => 93,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageEntity::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'entity_type' => PageEntity::TYPE_COMPETITOR,
        'entity_key' => 'competitor',
        'entity_name' => 'Competitor',
        'source_type' => 'test',
        'mention_count' => 2,
        'prominence_score' => 40,
        'confidence_score' => 80,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageTopic::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'topic_key' => 'ai_visibility',
        'topic_name' => 'AI Visibility',
        'topic_type' => 'market_pack_theme',
        'source_type' => 'market_pack',
        'mention_count' => 4,
        'prominence_score' => 80,
        'confidence_score' => 88,
        'keywords_json' => ['AI visibility'],
        'evidence_json' => [],
        'classified_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageMarketPackMatch::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'market_pack_key' => $marketPackKey,
        'market_pack_name' => Str::headline($marketPackKey),
        'match_type' => 'theme',
        'match_score' => 92,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'name' => 'Competitor',
        'domain' => 'competitor.example.test',
        'is_active' => true,
    ]);
    PageCompetitorMatch::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'site_competitor_id' => $competitor->id,
        'match_type' => 'name',
        'match_score' => 42,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    $campaign = Campaign::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
    ]);
    PageCampaignMatch::query()->create(phase36PageInputAttributes($page, $snapshot, $extraction) + [
        'campaign_id' => $campaign->id,
        'match_type' => 'campaign_keyword',
        'match_score' => 70,
        'evidence_json' => [],
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageSerpObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'visibility_score' => 77,
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
    PageGeoObservation::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'monitored_page_id' => $page->id,
        'page_snapshot_id' => $snapshot->id,
        'geo_visibility_score' => 83,
        'observed_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);
}

function phase36Observation(array $context, string $metricKey, float|int $value, string $date, array $dimensions = [], array $overrides = []): MarketingObservation
{
    $connector = $overrides['connector'] ?? $context['connector'];
    $unit = $overrides['unit'] ?? 'count';
    $metric = MarketingMetricDefinition::query()->firstOrCreate(
        ['metric_key' => $metricKey],
        [
            'display_name' => Str::headline($metricKey),
            'description' => 'Phase 36 test metric.',
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
                'description' => 'Phase 36 test dimension.',
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
        'source_metadata_json' => ['phase' => 36],
        'quality_metadata_json' => [],
        'raw_metadata_json' => [],
    ], $dimensions, $overrides['attributions'] ?? []);
}

function phase36PageInputAttributes(MonitoredPage $page, PageSnapshot $snapshot, PageContentExtraction $extraction): array
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
