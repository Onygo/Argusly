<?php

use App\Jobs\Connectors\SyncConnectorDatasetJob;
use App\Models\AuditLog;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorOAuthState;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\ConnectorToken;
use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingMetricDefinition;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorOAuthTokenClient;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorSyncScheduler;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorRegistry;
use Database\Seeders\ConnectorProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('connects a workspace-owned Google Search Console account through generic OAuth callback and discovers datasets', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase27ConnectorContext('phase27-oauth');
    $client = new Phase27OAuthTokenClient;
    app()->instance(ConnectorOAuthTokenClient::class, $client);

    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'sc-domain:phase27.test', 'permissionLevel' => 'siteOwner'],
            ],
        ]),
    ]);

    $redirect = $this->actingAs($context['user'])
        ->get(route('app.connectors.connect', [
            'provider' => 'google-search-console',
            'workspace_id' => $context['workspace']->id,
        ]))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

    $this->actingAs($context['user'])
        ->get(route('app.connectors.oauth.callback', [
            'provider' => 'google-search-console',
            'code' => 'phase27-auth-code',
            'state' => $query['state'],
        ]))
        ->assertRedirect();

    $account = ConnectorAccount::query()->with(['datasets', 'scopes'])->firstOrFail();
    $rawToken = DB::table('connector_tokens')->where('connector_account_id', $account->id)->first();

    expect($account->workspace_id)->toBe($context['workspace']->id)
        ->and($account->provider_key)->toBe('google_search_console')
        ->and($account->status)->toBe(ConnectorAccount::STATUS_CONNECTED)
        ->and($account->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY)
        ->and($account->external_account_id)->toBe('sc-domain:phase27.test')
        ->and($account->datasets)->toHaveCount(1)
        ->and($account->datasets->first()->external_dataset_id)->toBe('sc-domain:phase27.test')
        ->and($account->scopes->pluck('scope')->all())->toContain('https://www.googleapis.com/auth/webmasters.readonly')
        ->and($rawToken->access_token)->not->toBe('phase27-access-one')
        ->and(AuditLog::query()->where('action', 'connector.connected')->exists())->toBeTrue()
        ->and($client->exchangedCode)->toBe('phase27-auth-code');
});

it('redirects admins back when Google Search Console OAuth setup still contains a placeholder client id', function () {
    $context = phase27ConnectorContext('phase27-gsc-setup');
    $definition = config('data_connectors.providers.google_search_console');
    data_set($definition, 'config_json.oauth.client_id', 'google-search-console-client-id');

    Config::set('data_connectors.providers.google_search_console', $definition);
    app()->forgetInstance(DataConnectorRegistry::class);

    $this->actingAs($context['user'])
        ->get(route('app.connectors.connect', [
            'provider' => 'google-search-console',
            'workspace_id' => $context['workspace']->id,
        ]))
        ->assertRedirect(route('app.connectors.index', ['workspace_id' => $context['workspace']->id]))
        ->assertSessionHasErrors('connector');
});

it('automatically refreshes expired tokens before provider API calls', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase27ConnectedAccount('phase27-refresh');
    $client = new Phase27OAuthTokenClient;
    app()->instance(ConnectorOAuthTokenClient::class, $client);

    $expired = app(ConnectorTokenVault::class)->store(
        $context['account'],
        'phase27-expired-access',
        'phase27-refresh-token',
        expiresAt: now()->subMinute(),
    );

    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'sc-domain:refresh.test', 'permissionLevel' => 'siteOwner'],
            ],
        ]),
    ]);

    app(\App\Services\DataConnectors\ConnectorDatasetDiscoveryService::class)->discover($context['account']);

    $latest = $context['account']->tokens()->whereNull('revoked_at')->latest('created_at')->firstOrFail();

    expect($expired->fresh()->revoked_at)->not->toBeNull()
        ->and($latest->access_token)->toBe('phase27-access-refreshed')
        ->and($latest->refresh_token)->toBe('phase27-refresh-token')
        ->and($client->refreshedToken)->toBe('phase27-refresh-token')
        ->and(AuditLog::query()->where('action', 'connector.token_refreshed')->exists())->toBeTrue();
});

it('stores raw provider rows during sync alongside existing observations', function () {
    $context = phase27ConnectedAccount('phase27-raw');
    phase27CanonicalDefinitions();

    app(ConnectorTokenVault::class)->store($context['account'], 'phase27-access-token', 'phase27-refresh-token');

    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
            'rows' => [[
                'keys' => ['2026-07-01', 'phase query', 'https://phase27.test/', 'nld', 'DESKTOP', 'WEB'],
                'clicks' => 3,
                'impressions' => 30,
                'ctr' => 0.1,
                'position' => 4.2,
                'access_token' => 'must-redact',
            ]],
        ]),
    ]);

    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'google_search_console',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-01'),
        runType: ConnectorSyncRun::TYPE_MANUAL,
    ));

    $raw = ConnectorRawRecord::query()->firstOrFail();

    expect($result->succeeded())->toBeTrue()
        ->and($result->metrics['observations_written'])->toBe(4)
        ->and($result->metrics['raw_records_written'])->toBe(1)
        ->and($raw->provider_key)->toBe('google_search_console')
        ->and($raw->record_type)->toBe('search_analytics')
        ->and($raw->payload_json['access_token'])->toBe('[redacted]');
});

