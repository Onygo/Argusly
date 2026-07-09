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
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates canonical marketing metric definitions', function () {
    $definition = MarketingMetricDefinition::query()->create([
        'metric_key' => 'canonical_reach',
        'display_name' => 'Canonical reach',
        'description' => 'A provider-neutral reach metric.',
        'value_type' => MarketingMetricDefinition::VALUE_TYPE_INTEGER,
        'default_unit' => 'count',
        'aggregation' => MarketingMetricDefinition::AGGREGATION_SUM,
        'direction' => 'up',
        'metadata_json' => ['owner' => 'canonical', 'api_key' => 'must-not-persist'],
    ]);

    expect($definition->exists)->toBeTrue()
        ->and($definition->metric_key)->toBe('canonical_reach')
        ->and($definition->metadata_json['owner'])->toBe('canonical')
        ->and($definition->metadata_json['api_key'])->toBe('[redacted]');
});

it('creates canonical marketing dimension definitions', function () {
    $definition = MarketingDimensionDefinition::query()->create([
        'dimension_key' => 'canonical_channel',
        'display_name' => 'Canonical channel',
        'description' => 'A provider-neutral channel dimension.',
        'value_type' => MarketingDimensionDefinition::VALUE_TYPE_STRING,
        'metadata_json' => ['safe' => true, 'client_secret' => 'must-not-persist'],
    ]);

    expect($definition->exists)->toBeTrue()
        ->and($definition->dimension_key)->toBe('canonical_channel')
        ->and($definition->metadata_json['safe'])->toBeTrue()
        ->and($definition->metadata_json['client_secret'])->toBe('[redacted]');
});

it('stores provider agnostic normalized observations with connector audit linkage and dimensions', function () {
    $context = makeCanonicalMarketingDataContext();
    $metric = MarketingMetricDefinition::factory()->create([
        'metric_key' => 'engagement_total',
        'default_unit' => 'count',
    ]);
    $channel = MarketingDimensionDefinition::factory()->create([
        'dimension_key' => 'channel',
    ]);
    $pagePath = MarketingDimensionDefinition::factory()->create([
        'dimension_key' => 'page_path',
    ]);

    $observation = MarketingObservation::upsertByFingerprint([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'marketing_metric_definition_id' => $metric->id,
        'metric_key' => $metric->metric_key,
        'metric_value' => 123.45,
        'unit' => 'count',
        'period_start' => '2026-07-01 00:00:00',
        'period_end' => '2026-07-01 23:59:59',
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'observed_at' => '2026-07-02 01:00:00',
        'confidence_score' => 0.98,
        'quality_score' => 0.93,
        'external_id' => 'generic-row-1',
        'source_metadata_json' => [
            'source_record_type' => 'generic_metric_row',
            'access_token' => 'secret-token',
        ],
        'quality_metadata_json' => [
            'sampling' => 'complete',
            'password' => 'secret-password',
        ],
        'raw_metadata_json' => [
            'payload_shape' => 'sanitized',
            'nested' => ['client_secret' => 'secret-client-value'],
        ],
        'raw_payload_ref' => 'connector-sync-runs/'.$context['syncRun']->id.'/rows/generic-row-1',
    ], [
        ['dimension_key' => $channel->dimension_key, 'dimension_value' => 'Organic'],
        ['dimension_key' => $pagePath->dimension_key, 'dimension_value' => '/pricing'],
    ], [
        [
            'attribution_type' => 'resource',
            'attributed_type' => 'canonical_page',
            'attributed_id' => 'page:/pricing',
            'attribution_key' => 'page_path',
            'attribution_value' => '/pricing',
            'weight' => 1,
            'confidence_score' => 0.91,
            'model_key' => 'canonical-attribution-v1',
        ],
    ]);

    expect($observation->workspace->is($context['workspace']))->toBeTrue()
        ->and($observation->clientSite->is($context['site']))->toBeTrue()
        ->and($observation->connectorProvider->is($context['provider']))->toBeTrue()
        ->and($observation->connectorAccount->is($context['account']))->toBeTrue()
        ->and($observation->connectorDataset->is($context['dataset']))->toBeTrue()
        ->and($observation->connectorSyncRun->is($context['syncRun']))->toBeTrue()
        ->and($observation->metricDefinition->is($metric))->toBeTrue()
        ->and((float) $observation->metric_value)->toBe(123.45)
        ->and($observation->unit)->toBe('count')
        ->and($observation->granularity)->toBe(MarketingObservation::GRANULARITY_DAILY)
        ->and($observation->period_start->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($observation->period_end->toDateTimeString())->toBe('2026-07-01 23:59:59')
        ->and($observation->dimensions)->toHaveCount(2)
        ->and($observation->dimensions->pluck('dimension_key')->sort()->values()->all())->toBe(['channel', 'page_path'])
        ->and($observation->dimensions->firstWhere('dimension_key', 'channel')->definition->is($channel))->toBeTrue()
        ->and($observation->attributions)->toHaveCount(1);

    expect(MarketingObservation::forWorkspace($context['workspace'])
        ->forClientSite($context['site'])
        ->forDataset($context['dataset'])
        ->forMetric($metric)
        ->granularity(MarketingObservation::GRANULARITY_DAILY)
        ->betweenPeriods('2026-07-01 00:00:00', '2026-07-01 23:59:59')
        ->count())->toBe(1);
});

it('upserts normalized observations idempotently by fingerprint', function () {
    $context = makeCanonicalMarketingDataContext();
    $metric = MarketingMetricDefinition::factory()->create(['metric_key' => 'conversion_total']);
    MarketingDimensionDefinition::factory()->create(['dimension_key' => 'funnel_stage']);

    $attributes = [
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'marketing_metric_definition_id' => $metric->id,
        'metric_key' => $metric->metric_key,
        'metric_value' => 10,
        'unit' => 'count',
        'period_start' => '2026-07-03 00:00:00',
        'period_end' => '2026-07-03 23:59:59',
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'external_id' => 'stable-row-id',
    ];
    $dimensions = ['funnel_stage' => 'lead'];

    $first = MarketingObservation::upsertByFingerprint($attributes, $dimensions);
    $second = MarketingObservation::upsertByFingerprint(array_merge($attributes, [
        'metric_value' => 14,
        'quality_score' => 0.88,
    ]), $dimensions);

    expect($second->id)->toBe($first->id)
        ->and(MarketingObservation::query()->count())->toBe(1)
        ->and((float) $second->metric_value)->toBe(14.0)
        ->and((float) $second->quality_score)->toBe(0.88)
        ->and($second->dimensions)->toHaveCount(1)
        ->and($context['syncRun']->marketingObservations()->count())->toBe(1)
        ->and($context['dataset']->marketingObservations()->count())->toBe(1)
        ->and($context['account']->marketingObservations()->count())->toBe(1)
        ->and($context['provider']->marketingObservations()->count())->toBe(1);
});

it('redacts secret-like metadata before persistence', function () {
    $context = makeCanonicalMarketingDataContext();
    $metric = MarketingMetricDefinition::factory()->create(['metric_key' => 'quality_checked_metric']);

    $observation = MarketingObservation::factory()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'marketing_metric_definition_id' => $metric->id,
        'metric_key' => $metric->metric_key,
        'source_metadata_json' => ['authorization' => 'Bearer hidden', 'safe_label' => 'kept'],
        'quality_metadata_json' => ['refresh_token' => 'hidden-refresh'],
        'raw_metadata_json' => ['nested' => ['api_key' => 'hidden-key'], 'visible' => 'kept'],
    ]);

    $raw = DB::table('marketing_observations')->where('id', $observation->id)->first();

    expect($observation->fresh()->source_metadata_json['authorization'])->toBe('[redacted]')
        ->and($observation->fresh()->source_metadata_json['safe_label'])->toBe('kept')
        ->and($observation->fresh()->quality_metadata_json['refresh_token'])->toBe('[redacted]')
        ->and($observation->fresh()->raw_metadata_json['nested']['api_key'])->toBe('[redacted]')
        ->and((string) $raw->source_metadata_json)->not->toContain('Bearer hidden')
        ->and((string) $raw->quality_metadata_json)->not->toContain('hidden-refresh')
        ->and((string) $raw->raw_metadata_json)->not->toContain('hidden-key');
});

