<?php

use App\Contracts\Connectors\Intelligence\AiVisibilityFeed;
use App\Contracts\Connectors\Intelligence\CampaignIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\ContentScoringFeed;
use App\Contracts\Connectors\Intelligence\LeadIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\MarketingIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\PrIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\SalesIntelligenceFeed;
use App\Contracts\Connectors\Intelligence\SeoScoringFeed;
use App\Events\Connectors\ConnectorRawRecordsWritten;
use App\Events\Connectors\ConnectorSyncCompletedForTransformation;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorAsyncReportJob;
use App\Models\Connectors\ConnectorBackfillRange;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorFieldMappingPreparation;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorOAuthState;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorQuotaBudget;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\ConnectorToken;
use App\Models\Connectors\ConnectorWebhookRegistration;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorAsyncReportService;
use App\Services\DataConnectors\ConnectorBackfillService;
use App\Services\DataConnectors\ConnectorOAuthTokenClient;
use App\Services\DataConnectors\ConnectorRateLimitService;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorRegistry;
use Database\Seeders\ConnectorProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('connects Google Ads through OAuth and discovers account hierarchy datasets', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectorContext('phase28-google-ads');
    app()->instance(ConnectorOAuthTokenClient::class, new Phase28OAuthTokenClient);

    Http::preventStrayRequests();
    Http::fake([
        'https://googleads.googleapis.com/v18/customers:listAccessibleCustomers' => Http::response([
            'resourceNames' => ['customers/1234567890'],
        ]),
    ]);

    $redirect = $this->actingAs($context['user'])
        ->get(route('app.connectors.connect', [
            'provider' => 'google-ads',
            'workspace_id' => $context['workspace']->id,
        ]))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

    expect($query['scope'])->toContain('https://www.googleapis.com/auth/adwords');

    $this->actingAs($context['user'])
        ->get(route('app.connectors.oauth.callback', [
            'provider' => 'google-ads',
            'code' => 'phase28-code',
            'state' => $query['state'],
        ]))
        ->assertRedirect();

    $account = ConnectorAccount::query()->with(['datasets', 'webhookRegistration'])->firstOrFail();

    expect($account->provider_key)->toBe('google_ads')
        ->and($account->workspace_id)->toBe($context['workspace']->id)
        ->and(data_get($account->metadata_json, 'account_hierarchy.0.id'))->toBe('1234567890')
        ->and($account->datasets->pluck('dataset_type')->all())->toContain('ad_account', 'campaigns', 'ad_groups', 'ads', 'creatives', 'ads_daily_performance')
        ->and($account->webhookRegistration->status)->toBe(ConnectorWebhookRegistration::STATUS_NOT_SUPPORTED);
});

it('tracks ads async report jobs and prevents duplicate raw records', function () {
    Event::fake([ConnectorRawRecordsWritten::class, ConnectorSyncCompletedForTransformation::class]);

    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectedConnector('phase28-async', 'google_ads', 'ads_daily_performance');
    app(ConnectorTokenVault::class)->store($context['account'], 'phase28-access', 'phase28-refresh');

    Http::preventStrayRequests();
    Http::fake([
        'https://googleads.googleapis.com/v18/customers/*/googleAds:search' => Http::response([
            'report_job_id' => 'ga-report-1',
            'async_status' => 'pending',
        ], 202),
    ]);

    $plan = ConnectorSyncPlan::forDataset($context['dataset'], ConnectorSyncRun::TYPE_MANUAL);
    app(ConnectorSyncEngine::class)->sync($plan);
    app(ConnectorSyncEngine::class)->sync($plan);

    $job = ConnectorAsyncReportJob::query()->firstOrFail();
    expect($job->status)->toBe(ConnectorAsyncReportJob::STATUS_PENDING);

    $ready = app(ConnectorAsyncReportService::class)->markReady($job, ['download_url' => 'https://provider.example/report.csv']);
    $done = app(ConnectorAsyncReportService::class)->markSucceeded($ready, ['rows' => 10]);

    expect(ConnectorRawRecord::query()->count())->toBe(1)
        ->and($done->status)->toBe(ConnectorAsyncReportJob::STATUS_SUCCEEDED)
        ->and($done->payload_json['rows'])->toBe(10);

    Event::assertDispatched(ConnectorRawRecordsWritten::class);
    Event::assertDispatched(ConnectorSyncCompletedForTransformation::class);
});

