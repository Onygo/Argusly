<?php

use App\Events\Connectors\ConnectorSyncCancelled;
use App\Events\Connectors\ConnectorSyncCheckpointAdvanced;
use App\Events\Connectors\ConnectorSyncFailed;
use App\Events\Connectors\ConnectorSyncFinished;
use App\Events\Connectors\ConnectorSyncPageProcessed;
use App\Events\Connectors\ConnectorSyncStarted;
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
use App\Services\DataConnectors\ConnectorFatalSyncException;
use App\Services\DataConnectors\ConnectorHealthService;
use App\Services\DataConnectors\ConnectorObservationWriteResult;
use App\Services\DataConnectors\ConnectorObservationWriter;
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\ConnectorRecoverableSyncException;
use App\Services\DataConnectors\ConnectorSyncAdapter;
use App\Services\DataConnectors\ConnectorSyncCancelledException;
use App\Services\DataConnectors\ConnectorSyncCheckpoint;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncCursor;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPage;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorSyncRunLogger;
use App\Services\DataConnectors\DataConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs a successful fake provider sync through the reusable engine and writer', function () {
    Event::fake();

    $context = makeGenericConnectorSyncContext('phase31_success');
    MarketingMetricDefinition::factory()->create(['metric_key' => 'generic_clicks', 'default_unit' => 'count']);
    MarketingDimensionDefinition::factory()->create(['dimension_key' => 'landing_page']);

    $engine = phase31SyncEngine($context, [
        new ConnectorSyncPage([
            phase31Observation('generic_clicks', 12, 'row-1', ['landing_page' => '/pricing']),
        ], new ConnectorSyncCursor(['watermark' => '2026-07-02T00:00:00Z']), false, [], ['remaining' => 99]),
    ]);

    $result = $engine->sync(ConnectorSyncPlan::forDataset($context['dataset']));
    $run = $result->run->fresh();
    $observation = MarketingObservation::query()->firstOrFail();

    expect($result->succeeded())->toBeTrue()
        ->and($run->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED)
        ->and($run->metrics_json['observations_written'])->toBe(1)
        ->and($run->rate_limit_json['remaining'])->toBe(99)
        ->and($context['dataset']->fresh()->cursor_json['watermark'])->toBe('2026-07-02T00:00:00Z')
        ->and($observation->connectorSyncRun->is($run))->toBeTrue()
        ->and($observation->dimensions)->toHaveCount(1)
        ->and($observation->raw_metadata_json['access_token'])->toBe('[redacted]')
        ->and($context['account']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY);

    Event::assertDispatched(ConnectorSyncStarted::class);
    Event::assertDispatched(ConnectorSyncPageProcessed::class);
    Event::assertDispatched(ConnectorSyncCheckpointAdvanced::class);
    Event::assertDispatched(ConnectorSyncFinished::class);
});

it('supports incremental, backfill, paginated syncs and generic cursor advancement', function () {
    $context = makeGenericConnectorSyncContext('phase31_pagination');
    $context['dataset']->forceFill(['cursor_json' => ['page_token' => 'start']])->save();
    MarketingMetricDefinition::factory()->create(['metric_key' => 'generic_impressions', 'default_unit' => 'count']);

    Phase31FakeSyncAdapter::$seenCursors = [];
    $engine = phase31SyncEngine($context, [
        new ConnectorSyncPage([
            phase31Observation('generic_impressions', 10, 'row-page-1'),
        ], new ConnectorSyncCursor(['page_token' => 'next-page', 'offset' => 1]), true),
        new ConnectorSyncPage([
            phase31Observation('generic_impressions', 20, 'row-page-2'),
        ], new ConnectorSyncCursor(['page_token' => null, 'offset' => 2, 'watermark' => '2026-07-03']), false),
    ]);

    $plan = new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: $context['provider']->provider_key,
        account: $context['account'],
        dataset: $context['dataset'],
        priority: 'high',
        incremental: true,
        backfill: true,
        dateRangeStart: now()->subDays(30),
        dateRangeEnd: now(),
        metrics: ['generic_impressions'],
        dimensions: [],
        filters: ['country' => 'NL'],
        pageSize: 1,
        capabilities: ['metrics' => true],
        checkpoint: ConnectorSyncCursor::from($context['dataset']->cursor_json),
        retryPolicy: ['max_attempts' => 4, 'backoff_seconds' => 120],
        runType: ConnectorSyncRun::TYPE_BACKFILL,
    );

    $result = $engine->sync($plan);

    expect($result->run->run_type)->toBe(ConnectorSyncRun::TYPE_BACKFILL)
        ->and($result->metrics['pages'])->toBe(2)
        ->and($result->metrics['observations_written'])->toBe(2)
        ->and($result->metrics['incremental'])->toBeTrue()
        ->and($result->metrics['backfill'])->toBeTrue()
        ->and($context['dataset']->fresh()->cursor_json['offset'])->toBe(2)
        ->and($context['dataset']->fresh()->cursor_json['watermark'])->toBe('2026-07-03')
        ->and(Phase31FakeSyncAdapter::$seenCursors)->toBe([
            ['page_token' => 'start'],
            ['page_token' => 'next-page', 'offset' => 1],
        ]);
});

