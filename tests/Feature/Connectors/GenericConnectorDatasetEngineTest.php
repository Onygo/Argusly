<?php

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryAdapter;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryService;
use App\Services\DataConnectors\ConnectorDatasetResolver;
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\FakeDatasetDiscoveryAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('discovers connector datasets from a fake provider without external calls', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-discovery');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'property-123',
            'dataset_type' => 'property',
            'display_name' => 'Demo Analytics Property',
            'capabilities' => ['metrics', 'dimensions'],
            'sync_config' => ['frequency' => 'daily'],
            'metadata' => ['timezone' => 'Europe/Amsterdam'],
        ],
    ]);

    $result = $service->discover($account);
    $dataset = ConnectorDataset::query()->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and($dataset->provider_key)->toBe($account->provider_key)
        ->and($dataset->workspace_id)->toBe($account->workspace_id)
        ->and($dataset->client_site_id)->toBe($account->client_site_id)
        ->and($dataset->external_dataset_id)->toBe('property-123')
        ->and($dataset->display_name)->toBe('Demo Analytics Property')
        ->and($dataset->status)->toBe(ConnectorDataset::STATUS_ACTIVE)
        ->and($dataset->discovered_at)->not->toBeNull()
        ->and($dataset->last_seen_at)->not->toBeNull()
        ->and($dataset->hasCapability('metrics'))->toBeTrue()
        ->and($dataset->sync_config_json['frequency'])->toBe('daily')
        ->and($dataset->metadata_json['timezone'])->toBe('Europe/Amsterdam')
        ->and($result['sync_run']->run_type)->toBe(ConnectorSyncRun::TYPE_DISCOVERY)
        ->and($result['sync_run']->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED)
        ->and($result['sync_run']->metrics_json['discovered'])->toBe(1);
});

it('applies initial dataset status while preserving explicit enablement choices', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-status');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'property-123',
            'dataset_type' => 'property',
            'display_name' => 'Demo Analytics Property',
            'status' => ConnectorDataset::STATUS_DISABLED,
        ],
    ]);

    $service->discover($account);
    $dataset = ConnectorDataset::query()->firstOrFail();

    expect($dataset->status)->toBe(ConnectorDataset::STATUS_DISABLED);

    $dataset->forceFill(['status' => ConnectorDataset::STATUS_ACTIVE])->save();
    $service->discover($account);

    expect($dataset->fresh()->status)->toBe(ConnectorDataset::STATUS_ACTIVE);

    Config::set('data_connectors.testing.fake_dataset_discovery.datasets', [
        [
            'external_dataset_id' => 'property-123',
            'dataset_type' => 'property',
            'display_name' => 'Demo Analytics Property',
            'status' => ConnectorDataset::STATUS_ACTIVE,
        ],
    ]);

    $dataset->forceFill(['status' => ConnectorDataset::STATUS_DISABLED])->save();
    $service->discover($account);

    expect($dataset->fresh()->status)->toBe(ConnectorDataset::STATUS_DISABLED);
});

it('updates existing datasets idempotently during repeated discovery', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-idempotent');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'site-a',
            'dataset_type' => 'site',
            'display_name' => 'Original Site',
            'capabilities' => ['search.analytics'],
        ],
    ]);

    $first = $service->discover($account);
    $firstDataset = ConnectorDataset::query()->firstOrFail();

    Config::set('data_connectors.testing.fake_dataset_discovery.datasets', [
        [
            'external_dataset_id' => 'site-a',
            'dataset_type' => 'site',
            'display_name' => 'Renamed Site',
            'capabilities' => ['search.analytics', 'pages'],
        ],
    ]);

    $second = $service->discover($account);
    $updated = $firstDataset->fresh();

    expect(ConnectorDataset::query()->count())->toBe(1)
        ->and($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(1)
        ->and($updated->display_name)->toBe('Renamed Site')
        ->and($updated->discovered_at?->isSameSecond($firstDataset->discovered_at))->toBeTrue()
        ->and($updated->last_seen_at?->greaterThanOrEqualTo($firstDataset->last_seen_at))->toBeTrue()
        ->and($updated->hasCapability('pages'))->toBeTrue();
});

