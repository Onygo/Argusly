<?php

use App\Contracts\Connectors\Intelligence\MarketingIntelligenceFeed;
use App\Contracts\Connectors\Normalization\NormalizedRecordMapper;
use App\Jobs\Connectors\TransformConnectorRawRecordsJob;
use App\Models\AttributionConversion;
use App\Models\AttributionResult;
use App\Models\AttributionTouchpoint;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\NormalizationRun;
use App\Models\Connectors\Normalized\NormalizedCampaign;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Attribution\AttributionEngine;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;
use App\Services\DataConnectors\Normalization\NormalizedRecordMapperResolver;
use App\Services\Reporting\ConnectorReportingReadService;
use App\Services\Reporting\MetricDefinitionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('collapses duplicate active normalization scopes with database uniqueness', function (): void {
    Bus::fake([TransformConnectorRawRecordsJob::class]);

    $context = phase30ConnectorContext('phase30-collapse', 'google_ads', 'ads_daily_performance');
    $syncContext = phase30SyncContext($context);
    $service = app(ConnectorNormalizationService::class);

    $first = $service->enqueueForSyncContext($syncContext, 'raw_records_written');
    $second = $service->enqueueForSyncContext($syncContext, 'sync_completed_for_transformation');

    expect($second->id)->toBe($first->id)
        ->and(NormalizationRun::query()->count())->toBe(1)
        ->and(NormalizationRun::query()->firstOrFail()->active_scope_hash)->not->toBeNull();

    Bus::assertDispatched(TransformConnectorRawRecordsJob::class, 1);

    $hash = NormalizationRun::scopeHashFor([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'provider' => 'google_ads',
        'dataset_key' => $context['dataset']->dataset_key,
        'source_type' => 'sync_run',
        'source_key' => $context['syncRun']->id,
        'scope_start_date' => '2026-07-08',
        'scope_end_date' => '2026-07-08',
    ]);

    expect(fn () => NormalizationRun::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'provider' => 'google_ads',
        'dataset_key' => $context['dataset']->dataset_key,
        'source_type' => 'sync_run',
        'source_key' => $context['syncRun']->id,
        'scope_start_date' => '2026-07-08',
        'scope_end_date' => '2026-07-08',
        'scope_hash' => $hash,
        'active_scope_hash' => $hash,
        'trigger' => 'duplicate_insert',
        'status' => NormalizationRun::STATUS_PENDING,
        'metadata_json' => [],
    ]))->toThrow(QueryException::class);
});

it('ignores duplicate queue jobs and allows failed retries and completed reprocessing', function (): void {
    $context = phase30ConnectorContext('phase30-jobs', 'google_ads', 'ads_daily_performance');
    phase30RawRecord($context, [
        'segments' => ['date' => '2026-07-08'],
        'campaign' => ['id' => 'phase30-campaign', 'name' => 'Phase 30 Search', 'status' => 'ENABLED'],
        'metrics' => ['impressions' => 100, 'clicks' => 10, 'cost_micros' => 5000000, 'conversions' => 2],
    ], externalId: 'phase30-row');

    $service = app(ConnectorNormalizationService::class);
    $run = $service->enqueueForSyncContext(phase30SyncContext($context), 'raw_records_written');

    $service->normalizeRunId((string) $run->id);
    $service->normalizeRunId((string) $run->id);

    expect($run->fresh()->status)->toBe(NormalizationRun::STATUS_COMPLETED)
        ->and($run->fresh()->active_scope_hash)->toBeNull()
        ->and(NormalizedDailyPerformance::query()->where('entity_id', 'phase30-campaign')->count())->toBe(1);

    $reprocess = $service->reprocess($run->fresh());

    expect($reprocess->id)->not->toBe($run->id)
        ->and($reprocess->status)->toBe(NormalizationRun::STATUS_PENDING)
        ->and($reprocess->active_scope_hash)->not->toBeNull();

    $failedContext = phase30ConnectorContext('phase30-retry', 'google_ads', 'ads_daily_performance');
    phase30RawRecord($failedContext, ['id' => 'bad-row'], externalId: 'bad-row');

    $resolver = Mockery::mock(NormalizedRecordMapperResolver::class);
    $resolver->shouldReceive('has')->andReturnTrue();
    $resolver->shouldReceive('resolve')->andReturn(new class implements NormalizedRecordMapper
    {
        public function provider(): string
        {
            return 'google_ads';
        }

        public function map(ConnectorRawRecord $rawRecord): array
        {
            unset($rawRecord);

            throw new RuntimeException('fixture mapper failure');
        }
    });
    app()->instance(NormalizedRecordMapperResolver::class, $resolver);

    $failedRun = app(ConnectorNormalizationService::class)->enqueueForSyncContext(phase30SyncContext($failedContext), 'raw_records_written');
    app(ConnectorNormalizationService::class)->normalizeRunId((string) $failedRun->id);

    Bus::fake([TransformConnectorRawRecordsJob::class]);
    $retried = app(ConnectorNormalizationService::class)->retry($failedRun->fresh());

    expect($failedRun->fresh()->status)->toBe(NormalizationRun::STATUS_PENDING)
        ->and($retried->active_scope_hash)->not->toBeNull();

    Bus::assertDispatched(TransformConnectorRawRecordsJob::class, 1);
});