it('rolls back checkpoint advancement when observation persistence fails', function () {
    $context = makeGenericConnectorSyncContext('phase31_rollback');
    $context['dataset']->forceFill(['cursor_json' => ['offset' => 0]])->save();

    $engine = phase31SyncEngine($context, [
        new ConnectorSyncPage([
            ['metric_key' => 'missing_periods'],
        ], new ConnectorSyncCursor(['offset' => 1]), false),
    ], new Phase31FailingObservationWriter);

    $result = $engine->sync(ConnectorSyncPlan::forDataset($context['dataset']));

    expect($result->run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($context['dataset']->fresh()->cursor_json)->toBe(['offset' => 0])
        ->and(MarketingObservation::query()->count())->toBe(0);
});

it('persists observations idempotently across repeated sync runs', function () {
    $context = makeGenericConnectorSyncContext('phase31_idempotent');
    MarketingMetricDefinition::factory()->create(['metric_key' => 'generic_revenue', 'default_unit' => 'eur']);

    $engine = phase31SyncEngine($context, [
        new ConnectorSyncPage([
            phase31Observation('generic_revenue', 100, 'stable-row'),
        ], new ConnectorSyncCursor(['watermark' => 'first']), false),
    ]);
    $engine->sync(ConnectorSyncPlan::forDataset($context['dataset']));

    $engine = phase31SyncEngine($context, [
        new ConnectorSyncPage([
            phase31Observation('generic_revenue', 125, 'stable-row'),
        ], new ConnectorSyncCursor(['watermark' => 'second']), false),
    ]);
    $second = $engine->sync(ConnectorSyncPlan::forDataset($context['dataset']));

    $observation = MarketingObservation::query()->firstOrFail();

    expect(MarketingObservation::query()->count())->toBe(1)
        ->and((float) $observation->metric_value)->toBe(125.0)
        ->and($observation->connector_sync_run_id)->toBe($second->run->id);
});

it('rejects invalid sync run transitions and records cancelled syncs', function () {
    Event::fake();

    $context = makeGenericConnectorSyncContext('phase31_cancelled');
    $logger = app(ConnectorSyncRunLogger::class);
    $terminal = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $context['provider']->provider_key,
        'dataset_key' => $context['dataset']->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
    ]);

    expect(fn () => $logger->transition($terminal, ConnectorSyncRun::STATUS_RUNNING))
        ->toThrow(InvalidArgumentException::class, 'cannot transition');

    $engine = phase31SyncEngine($context, [], null, new ConnectorSyncCancelledException('Operator cancelled sync.'));
    $result = $engine->sync(ConnectorSyncPlan::forDataset($context['dataset']));

    expect($result->run->status)->toBe(ConnectorSyncRun::STATUS_CANCELLED)
        ->and($result->run->retry_json['cancelled'])->toBeTrue();

    Event::assertDispatched(ConnectorSyncCancelled::class);
});

it('records generic retry metadata and health updates for recoverable failures', function () {
    Event::fake();

    $context = makeGenericConnectorSyncContext('phase31_retry');
    $engine = phase31SyncEngine(
        $context,
        [],
        null,
        new ConnectorRecoverableSyncException('Temporary provider outage.')
    );

    $plan = new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: $context['provider']->provider_key,
        account: $context['account'],
        dataset: $context['dataset'],
        retryPolicy: ['max_attempts' => 5, 'backoff_seconds' => 60],
    );

    $result = $engine->sync($plan);
    $run = $result->run->fresh();
    $event = ConnectorHealthEvent::query()->firstOrFail();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->retry_json['recoverable'])->toBeTrue()
        ->and($run->retry_json['fatal'])->toBeFalse()
        ->and($run->retry_json['backoff_seconds'])->toBe(60)
        ->and($run->next_retry_at)->not->toBeNull()
        ->and($event->event_type)->toBe('sync.recoverable_failed')
        ->and($context['dataset']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_WARNING);

    Event::assertDispatched(ConnectorSyncFailed::class);
});