it('marks disappeared datasets inactive without deleting history', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-inactive');
    $service = datasetEngineDiscoveryService($account, [
        ['external_dataset_id' => 'dataset-one', 'dataset_type' => 'campaign', 'display_name' => 'Dataset One'],
        ['external_dataset_id' => 'dataset-two', 'dataset_type' => 'campaign', 'display_name' => 'Dataset Two'],
    ]);

    $service->discover($account);

    Config::set('data_connectors.testing.fake_dataset_discovery.datasets', [
        ['external_dataset_id' => 'dataset-one', 'dataset_type' => 'campaign', 'display_name' => 'Dataset One'],
    ]);

    $result = $service->discover($account);
    $inactive = ConnectorDataset::query()->where('external_dataset_id', 'dataset-two')->firstOrFail();

    expect($result['deactivated'])->toBe(1)
        ->and(ConnectorDataset::query()->count())->toBe(2)
        ->and($inactive->status)->toBe(ConnectorDataset::STATUS_INACTIVE)
        ->and($inactive->deactivated_at)->not->toBeNull()
        ->and($inactive->trashed())->toBeFalse();
});

it('stores and queries dataset capabilities generically', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-capabilities');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'org-1',
            'dataset_type' => 'organization',
            'display_name' => 'Organization Page',
            'capabilities' => [
                'organic_posts',
                'campaigns' => ['enabled' => false],
                ['key' => 'audience.insights', 'window' => 'P30D'],
            ],
        ],
    ]);

    $service->discover($account);
    $dataset = ConnectorDataset::query()->withCapability('audience.insights')->firstOrFail();

    expect($dataset->hasCapability('organic_posts'))->toBeTrue()
        ->and($dataset->hasCapability('campaigns'))->toBeFalse()
        ->and($dataset->capabilities_json['definitions']['audience.insights']['window'])->toBe('P30D');
});

it('redacts secret-like dataset metadata during discovery', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-redaction');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'pipeline-1',
            'dataset_type' => 'pipeline',
            'display_name' => 'Pipeline',
            'metadata' => [
                'owner' => 'Revenue Ops',
                'access_token' => 'plain-token',
                'nested' => ['client_secret' => 'plain-secret'],
            ],
        ],
    ]);

    $service->discover($account);
    $dataset = ConnectorDataset::query()->firstOrFail();

    expect($dataset->metadata_json['owner'])->toBe('Revenue Ops')
        ->and($dataset->metadata_json['access_token'])->toBe('[redacted]')
        ->and($dataset->metadata_json['nested']['client_secret'])->toBe('[redacted]');
});

it('rolls discovery failures into connector health and audit records', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-health');
    $service = datasetEngineDiscoveryService($account, [], 'Provider timeout');

    expect(fn () => $service->discover($account))->toThrow(RuntimeException::class, 'Provider timeout');

    $run = ConnectorSyncRun::query()->firstOrFail();
    $event = ConnectorHealthEvent::query()->firstOrFail();

    expect($run->run_type)->toBe(ConnectorSyncRun::TYPE_DISCOVERY)
        ->and($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toBe('Provider timeout')
        ->and($event->event_type)->toBe('dataset.discovery_failed')
        ->and($event->severity)->toBe(ConnectorHealthEvent::SEVERITY_ERROR)
        ->and($account->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_ERROR)
        ->and($account->fresh()->latest_health_event_id)->toBe($event->id);
});