it('records quota soft warnings and hard stops', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectedConnector('phase28-quota', 'google_ads', 'ads_daily_performance');

    Config::set('data_connectors.providers.google_ads.config_json.quota.hourly.limit', 2);
    Config::set('data_connectors.providers.google_ads.config_json.quota.hourly.warning_threshold_percent', 50);
    Config::set('data_connectors.providers.google_ads.config_json.quota.daily.limit', 2);
    Config::set('data_connectors.providers.google_ads.config_json.quota.daily.warning_threshold_percent', 50);
    app()->forgetInstance(DataConnectorRegistry::class);

    $service = app(ConnectorRateLimitService::class);
    $first = $service->consume($context['account']);
    $second = $service->consume($context['account']);

    expect($first['status'])->toBe(ConnectorQuotaBudget::STATUS_SOFT_WARNING)
        ->and($second['status'])->toBe(ConnectorQuotaBudget::STATUS_HARD_STOP)
        ->and($service->canAttempt($context['account']))->toBeFalse()
        ->and(ConnectorHealthEvent::query()->where('event_type', 'quota.soft_warning')->exists())->toBeTrue()
        ->and(ConnectorHealthEvent::query()->where('event_type', 'quota.hard_stop')->exists())->toBeTrue();
});

it('connects HubSpot, discovers CRM schemas, prepares webhooks and syncs by cursor', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectorContext('phase28-hubspot');
    app()->instance(ConnectorOAuthTokenClient::class, new Phase28OAuthTokenClient);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.hubapi.com/crm/v3/properties/*' => Http::response([
            'results' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'string'],
                ['name' => 'updatedAt', 'label' => 'Updated at', 'type' => 'datetime'],
            ],
        ]),
        'https://api.hubapi.com/crm/v3/objects/contacts/search' => Http::response([
            'results' => [
                ['id' => '101', 'updatedAt' => '2026-07-08T10:00:00Z', 'properties' => ['email' => 'ada@example.test']],
            ],
        ]),
    ]);

    $redirect = $this->actingAs($context['user'])
        ->get(route('app.connectors.connect', [
            'provider' => 'hubspot',
            'workspace_id' => $context['workspace']->id,
        ]))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

    $this->actingAs($context['user'])
        ->get(route('app.connectors.oauth.callback', [
            'provider' => 'hubspot',
            'code' => 'phase28-hubspot-code',
            'state' => $query['state'],
        ]))
        ->assertRedirect();

    $account = ConnectorAccount::query()->with(['datasets', 'webhookRegistration'])->firstOrFail();
    $dataset = $account->datasets->firstWhere('dataset_type', 'contacts');
    $result = app(ConnectorSyncEngine::class)->sync(ConnectorSyncPlan::forDataset($dataset, ConnectorSyncRun::TYPE_MANUAL));

    expect($account->provider_key)->toBe('hubspot')
        ->and($account->webhookRegistration->status)->toBe(ConnectorWebhookRegistration::STATUS_PREPARED)
        ->and(ConnectorFieldMappingPreparation::query()->where('connector_account_id', $account->id)->count())->toBeGreaterThan(0)
        ->and($result->succeeded())->toBeTrue()
        ->and($dataset->fresh()->cursor_json['last_updated_at'])->toBe('2026-07-08T10:00:00Z')
        ->and(ConnectorRawRecord::query()->where('record_type', 'contacts')->exists())->toBeTrue();
});

it('creates idempotent backfill ranges and enforces connector permissions and workspace isolation', function () {
    Bus::fake();

    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectedConnector('phase28-backfill', 'google_ads', 'ads_daily_performance');

    $service = app(ConnectorBackfillService::class);
    $service->request($context['dataset'], '2026-06-01', '2026-06-10', $context['user'], chunkDays: 10);
    $service->request($context['dataset'], '2026-06-01', '2026-06-10', $context['user'], chunkDays: 10);

    $member = User::query()->create([
        'name' => 'Phase 28 Member',
        'email' => 'phase28-member+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $context['organization']->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
    ]);

    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $context['organization']->id,
        'name' => 'Other Workspace',
        'display_name' => 'Other Workspace',
    ]);

    expect(ConnectorBackfillRange::query()->count())->toBe(1);

    $this->actingAs($member)
        ->post(route('app.connectors.datasets.backfill', $context['dataset']), [
            'range_start' => '2026-06-01',
            'range_end' => '2026-06-10',
        ])
        ->assertForbidden();

    $this->actingAs($context['user'])
        ->post(route('app.connectors.datasets.backfill', [
            'connectorDataset' => $context['dataset']->id,
            'workspace_id' => $otherWorkspace->id,
        ]), [
            'range_start' => '2026-06-01',
            'range_end' => '2026-06-10',
        ])
        ->assertNotFound();
});

it('shows Phase 28 UI and exposes intelligence feed interfaces', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase28ConnectedConnector('phase28-ui', 'meta_ads', 'ads_daily_performance');

    $this->actingAs($context['user'])
        ->get(route('app.connectors.index', ['workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Google Ads')
        ->assertSee('HubSpot')
        ->assertSee('Async reports')
        ->assertSee('Incremental');

    $this->actingAs($context['user'])
        ->get(route('app.connectors.show', $context['account']))
        ->assertOk()
        ->assertSee('Quota usage')
        ->assertSee('Backfill status')
        ->assertSee('Latest report jobs')
        ->assertSee('Webhook readiness');

    foreach ([
        MarketingIntelligenceFeed::class,
        ContentScoringFeed::class,
        SeoScoringFeed::class,
        CampaignIntelligenceFeed::class,
        PrIntelligenceFeed::class,
        AiVisibilityFeed::class,
        LeadIntelligenceFeed::class,
        SalesIntelligenceFeed::class,
    ] as $interface) {
        expect(interface_exists($interface))->toBeTrue();
    }
});

function phase28ConnectorContext(string $slug): array
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

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'phase28-plan'],
        [
            'name' => 'Phase 28 Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 1000,
        ],
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
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 28 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Phase 28 Owner',
        'email' => 'phase28-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function phase28ConnectedConnector(string $slug, string $providerKey, string $datasetType): array
{
    $context = phase28ConnectorContext($slug);
    $provider = ConnectorProvider::query()->where('provider_key', $providerKey)->firstOrFail();

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $providerKey,
        'account_name' => Str::headline($providerKey).' Account',
        'external_account_id' => 'account-'.$slug,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'health_score' => 100,
        'metadata_json' => [],
    ]);

    ConnectorWebhookRegistration::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $account->id,
        'provider_key' => $providerKey,
        'status' => ConnectorWebhookRegistration::STATUS_PREPARED,
        'event_types_json' => [],
        'metadata_json' => ['registration_ready' => true],
    ]);

    ConnectorToken::query()->create([
        'connector_account_id' => $account->id,
        'access_token' => 'phase28-access-existing',
        'refresh_token' => 'phase28-refresh-existing',
        'token_type' => 'Bearer',
        'expires_at' => now()->addHour(),
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $providerKey,
        'dataset_key' => $providerKey.':'.$datasetType,
        'dataset_type' => $datasetType,
        'external_dataset_id' => '1234567890',
        'display_name' => Str::headline($datasetType),
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'cursor_json' => [],
        'capabilities_json' => [
            'keys' => ['ads.performance', 'crm.field_mapping_prep'],
            'definitions' => [
                'ads.performance' => ['enabled' => true],
                'crm.field_mapping_prep' => ['enabled' => true],
            ],
        ],
        'sync_config_json' => [],
        'config_json' => ['ad_account_id' => '1234567890', 'provider_object' => $datasetType],
        'metadata_json' => [],
    ]);

    return array_merge($context, compact('provider', 'account', 'dataset'));
}

final class Phase28OAuthTokenClient implements ConnectorOAuthTokenClient
{
    public function exchangeAuthorizationCode(array $oauthConfig, ConnectorOAuthState $state, string $code, array $payload = []): array
    {
        unset($oauthConfig, $code, $payload);

        return [
            'access_token' => 'phase28-access-token',
            'refresh_token' => 'phase28-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', (array) ($state->scopes_json ?? [])),
        ];
    }

    public function refreshAccessToken(array $oauthConfig, string $refreshToken, array $payload = []): array
    {
        unset($oauthConfig, $refreshToken, $payload);

        return [
            'access_token' => 'phase28-access-refreshed',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function revokeToken(array $oauthConfig, string $token, array $payload = []): array
    {
        unset($oauthConfig, $token, $payload);

        return ['revoked' => true];
    }
}
