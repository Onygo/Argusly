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
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\GoogleSearchConsole\GoogleSearchConsoleDatasetDiscoveryAdapter;
use App\Services\DataConnectors\GoogleSearchConsole\GoogleSearchConsoleSearchAnalyticsSyncAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('validates the Google Search Console provider config and adapters', function () {
    $definition = config('data_connectors.providers.google_search_console');

    app(ConnectorProviderConfigValidator::class)
        ->validateProviderDefinition('google_search_console', $definition);

    $registry = app(DataConnectorRegistry::class);

    expect($registry->provider('google_search_console')['config_json']['required_scopes'])
        ->toContain('https://www.googleapis.com/auth/webmasters.readonly')
        ->and($registry->datasetDiscoveryAdapter('google_search_console'))
        ->toBeInstanceOf(GoogleSearchConsoleDatasetDiscoveryAdapter::class)
        ->and($registry->syncAdapter('google_search_console'))
        ->toBeInstanceOf(GoogleSearchConsoleSearchAnalyticsSyncAdapter::class);
});

it('generates a Google Search Console OAuth URL with the Search Console scope', function () {
    $context = phase32GscContext();

    $authorization = app(ConnectorOAuthAuthorizationUrlGenerator::class)
        ->generate('google_search_console', [
            'workspace_id' => $context['workspace']->id,
            'connector_provider_id' => $context['provider']->id,
            'connector_account_id' => $context['account']->id,
        ]);

    parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

    expect($authorization->url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'])->toContain('https://www.googleapis.com/auth/webmasters.readonly')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['include_granted_scopes'])->toBe('true');
});

it('discovers verified Google Search Console sites into connector datasets idempotently', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://unverified.example.test/', 'permissionLevel' => 'siteUnverifiedUser'],
            ],
        ]),
    ]);

    $context = phase32GscContext(withDataset: false);
    $first = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);
    $second = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);

    $dataset = ConnectorDataset::query()->firstOrFail();

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(1)
        ->and(ConnectorDataset::query()->count())->toBe(1)
        ->and($dataset->provider_key)->toBe('google_search_console')
        ->and($dataset->dataset_type)->toBe('site')
        ->and($dataset->external_dataset_id)->toBe('sc-domain:example.com')
        ->and($dataset->display_name)->toBe('sc-domain:example.com')
        ->and($dataset->config_json['site_url'])->toBe('sc-domain:example.com')
        ->and($dataset->metadata_json['permission_level'])->toBe('siteOwner')
        ->and($dataset->metadata_json['property_type'])->toBe('domain')
        ->and($dataset->hasCapability('search.analytics'))->toBeTrue()
        ->and($first['sync_run']->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED);
});

it('syncs Search Analytics rows into canonical marketing observations with dimensions and checkpoint advancement', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
            ->push(['rows' => [
                phase32GscRow('2026-07-01', 'brand query', 'https://example.com/page-a', 'usa', 'DESKTOP', 'FAQ rich results', 10, 100, 0.10, 3.2),
            ]], 200, ['X-RateLimit-Remaining' => '9'])
            ->push(['rows' => [
                phase32GscRow('2026-07-02', 'second query', 'https://example.com/page-b', 'nld', 'MOBILE', 'Web Light results', 4, 20, 0.20, 5.5),
            ]])
            ->push(['rows' => []]),
    ]);

    $context = phase32GscContext();
    phase32CreateCanonicalDefinitions();

    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'google_search_console',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-02'),
        dimensions: ['date', 'query', 'page', 'country', 'device', 'searchAppearance'],
        pageSize: 1,
        runType: ConnectorSyncRun::TYPE_MANUAL,
    ));

    $observations = MarketingObservation::query()->with('dimensions')->get();
    $firstClicks = $observations
        ->where('metric_key', 'clicks')
        ->first(fn (MarketingObservation $observation): bool => (float) $observation->metric_value === 10.0);

    expect($result->succeeded())->toBeTrue()
        ->and($result->metrics['pages'])->toBe(3)
        ->and($result->metrics['observations_written'])->toBe(8)
        ->and($observations)->toHaveCount(8)
        ->and($observations->pluck('metric_key')->unique()->sort()->values()->all())->toBe([
            'clicks',
            'ctr',
            'impressions',
            'position',
        ])
        ->and($firstClicks)->not->toBeNull()
        ->and($firstClicks->period_start->toDateString())->toBe('2026-07-01')
        ->and($firstClicks->unit)->toBe('count')
        ->and($firstClicks->dimensions->pluck('dimension_value', 'dimension_key')->all())->toMatchArray([
            'date' => '2026-07-01',
            'query' => 'brand query',
            'page' => 'https://example.com/page-a',
            'country' => 'usa',
            'device' => 'DESKTOP',
            'searchAppearance' => 'FAQ rich results',
        ])
        ->and($context['dataset']->fresh()->cursor_json)->toMatchArray([
            'start_row' => 0,
            'last_synced_date' => '2026-07-02',
        ])
        ->and($result->run->fresh()->rate_limit_json['remaining'])->toBe('9')
        ->and($context['account']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY);

    Http::assertSentCount(3);
    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $payload['startDate'] === '2026-07-01'
            && $payload['endDate'] === '2026-07-02'
            && $payload['rowLimit'] === 1
            && in_array($payload['startRow'], [0, 1, 2], true)
            && $payload['dimensions'] === ['date', 'query', 'page', 'country', 'device', 'searchAppearance'];
    });
});