it('does not rely on provider-specific marketing tables', function () {
    $context = makeCanonicalMarketingDataContext(['provider_key' => 'fictional_signal_source']);

    expect(Schema::hasTable('marketing_observations'))->toBeTrue()
        ->and(Schema::hasTable($context['provider']->provider_key.'_observations'))->toBeFalse()
        ->and(Schema::hasTable($context['provider']->provider_key.'_metrics'))->toBeFalse();
});

function makeCanonicalMarketingDataContext(array $overrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Canonical Marketing Data Organization',
        'slug' => 'canonical-marketing-data-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Canonical Marketing Data Workspace',
        'display_name' => 'Canonical Marketing Data Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Canonical Site',
        'site_url' => 'https://canonical.example.test',
        'base_url' => 'https://canonical.example.test',
        'allowed_domains' => ['canonical.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::factory()->create([
        'provider_key' => $overrides['provider_key'] ?? 'generic_marketing_source',
        'name' => 'Generic Marketing Source',
        'category' => ConnectorProvider::CATEGORY_OTHER,
    ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Generic account',
        'external_account_id' => 'generic-account-'.Str::lower(Str::random(8)),
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => 'healthy',
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => 'generic_dataset',
        'dataset_type' => 'canonical_marketing_dataset',
        'external_dataset_id' => 'generic-dataset-'.Str::lower(Str::random(8)),
        'display_name' => 'Generic canonical dataset',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'health_status' => 'healthy',
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'config_json' => [],
        'metadata_json' => [],
    ]);

    $syncRun = ConnectorSyncRun::query()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'run_type' => ConnectorSyncRun::TYPE_SCHEDULED,
        'window_start' => '2026-07-01 00:00:00',
        'window_end' => '2026-07-01 23:59:59',
        'started_at' => '2026-07-02 00:00:00',
        'finished_at' => '2026-07-02 00:01:00',
        'attempts' => 1,
        'metrics_json' => [],
        'rate_limit_json' => [],
        'retry_json' => [],
        'idempotency_key' => 'canonical-run-'.Str::lower(Str::random(16)),
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'provider' => $provider,
        'account' => $account,
        'dataset' => $dataset,
        'syncRun' => $syncRun,
    ];
}