it('queues manual syncs and scheduled syncs through generic jobs', function () {
    Bus::fake([SyncConnectorDatasetJob::class]);

    $this->seed(ConnectorProviderSeeder::class);
    $context = phase27ConnectedAccount('phase27-scheduler');
    $context['dataset']->forceFill([
        'sync_frequency' => 'hourly',
        'next_sync_at' => now()->subMinute(),
    ])->save();

    $this->actingAs($context['user'])
        ->post(route('app.connectors.sync', $context['account']))
        ->assertRedirect();

    app(ConnectorSyncScheduler::class)->dispatchDue(limit: 10, queue: 'default');

    Bus::assertDispatched(SyncConnectorDatasetJob::class, 2);

    expect($context['dataset']->fresh()->next_sync_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'connector.manual_sync_requested')->exists())->toBeTrue();
});

it('enforces admin-only connector mutations while allowing member views', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase27ConnectedAccount('phase27-permissions');

    $member = User::query()->create([
        'name' => 'Connector Member',
        'email' => 'phase27-member+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $context['organization']->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('app.connectors.show', $context['account']))
        ->assertOk();

    $this->actingAs($member)
        ->post(route('app.connectors.sync', $context['account']))
        ->assertForbidden();
});

it('disconnects connectors without exposing or deleting credentials', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = phase27ConnectedAccount('phase27-disconnect');
    $client = new Phase27OAuthTokenClient;
    app()->instance(ConnectorOAuthTokenClient::class, $client);

    $token = app(ConnectorTokenVault::class)->store($context['account'], 'phase27-access-token', 'phase27-refresh-token');

    $this->actingAs($context['user'])
        ->post(route('app.connectors.disconnect', $context['account']))
        ->assertRedirect(route('app.connectors.index', ['workspace_id' => $context['workspace']->id]));

    expect($context['account']->fresh()->status)->toBe(ConnectorAccount::STATUS_REVOKED)
        ->and($context['dataset']->fresh()->status)->toBe(ConnectorDataset::STATUS_DISABLED)
        ->and($token->fresh()->revoked_at)->not->toBeNull()
        ->and(ConnectorToken::query()->where('connector_account_id', $context['account']->id)->count())->toBe(1)
        ->and($client->revokedToken)->toBe('phase27-access-token')
        ->and(AuditLog::query()->where('action', 'connector.disconnected')->exists())->toBeTrue();
});

function phase27ConnectorContext(string $slug): array
{
    phase27ConfigureConnectorOAuth('google_search_console');

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
        ['key' => 'phase27-plan'],
        [
            'name' => 'Phase 27 Plan',
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
        'name' => 'Phase 27 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Phase 27 Owner',
        'email' => 'phase27-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function phase27ConfigureConnectorOAuth(string $providerKey, ?string $clientId = null): void
{
    Config::set(
        'data_connectors.providers.'.$providerKey.'.config_json.oauth.client_id',
        $clientId ?? str_replace('_', '-', $providerKey).'-test-oauth-client',
    );

    app()->forgetInstance(DataConnectorRegistry::class);
}

function phase27ConnectedAccount(string $slug): array
{
    $context = phase27ConnectorContext($slug);
    $provider = ConnectorProvider::query()->where('provider_key', 'google_search_console')->first()
        ?? ConnectorProvider::factory()->create([
            'provider_key' => 'google_search_console',
            'name' => 'Google Search Console',
            'category' => ConnectorProvider::CATEGORY_SEARCH,
        ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Phase 27 GSC',
        'external_account_id' => 'sc-domain:phase27.test',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'health_score' => 100,
        'metadata_json' => [],
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => 'site:phase27',
        'dataset_type' => 'site',
        'external_dataset_id' => 'sc-domain:phase27.test',
        'display_name' => 'sc-domain:phase27.test',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'cursor_json' => [],
        'capabilities_json' => [
            'keys' => ['search.analytics'],
            'definitions' => ['search.analytics' => ['enabled' => true]],
        ],
        'sync_config_json' => [
            'metrics' => ['clicks', 'impressions', 'ctr', 'position'],
            'dimensions' => ['date', 'query', 'page', 'country', 'device', 'searchAppearance'],
        ],
        'config_json' => ['site_url' => 'sc-domain:phase27.test'],
        'metadata_json' => [],
    ]);

    return array_merge($context, compact('provider', 'account', 'dataset'));
}

function phase27CanonicalDefinitions(): void
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

final class Phase27OAuthTokenClient implements ConnectorOAuthTokenClient
{
    public ?string $exchangedCode = null;

    public ?string $refreshedToken = null;

    public ?string $revokedToken = null;

    public function exchangeAuthorizationCode(array $oauthConfig, ConnectorOAuthState $state, string $code, array $payload = []): array
    {
        $this->exchangedCode = $code;

        return [
            'access_token' => 'phase27-access-one',
            'refresh_token' => 'phase27-refresh-one',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', (array) ($state->scopes_json ?? [])),
        ];
    }

    public function refreshAccessToken(array $oauthConfig, string $refreshToken, array $payload = []): array
    {
        $this->refreshedToken = $refreshToken;

        return [
            'access_token' => 'phase27-access-refreshed',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];
    }

    public function revokeToken(array $oauthConfig, string $token, array $payload = []): array
    {
        $this->revokedToken = $token;

        return ['revoked' => true];
    }
}