it('uses sanitized provider fixtures in mapper integration coverage', function (string $provider): void {
    $fixture = phase30ConnectorFixture($provider);
    $json = json_encode($fixture, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('access_token')
        ->and($json)->not->toContain('refresh_token')
        ->and($json)->not->toContain('client_secret');

    $context = phase30ConnectorContext('phase30-fixture-'.$provider, $provider, str_contains($provider, 'ads') ? 'ads_daily_performance' : 'contacts');

    if (str_contains($provider, 'ads')) {
        $accountDataset = phase30Dataset($context, 'ad_accounts', ['ad_account_id' => $context['account']->external_account_id]);
        foreach ((array) $fixture['account_discovery'] as $index => $payload) {
            phase30RawRecord($context, $payload, $accountDataset, 'ad_accounts', $provider.'-account-'.$index);
        }

        foreach ((array) $fixture['campaign_hierarchy'] as $index => $payload) {
            phase30RawRecord($context, $payload, $context['dataset'], 'ads_daily_performance', $provider.'-hierarchy-'.$index);
        }

        foreach (phase30PerformanceRows($fixture) as $index => $payload) {
            phase30RawRecord($context, $payload, $context['dataset'], 'ads_daily_performance', $provider.'-performance-'.$index);
        }
    } else {
        foreach (phase30CrmObjects($fixture) as $object => $records) {
            $dataset = phase30Dataset($context, $object, ['object' => $object, 'provider_object' => $object]);

            foreach ($records as $index => $payload) {
                phase30RawRecord($context, $payload, $dataset, $object, $provider.'-'.$object.'-'.$index);
            }
        }
    }

    app(ConnectorNormalizationService::class)->normalize(NormalizationRun::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'provider' => $provider,
        'source_type' => 'connector_account',
        'source_key' => $context['account']->id,
        'trigger' => 'fixture_test',
        'status' => NormalizationRun::STATUS_PENDING,
        'metadata_json' => [],
    ]));

    if (str_contains($provider, 'ads')) {
        expect(NormalizedCampaign::query()->where('provider', $provider)->count())->toBeGreaterThan(0)
            ->and(NormalizedDailyPerformance::query()->where('provider', $provider)->count())->toBeGreaterThan(0);
    } else {
        expect(NormalizedCrmContact::query()->where('provider', $provider)->count())->toBeGreaterThan(0)
            ->and(NormalizedCrmDeal::query()->where('provider', $provider)->count())->toBeGreaterThan(0)
            ->and(NormalizedCrmContact::query()->where('provider', $provider)->whereNotNull('email_hash')->count())->toBeGreaterThan(0);
    }
})->with([
    'google_ads',
    'microsoft_ads',
    'meta_ads',
    'hubspot',
    'salesforce',
    'pipedrive',
]);

