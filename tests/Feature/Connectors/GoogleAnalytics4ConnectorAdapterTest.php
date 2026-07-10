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
use App\Services\DataConnectors\ConnectorDatasetDiscoveryService;
use App\Services\DataConnectors\ConnectorOAuthAuthorizationUrlGenerator;
use App\Services\DataConnectors\ConnectorProviderActionRequiredException;
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\GoogleAnalytics4\GoogleAnalytics4DatasetDiscoveryAdapter;
use App\Services\DataConnectors\GoogleAnalytics4\GoogleAnalytics4ReportingSyncAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('validates the Google Analytics 4 provider config and adapters', function () {
    $definition = config('data_connectors.providers.google_analytics_4');

    app(ConnectorProviderConfigValidator::class)
        ->validateProviderDefinition('google_analytics_4', $definition);

    $registry = app(DataConnectorRegistry::class);

    expect($registry->provider('google_analytics_4')['config_json']['required_scopes'])
        ->toContain('https://www.googleapis.com/auth/analytics.readonly')
        ->and($registry->datasetDiscoveryAdapter('google_analytics_4'))
        ->toBeInstanceOf(GoogleAnalytics4DatasetDiscoveryAdapter::class)
        ->and($registry->syncAdapter('google_analytics_4'))
        ->toBeInstanceOf(GoogleAnalytics4ReportingSyncAdapter::class);
});

it('generates a Google Analytics 4 OAuth URL with the Analytics readonly scope', function () {
    Config::set('data_connectors.providers.google_analytics_4.config_json.oauth.client_id', 'ga4-test-oauth-client');
    app()->forgetInstance(DataConnectorRegistry::class);

    $context = phase33Ga4Context();

    $authorization = app(ConnectorOAuthAuthorizationUrlGenerator::class)
        ->generate('google_analytics_4', [
            'workspace_id' => $context['workspace']->id,
            'connector_provider_id' => $context['provider']->id,
            'connector_account_id' => $context['account']->id,
        ]);

    parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

    expect($authorization->url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'])->toContain('https://www.googleapis.com/auth/analytics.readonly')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['include_granted_scopes'])->toBe('true')
        ->and($query)->not->toHaveKey('nonce');
});

it('discovers Google Analytics 4 accounts properties and data streams idempotently', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
            'accountSummaries' => [
                [
                    'name' => 'accountSummaries/1000',
                    'account' => 'accounts/1000',
                    'displayName' => 'Example Account',
                    'propertySummaries' => [
                        [
                            'property' => 'properties/1234',
                            'displayName' => 'Example Property',
                            'propertyType' => 'PROPERTY_TYPE_ORDINARY',
                            'parent' => 'accounts/1000',
                            'canEdit' => true,
                        ],
                    ],
                ],
            ],
        ]),
        'https://analyticsadmin.googleapis.com/v1beta/properties/1234/dataStreams*' => Http::response([
            'dataStreams' => [
                [
                    'name' => 'properties/1234/dataStreams/5678',
                    'type' => 'WEB_DATA_STREAM',
                    'displayName' => 'Primary Web Stream',
                    'webStreamData' => [
                        'measurementId' => 'G-EXAMPLE',
                        'defaultUri' => 'https://example.com',
                    ],
                    'client_secret' => 'plain-secret',
                ],
            ],
        ]),
    ]);

    $context = phase33Ga4Context(withDataset: false);
    $first = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);
    $second = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);

    $accountDataset = ConnectorDataset::query()->where('dataset_type', 'account')->firstOrFail();
    $propertyDataset = ConnectorDataset::query()->where('dataset_type', 'property')->firstOrFail();
    $streamDataset = ConnectorDataset::query()->where('dataset_type', 'data_stream')->firstOrFail();

    expect($first['created'])->toBe(3)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(3)
        ->and(ConnectorDataset::query()->count())->toBe(3)
        ->and($accountDataset->external_dataset_id)->toBe('accounts/1000')
        ->and($accountDataset->hasCapability('analytics.account'))->toBeTrue()
        ->and($propertyDataset->external_dataset_id)->toBe('properties/1234')
        ->and($propertyDataset->config_json['property_id'])->toBe('1234')
        ->and($propertyDataset->sync_config_json['metrics'])->toContain('users')
        ->and($propertyDataset->sync_config_json['dimensions'])->toContain('defaultChannelGroup')
        ->and($propertyDataset->hasCapability('analytics.reporting'))->toBeTrue()
        ->and($streamDataset->external_dataset_id)->toBe('properties/1234/dataStreams/5678')
        ->and($streamDataset->config_json['measurement_id'])->toBe('G-EXAMPLE')
        ->and($streamDataset->metadata_json['raw_stream']['client_secret'])->toBe('[redacted]')
        ->and($first['sync_run']->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED);
});