it('routes failed Search Analytics API responses through generic sync run and health failure paths', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
            'error' => ['message' => 'backend error'],
        ], 500),
    ]);

    $context = phase32GscContext();
    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'google_search_console',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-02'),
    ));

    $run = $result->run->fresh();
    $event = ConnectorHealthEvent::query()->firstOrFail();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toBe('Google Search Console Search Analytics request failed with status 500.')
        ->and($run->retry_json['recoverable'])->toBeTrue()
        ->and($event->event_type)->toBe('sync.recoverable_failed')
        ->and($context['dataset']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_WARNING);
});

it('does not introduce Google Search Console specific tables or models', function () {
    expect(Schema::hasTable('google_search_console_sites'))->toBeFalse()
        ->and(Schema::hasTable('google_search_console_search_analytics'))->toBeFalse()
        ->and(Schema::hasTable('gsc_observations'))->toBeFalse()
        ->and(glob(app_path('Models/*GoogleSearchConsole*')))->toBe([])
        ->and(glob(app_path('Models/*SearchConsole*')))->toBe([]);
});

function phase32GscContext(bool $withDataset = true): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 32 Organization',
        'slug' => 'phase-32-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 32 Workspace',
        'display_name' => 'Phase 32 Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 32 Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::factory()->create([
        'provider_key' => 'google_search_console',
        'name' => 'Google Search Console',
        'category' => ConnectorProvider::CATEGORY_SEARCH,
    ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Example GSC',
        'external_account_id' => 'gsc-example',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    app(ConnectorTokenVault::class)->store($account, 'fake-gsc-access-token', 'fake-gsc-refresh-token');

    $dataset = $withDataset
        ? ConnectorDataset::query()->create([
            'connector_account_id' => $account->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'provider_key' => $provider->provider_key,
            'dataset_key' => 'site:example',
            'dataset_type' => 'site',
            'external_dataset_id' => 'sc-domain:example.com',
            'display_name' => 'sc-domain:example.com',
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
            'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
            'cursor_json' => [],
            'capabilities_json' => [
                'keys' => ['search.analytics'],
                'definitions' => ['search.analytics' => ['enabled' => true]],
            ],
            'sync_config_json' => [
                'metrics' => ['clicks', 'impressions', 'ctr', 'position'],
                'dimensions' => ['date', 'query', 'page', 'country', 'device', 'searchAppearance'],
            ],
            'config_json' => ['site_url' => 'sc-domain:example.com'],
            'metadata_json' => [],
        ])
        : null;

    return compact('organization', 'workspace', 'site', 'provider', 'account', 'dataset');
}

function phase32CreateCanonicalDefinitions(): void
{
    foreach (['clicks' => 'count', 'impressions' => 'count', 'ctr' => 'ratio', 'position' => 'rank'] as $metricKey => $unit) {
        MarketingMetricDefinition::factory()->create([
            'metric_key' => $metricKey,
            'default_unit' => $unit,
        ]);
    }

    foreach (['date', 'query', 'page', 'country', 'device', 'searchAppearance'] as $dimensionKey) {
        MarketingDimensionDefinition::factory()->create(['dimension_key' => $dimensionKey]);
    }
}

function phase32GscRow(
    string $date,
    string $query,
    string $page,
    string $country,
    string $device,
    string $searchAppearance,
    int $clicks,
    int $impressions,
    float $ctr,
    float $position,
): array {
    return [
        'keys' => [$date, $query, $page, $country, $device, $searchAppearance],
        'clicks' => $clicks,
        'impressions' => $impressions,
        'ctr' => $ctr,
        'position' => $position,
    ];
}