it('validates dataset discovery adapter class definitions generically', function () {
    $validator = app(ConnectorProviderConfigValidator::class);
    $definition = datasetEngineProviderDefinition('dataset_engine_valid');
    $definition['dataset_discovery'] = ['adapter' => FakeDatasetDiscoveryAdapter::class];

    $validator->validateProviderDefinition('dataset_engine_valid', $definition);

    $missing = $definition;
    $missing['dataset_discovery'] = ['adapter' => 'App\\Services\\DataConnectors\\MissingDatasetDiscoveryAdapter'];

    expect(fn () => $validator->validateProviderDefinition('dataset_engine_valid', $missing))
        ->toThrow(InvalidArgumentException::class, 'does not exist');

    $wrongContract = $definition;
    $wrongContract['dataset_discovery'] = ['adapter' => DatasetEngineWrongDiscoveryAdapter::class];

    expect(fn () => $validator->validateProviderDefinition('dataset_engine_valid', $wrongContract))
        ->toThrow(InvalidArgumentException::class, 'must implement ConnectorDatasetDiscoveryAdapter');
});

it('resolves sync eligibility provider agnostically', function () {
    [$account] = makeDatasetEngineAccount('dataset-engine-eligibility');
    $service = datasetEngineDiscoveryService($account, [
        [
            'external_dataset_id' => 'stream-1',
            'dataset_type' => 'stream',
            'display_name' => 'Data Stream',
            'capabilities' => ['metrics'],
            'next_sync_at' => now()->subMinute(),
        ],
    ]);

    $service->discover($account);
    $dataset = ConnectorDataset::query()->firstOrFail();
    $resolver = app(ConnectorDatasetResolver::class);

    expect($resolver->syncEligibility($dataset, 'metrics')['eligible'])->toBeTrue()
        ->and($resolver->syncEligibility($dataset, 'campaigns')['eligible'])->toBeFalse();

    $dataset->forceFill(['status' => ConnectorDataset::STATUS_INACTIVE])->save();

    expect($resolver->syncEligibility($dataset->fresh(), 'metrics')['eligible'])->toBeFalse();
});

function datasetEngineDiscoveryService(ConnectorAccount $account, array $datasets, ?string $failure = null): ConnectorDatasetDiscoveryService
{
    Config::set('data_connectors.testing.fake_dataset_discovery.provider_key', $account->provider_key);
    Config::set('data_connectors.testing.fake_dataset_discovery.datasets', $datasets);
    Config::set('data_connectors.testing.fake_dataset_discovery.failure', $failure);

    $definition = datasetEngineProviderDefinition($account->provider_key);
    $definition['dataset_discovery'] = ['adapter' => FakeDatasetDiscoveryAdapter::class];

    $registry = new DataConnectorRegistry([
        $account->provider_key => $definition,
    ], app(), app(ConnectorProviderConfigValidator::class));

    return new ConnectorDatasetDiscoveryService(
        $registry,
        app(App\Services\DataConnectors\ConnectorDatasetFingerprint::class),
        app(ConnectorDatasetResolver::class),
        app(App\Services\DataConnectors\ConnectorSyncRunLogger::class),
        app(App\Services\DataConnectors\ConnectorHealthService::class),
    );
}

function datasetEngineProviderDefinition(string $providerKey): array
{
    return [
        'provider_key' => $providerKey,
        'name' => 'Dataset Engine Provider',
        'category' => ConnectorProvider::CATEGORY_OTHER,
        'status' => ConnectorProvider::STATUS_ACTIVE,
        'supports_oauth' => true,
        'supports_sync' => true,
        'supports_webhooks' => false,
        'config_json' => [
            'required_scopes' => ['datasets.read'],
        ],
    ];
}

function makeDatasetEngineAccount(string $slug): array
{
    $unique = $slug.'-'.Str::lower(Str::random(6));

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
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'dataset-engine-plan'],
        [
            'name' => 'Dataset Engine Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 1000,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 1000,
        'seat_limit' => 5,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Primary Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Dataset Engine Owner',
        'email' => 'dataset-engine-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $provider = ConnectorProvider::query()->create([
        'provider_key' => 'dataset_engine_'.Str::lower(Str::random(8)),
        'name' => 'Dataset Engine Provider',
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
        'account_name' => 'Dataset Engine Account',
        'external_account_id' => 'account-'.$unique,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    return [$account, $provider, $workspace, $site, $user];
}

final class DatasetEngineWrongDiscoveryAdapter
{
}