it('surfaces action required when Google Analytics 4 discovery is blocked by Google', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
            'error' => [
                'message' => 'Google Analytics Admin API has not been used in this project before or it is disabled.',
            ],
        ], 403),
    ]);

    $context = phase33Ga4Context(withDataset: false);

    expect(fn () => app(ConnectorDatasetDiscoveryService::class)->discover($context['account']))
        ->toThrow(ConnectorProviderActionRequiredException::class);

    $run = ConnectorSyncRun::query()->firstOrFail();
    $event = ConnectorHealthEvent::query()->firstOrFail();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toContain('Google Analytics 4 account summary discovery failed with HTTP 403')
        ->and($run->error_message)->toContain('Google Analytics Admin API has not been used')
        ->and($event->message)->toContain('Google Analytics 4 account summary discovery failed with HTTP 403')
        ->and($context['account']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_ERROR);
});

it('syncs Google Analytics 4 report rows into canonical observations with dimensions and checkpoint advancement', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://analyticsdata.googleapis.com/v1beta/properties/1234:runReport' => Http::sequence()
            ->push(phase33Ga4ReportResponse([
                phase33Ga4Row('20260701', '/pricing', 'google', 'organic', 'summer', 'desktop', 'Netherlands', 'Organic Search', [
                    '12',
                    '9',
                    '4',
                    '8',
                    '0.6667',
                    '82.5',
                    '42',
                    '3',
                ]),
            ], 2), 200, ['X-RateLimit-Remaining' => '8'])
            ->push(phase33Ga4ReportResponse([
                phase33Ga4Row('20260702', '/demo', 'newsletter', 'email', 'launch', 'mobile', 'United States', 'Email', [
                    '7',
                    '6',
                    '2',
                    '5',
                    '0.7143',
                    '64.25',
                    '21',
                    '1',
                ]),
            ], 2)),
    ]);

    $context = phase33Ga4Context();
    phase33CreateCanonicalDefinitions();

    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'google_analytics_4',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-02'),
        pageSize: 1,
        runType: ConnectorSyncRun::TYPE_MANUAL,
    ));

    $observations = MarketingObservation::query()->with('dimensions')->get();
    $firstUsers = $observations
        ->where('metric_key', 'users')
        ->first(fn (MarketingObservation $observation): bool => (float) $observation->metric_value === 9.0);

    expect($result->succeeded())->toBeTrue()
        ->and($result->metrics['pages'])->toBe(2)
        ->and($result->metrics['observations_written'])->toBe(16)
        ->and($observations)->toHaveCount(16)
        ->and($observations->pluck('metric_key')->unique()->sort()->values()->all())->toBe([
            'averageSessionDuration',
            'engagedSessions',
            'engagementRate',
            'eventCount',
            'keyEvents',
            'newUsers',
            'sessions',
            'users',
        ])
        ->and($firstUsers)->not->toBeNull()
        ->and($firstUsers->period_start->toDateString())->toBe('2026-07-01')
        ->and($firstUsers->unit)->toBe('count')
        ->and($firstUsers->source_metadata_json['source_metric'])->toBe('activeUsers')
        ->and($firstUsers->dimensions->pluck('dimension_value', 'dimension_key')->all())->toMatchArray([
            'date' => '2026-07-01',
            'pagePath' => '/pricing',
            'sessionSource' => 'google',
            'sessionMedium' => 'organic',
            'sessionCampaign' => 'summer',
            'deviceCategory' => 'desktop',
            'country' => 'Netherlands',
            'defaultChannelGroup' => 'Organic Search',
        ])
        ->and($context['dataset']->fresh()->cursor_json)->toMatchArray([
            'offset' => 0,
            'last_synced_date' => '2026-07-02',
        ])
        ->and($result->run->fresh()->rate_limit_json['remaining'])->toBe('8')
        ->and($context['account']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY);

    Http::assertSentCount(2);
    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $payload['dateRanges'][0]['startDate'] === '2026-07-01'
            && $payload['dateRanges'][0]['endDate'] === '2026-07-02'
            && $payload['limit'] === '1'
            && in_array($payload['offset'], ['0', '1'], true)
            && collect($payload['metrics'])->pluck('name')->contains('activeUsers')
            && ! collect($payload['metrics'])->pluck('name')->contains('users')
            && collect($payload['dimensions'])->pluck('name')->all() === phase33Ga4Dimensions();
    });
});

it('routes failed Google Analytics 4 API responses through generic sync run and health failure paths', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://analyticsdata.googleapis.com/v1beta/properties/1234:runReport' => Http::response([
            'error' => ['message' => 'backend error'],
        ], 500),
    ]);

    $context = phase33Ga4Context();
    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'google_analytics_4',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-02'),
    ));

    $run = $result->run->fresh();
    $event = ConnectorHealthEvent::query()->firstOrFail();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toBe('Google Analytics 4 reporting request failed with status 500.')
        ->and($run->retry_json['recoverable'])->toBeTrue()
        ->and($event->event_type)->toBe('sync.recoverable_failed')
        ->and($context['dataset']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_WARNING);
});