it('keeps the sync engine provider agnostic without provider specific branches', function () {
    $context = makeGenericConnectorSyncContext('phase31_provider_agnostic', 'fictional_zero_party_metrics');

    expect($context['provider']->provider_key)->toBe('fictional_zero_party_metrics')
        ->and(phase31ProviderDefinition($context['provider']->provider_key))->toHaveKey('sync');

    foreach ([
        app_path('Services/DataConnectors/ConnectorSyncEngine.php'),
        app_path('Services/DataConnectors/ConnectorObservationWriter.php'),
        app_path('Services/DataConnectors/ConnectorSyncPlan.php'),
    ] as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('google_search_console')
            ->and($source)->not->toContain('google_analytics_4')
            ->and($source)->not->toContain('linkedin');
    }
});

function phase31SyncEngine(
    array $context,
    array $pages,
    ?ConnectorObservationWriter $writer = null,
    ?Throwable $failure = null,
): ConnectorSyncEngine {
    Phase31FakeSyncAdapter::$pages = $pages;
    Phase31FakeSyncAdapter::$failure = $failure;

    return new ConnectorSyncEngine(
        new DataConnectorRegistry([
            $context['provider']->provider_key => phase31ProviderDefinition($context['provider']->provider_key),
        ], app(), app(ConnectorProviderConfigValidator::class)),
        app(ConnectorSyncRunLogger::class),
        $writer ?? app(ConnectorObservationWriter::class),
        app(ConnectorSyncCheckpoint::class),
        app(ConnectorHealthService::class),
    );
}

function phase31ProviderDefinition(string $providerKey): array
{
    return [
        'provider_key' => $providerKey,
        'name' => 'Phase 31 Fake Provider',
        'category' => ConnectorProvider::CATEGORY_OTHER,
        'status' => ConnectorProvider::STATUS_ACTIVE,
        'supports_oauth' => true,
        'supports_sync' => true,
        'supports_webhooks' => false,
        'sync' => ['adapter' => Phase31FakeSyncAdapter::class],
        'config_json' => [
            'required_scopes' => ['metrics.read'],
        ],
    ];
}

function phase31Observation(string $metricKey, int|float $value, string $externalId, array $dimensions = []): array
{
    return [
        'metric_key' => $metricKey,
        'metric_value' => $value,
        'unit' => 'count',
        'period_start' => '2026-07-01 00:00:00',
        'period_end' => '2026-07-01 23:59:59',
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'observed_at' => '2026-07-02 00:00:00',
        'external_id' => $externalId,
        'dimensions' => $dimensions,
        'raw_metadata' => ['access_token' => 'must-redact'],
    ];
}

function makeGenericConnectorSyncContext(string $slug, ?string $providerKey = null): array
{
    $unique = $slug.'-'.Str::lower(Str::random(8));

    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $unique,
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
        'name' => 'Phase 31 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::query()->create([
        'provider_key' => $providerKey ?? 'phase31_fake_'.Str::lower(Str::random(8)),
        'name' => 'Phase 31 Fake Provider',
        'category' => ConnectorProvider::CATEGORY_OTHER,
        'status' => ConnectorProvider::STATUS_ACTIVE,
        'config_json' => [],
        'supports_oauth' => true,
        'supports_sync' => true,
        'supports_webhooks' => false,
    ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Phase 31 Account',
        'external_account_id' => 'account-'.$unique,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => 'phase31_dataset',
        'dataset_type' => 'metrics',
        'external_dataset_id' => 'external-'.$unique,
        'display_name' => 'Phase 31 Dataset',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'cursor_json' => [],
        'capabilities_json' => ['keys' => ['metrics'], 'definitions' => ['metrics' => ['enabled' => true]]],
        'sync_config_json' => [],
        'metadata_json' => [],
    ]);

    return compact('organization', 'workspace', 'site', 'provider', 'account', 'dataset');
}

final class Phase31FakeSyncAdapter implements ConnectorSyncAdapter
{
    /**
     * @var array<int, ConnectorSyncPage>
     */
    public static array $pages = [];

    public static ?Throwable $failure = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $seenCursors = [];

    public function fetch(ConnectorSyncContext $context, ConnectorSyncCursor $cursor): ConnectorSyncPage
    {
        self::$seenCursors[] = $cursor->toArray();

        if (self::$failure instanceof Throwable) {
            throw self::$failure;
        }

        return array_shift(self::$pages) ?? new ConnectorSyncPage;
    }
}

final class Phase31FailingObservationWriter extends ConnectorObservationWriter
{
    public function write(ConnectorSyncContext $context, array $records): ConnectorObservationWriteResult
    {
        throw new ConnectorFatalSyncException('Writer failed before commit.');
    }
}