it('runs deterministic attribution models and reports from normalized read layers', function (): void {
    $context = phase30ConnectorContext('phase30-attribution', 'google_ads', 'ads_daily_performance');
    $workspace = $context['workspace'];

    NormalizedDailyPerformance::query()->create([
        'workspace_id' => $workspace->id,
        'connector_account_id' => $context['account']->id,
        'provider' => 'google_ads',
        'entity_type' => 'campaign',
        'entity_id' => 'phase30-campaign',
        'date' => '2026-07-08',
        'impressions' => 1000,
        'clicks' => 100,
        'cost' => 250,
        'original_cost' => 250,
        'original_currency' => 'EUR',
        'conversions' => 0,
        'raw_reference' => [],
    ]);

    NormalizedCampaign::query()->create([
        'workspace_id' => $workspace->id,
        'connector_account_id' => $context['account']->id,
        'provider' => 'google_ads',
        'provider_campaign_id' => 'phase30-campaign',
        'name' => 'Phase 30 Campaign',
        'status' => 'ENABLED',
        'currency' => 'EUR',
        'raw_reference' => [],
    ]);

    $contact = NormalizedCrmContact::query()->create([
        'workspace_id' => $workspace->id,
        'provider' => 'hubspot',
        'provider_contact_id' => 'contact-1',
        'email_hash' => hash('sha256', 'phase30-contact'),
        'raw_reference' => [],
    ]);

    $deal = NormalizedCrmDeal::query()->create([
        'workspace_id' => $workspace->id,
        'provider' => 'hubspot',
        'provider_deal_id' => 'deal-1',
        'contact_id' => $contact->id,
        'amount' => 1000,
        'currency' => 'EUR',
        'status' => 'won',
        'close_date' => '2026-07-09',
        'raw_reference' => [],
    ]);

    AttributionTouchpoint::query()->create([
        'workspace_id' => $workspace->id,
        'touchpoint_key' => 'first',
        'anonymous_or_contact_key' => $contact->email_hash,
        'occurred_at' => '2026-07-01 10:00:00',
        'channel' => 'paid_search',
        'source' => 'google',
        'medium' => 'cpc',
        'campaign_id' => 'phase30-campaign',
        'landing_page' => 'https://example.test/platform',
        'raw_reference' => [],
    ]);

    $last = AttributionTouchpoint::query()->create([
        'workspace_id' => $workspace->id,
        'touchpoint_key' => 'last',
        'anonymous_or_contact_key' => $contact->email_hash,
        'occurred_at' => '2026-07-08 10:00:00',
        'channel' => 'paid_search',
        'source' => 'google',
        'medium' => 'cpc',
        'campaign_id' => 'phase30-campaign',
        'landing_page' => 'https://example.test/platform',
        'raw_reference' => [],
    ]);

    AttributionConversion::query()->create([
        'workspace_id' => $workspace->id,
        'conversion_key' => 'deal-1',
        'contact_key' => $contact->email_hash,
        'email_hash' => $contact->email_hash,
        'deal_id' => $deal->id,
        'conversion_type' => 'revenue',
        'occurred_at' => '2026-07-09 12:00:00',
        'value' => 1000,
        'currency' => 'EUR',
        'status' => 'won',
        'raw_reference' => [],
    ]);

    $run = app(AttributionEngine::class)->run($workspace, 'last_touch', '2026-07-01', '2026-07-10');
    $result = AttributionResult::query()->where('attribution_run_id', $run->id)->where('credit', 1)->firstOrFail();
    $summary = app(ConnectorReportingReadService::class)->summary($workspace, '2026-07-01', '2026-07-10');
    $feed = app(MarketingIntelligenceFeed::class)->snapshot($workspace, '2026-07-01', '2026-07-10');

    expect($run->status)->toBe('completed')
        ->and($result->attribution_touchpoint_id)->toBe($last->id)
        ->and($summary['metrics']['spend'])->toBe(250.0)
        ->and($summary['metrics']['revenue'])->toBe(1000.0)
        ->and($summary['metrics']['roas'])->toBe(4.0)
        ->and(app(MetricDefinitionRegistry::class)->get('roas')->requiresAttribution)->toBeTrue()
        ->and($feed)->toHaveKeys(['summary', 'comparison', 'top_movers', 'anomalies', 'freshness', 'coverage', 'source_completeness', 'attribution_model_used']);
});