it('does not introduce Google Analytics 4 specific tables or models', function () {
    expect(Schema::hasTable('google_analytics_4_properties'))->toBeFalse()
        ->and(Schema::hasTable('google_analytics_4_reports'))->toBeFalse()
        ->and(Schema::hasTable('ga4_observations'))->toBeFalse()
        ->and(glob(app_path('Models/*GoogleAnalytics4*')))->toBe([])
        ->and(glob(app_path('Models/*GA4*')))->toBe([]);
});

function phase33Ga4Context(bool $withDataset = true): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 33 Organization',
        'slug' => 'phase-33-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 33 Workspace',
        'display_name' => 'Phase 33 Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 33 Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::factory()->create([
        'provider_key' => 'google_analytics_4',
        'name' => 'Google Analytics 4',
        'category' => ConnectorProvider::CATEGORY_ANALYTICS,
    ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Example GA4',
        'external_account_id' => 'accounts/1000',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    app(ConnectorTokenVault::class)->store($account, 'fake-ga4-access-token', 'fake-ga4-refresh-token');

    $dataset = $withDataset
        ? ConnectorDataset::query()->create([
            'connector_account_id' => $account->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'provider_key' => $provider->provider_key,
            'dataset_key' => 'property:example',
            'dataset_type' => 'property',
            'external_dataset_id' => 'properties/1234',
            'display_name' => 'Example Property',
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
            'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
            'cursor_json' => [],
            'capabilities_json' => [
                'keys' => ['analytics.property', 'analytics.reporting'],
                'definitions' => [
                    'analytics.property' => ['enabled' => true],
                    'analytics.reporting' => ['enabled' => true],
                ],
            ],
            'sync_config_json' => [
                'metrics' => phase33Ga4Metrics(),
                'dimensions' => phase33Ga4Dimensions(),
            ],
            'config_json' => [
                'account' => 'accounts/1000',
                'property' => 'properties/1234',
                'property_id' => '1234',
            ],
            'metadata_json' => [],
        ])
        : null;

    return compact('organization', 'workspace', 'site', 'provider', 'account', 'dataset');
}

function phase33CreateCanonicalDefinitions(): void
{
    foreach ([
        'sessions' => 'count',
        'users' => 'count',
        'newUsers' => 'count',
        'engagedSessions' => 'count',
        'engagementRate' => 'ratio',
        'averageSessionDuration' => 'seconds',
        'eventCount' => 'count',
        'keyEvents' => 'count',
    ] as $metricKey => $unit) {
        MarketingMetricDefinition::factory()->create([
            'metric_key' => $metricKey,
            'default_unit' => $unit,
        ]);
    }

    foreach (phase33Ga4Dimensions() as $dimensionKey) {
        MarketingDimensionDefinition::factory()->create(['dimension_key' => $dimensionKey]);
    }
}

function phase33Ga4ReportResponse(array $rows, int $rowCount): array
{
    return [
        'dimensionHeaders' => array_map(fn (string $name): array => ['name' => $name], phase33Ga4Dimensions()),
        'metricHeaders' => array_map(fn (string $name): array => ['name' => $name, 'type' => 'TYPE_FLOAT'], phase33Ga4ApiMetrics()),
        'rows' => $rows,
        'rowCount' => $rowCount,
    ];
}

function phase33Ga4Row(
    string $date,
    string $pagePath,
    string $source,
    string $medium,
    string $campaign,
    string $device,
    string $country,
    string $channel,
    array $metrics,
): array {
    return [
        'dimensionValues' => array_map(fn (string $value): array => ['value' => $value], [
            $date,
            $pagePath,
            $source,
            $medium,
            $campaign,
            $device,
            $country,
            $channel,
        ]),
        'metricValues' => array_map(fn (string $value): array => ['value' => $value], $metrics),
    ];
}

function phase33Ga4Metrics(): array
{
    return [
        'sessions',
        'users',
        'newUsers',
        'engagedSessions',
        'engagementRate',
        'averageSessionDuration',
        'eventCount',
        'keyEvents',
    ];
}

function phase33Ga4ApiMetrics(): array
{
    return [
        'sessions',
        'activeUsers',
        'newUsers',
        'engagedSessions',
        'engagementRate',
        'averageSessionDuration',
        'eventCount',
        'keyEvents',
    ];
}

function phase33Ga4Dimensions(): array
{
    return [
        'date',
        'pagePath',
        'sessionSource',
        'sessionMedium',
        'sessionCampaign',
        'deviceCategory',
        'country',
        'defaultChannelGroup',
    ];
}