function phase30ConnectorContext(string $slug, string $providerKey, string $datasetType): array
{
    $unique = $slug.'-'.Str::lower(Str::random(8));

    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $unique,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
        'billing_company_name' => Str::headline($slug),
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 30 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::query()->firstOrCreate(
        ['provider_key' => $providerKey],
        [
            'name' => Str::headline($providerKey),
            'category' => str_contains($providerKey, 'ads') ? 'ads' : 'crm',
            'status' => ConnectorProvider::STATUS_ACTIVE,
            'config_json' => [],
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
        ],
    );

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $providerKey,
        'account_name' => Str::headline($providerKey).' Account',
        'external_account_id' => 'acct-'.$unique,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'health_score' => 100,
        'metadata_json' => [],
    ]);

    $dataset = phase30Dataset(['account' => $account, 'workspace' => $workspace, 'site' => $site], $datasetType, [
        'ad_account_id' => 'acct-'.$unique,
        'account_id' => 'acct-'.$unique,
        'object' => $datasetType,
        'provider_object' => $datasetType,
    ]);

    $syncRun = ConnectorSyncRun::query()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $providerKey,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'run_type' => ConnectorSyncRun::TYPE_MANUAL,
        'window_start' => '2026-07-08 00:00:00',
        'window_end' => '2026-07-08 23:59:59',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 100,
        'records_processed' => 0,
        'metrics_json' => [],
        'rate_limit_json' => [],
        'retry_json' => [],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    return compact('organization', 'workspace', 'site', 'provider', 'account', 'dataset', 'syncRun');
}

function phase30Dataset(array $context, string $datasetType, array $config = []): ConnectorDataset
{
    /** @var ConnectorAccount $account */
    $account = $context['account'];

    return ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'dataset_key' => $account->provider_key.':'.$datasetType.':'.Str::lower(Str::random(6)),
        'dataset_type' => $datasetType,
        'external_dataset_id' => (string) ($config['ad_account_id'] ?? $account->provider_key.':'.$datasetType),
        'display_name' => Str::headline($datasetType),
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'discovered_at' => now(),
        'last_seen_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'cursor_json' => [],
        'capabilities_json' => ['keys' => ['normalization']],
        'sync_config_json' => [],
        'config_json' => $config,
        'metadata_json' => [],
    ]);
}

function phase30RawRecord(
    array $context,
    array $payload,
    ?ConnectorDataset $dataset = null,
    ?string $recordType = null,
    ?string $externalId = null,
): ConnectorRawRecord {
    $dataset ??= $context['dataset'];

    return ConnectorRawRecord::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $dataset->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'provider_key' => $context['account']->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'record_type' => $recordType ?? $dataset->dataset_type,
        'external_record_id' => $externalId ?? (string) Str::uuid(),
        'fingerprint' => hash('sha256', $context['workspace']->id.'|'.($externalId ?? Str::uuid()).'|'.json_encode($payload)),
        'period_start' => '2026-07-08 00:00:00',
        'period_end' => '2026-07-08 23:59:59',
        'observed_at' => now(),
        'payload_json' => $payload,
        'metadata_json' => [],
    ]);
}

function phase30SyncContext(array $context): ConnectorSyncContext
{
    return new ConnectorSyncContext(
        ConnectorSyncPlan::forDataset($context['dataset'], ConnectorSyncRun::TYPE_MANUAL),
        $context['syncRun'],
    );
}

function phase30ConnectorFixture(string $provider): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/connectors/'.$provider.'/sample_payloads.json')) ?: '[]',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function phase30PerformanceRows(array $fixture): array
{
    return collect($fixture['performance_pages'] ?? [])
        ->flatMap(fn (array $page): array => (array) ($page['results'] ?? $page['rows'] ?? $page['data'] ?? []))
        ->values()
        ->all();
}

function phase30CrmObjects(array $fixture): array
{
    return collect(['companies', 'contacts', 'deals', 'activities', 'accounts', 'opportunities', 'tasks', 'organizations', 'persons'])
        ->mapWithKeys(fn (string $key): array => [$key => (array) ($fixture[$key] ?? [])])
        ->filter(fn (array $records): bool => $records !== [])
        ->all();
}
