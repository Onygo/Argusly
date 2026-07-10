<?php

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorCredential;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorOAuthState;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\ConnectorToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorHealthService;
use App\Services\DataConnectors\ConnectorOAuthAuthorizationUrlGenerator;
use App\Services\DataConnectors\ConnectorOAuthStateService;
use App\Services\DataConnectors\ConnectorOAuthTokenClient;
use App\Services\DataConnectors\ConnectorOAuthTokenManager;
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\ConnectorSyncRunLogger;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorAdapter;
use App\Services\DataConnectors\DataConnectorRegistry;
use Database\Seeders\ConnectorProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('seeds configured connector provider definitions', function () {
    $this->seed(ConnectorProviderSeeder::class);

    expect(ConnectorProvider::query()->pluck('provider_key')->sort()->values()->all())->toBe([
        'google_ads',
        'google_analytics_4',
        'google_search_console',
        'hubspot',
        'linkedin',
        'meta_ads',
        'microsoft_ads',
        'pipedrive',
        'salesforce',
    ]);

    $gsc = ConnectorProvider::query()->where('provider_key', 'google_search_console')->firstOrFail();

    expect($gsc->category)->toBe('search')
        ->and($gsc->supports_oauth)->toBeTrue()
        ->and($gsc->supports_sync)->toBeTrue()
        ->and($gsc->supports_webhooks)->toBeFalse();
});

it('resolves configured providers from the registry', function () {
    $registry = app(DataConnectorRegistry::class);

    expect($registry->has('google_search_console'))->toBeTrue()
        ->and($registry->has('google_analytics_4'))->toBeTrue()
        ->and($registry->has('linkedin'))->toBeTrue()
        ->and($registry->provider('linkedin')['category'])->toBe('social')
        ->and($registry->keys())->toContain('google_search_console');
});

it('creates connector accounts scoped to a workspace', function () {
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'google_search_console']);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Example Search Console',
        'external_account_id' => 'sc-domain:example.test',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'metadata_json' => ['property_type' => 'domain'],
    ]);

    expect($account->workspace->is($context['workspace']))->toBeTrue()
        ->and($account->clientSite->is($context['site']))->toBeTrue()
        ->and($account->provider->is($provider))->toBeTrue()
        ->and($account->metadata_json['property_type'])->toBe('domain');
});

it('encrypts connector credential and token fields', function () {
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'google_analytics_4']);
    $account = makeConnectorAccount($context, $provider);

    $credential = ConnectorCredential::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_provider_id' => $provider->id,
        'credential_type' => ConnectorCredential::TYPE_OAUTH_CLIENT,
        'name' => 'GA4 OAuth Client',
        'encrypted_config' => [
            'client_id' => 'plain-client-id',
            'client_secret' => 'plain-client-secret',
        ],
        'status' => 'active',
        'metadata_json' => [],
    ]);

    $token = ConnectorToken::query()->create([
        'connector_account_id' => $account->id,
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
        'token_type' => 'Bearer',
    ]);

    $rawCredential = DB::table('connector_credentials')->where('id', $credential->id)->value('encrypted_config');
    $rawToken = DB::table('connector_tokens')->where('id', $token->id)->first();

    expect($credential->fresh()->encrypted_config['client_secret'])->toBe('plain-client-secret')
        ->and($token->fresh()->access_token)->toBe('plain-access-token')
        ->and(str_contains((string) $rawCredential, 'plain-client-secret'))->toBeFalse()
        ->and($rawToken->access_token)->not->toBe('plain-access-token')
        ->and($rawToken->refresh_token)->not->toBe('plain-refresh-token');
});

it('issues durable oauth state with csrf nonce binding pkce and one time consumption', function () {
    Config::set('data_connectors.oauth.state_ttl_minutes', 5);

    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_oauth']);
    $account = makeConnectorAccount($context, $provider);
    $service = app(ConnectorOAuthStateService::class);

    $issued = $service->issue([
        'workspace_id' => $context['workspace']->id,
        'user_id' => $context['user']->id,
        'connector_provider_id' => $provider->id,
        'connector_account_id' => $account->id,
        'provider_key' => $provider->provider_key,
        'redirect_uri' => 'https://app.example.test/oauth/callback',
        'scopes' => ['read', 'write'],
        'account_hint' => 'primary',
    ]);

    $raw = DB::table('connector_oauth_states')->where('id', $issued->record->id)->first();
    $record = ConnectorOAuthState::query()->findOrFail($issued->record->id);

    expect($issued->state)->not->toBe('')
        ->and($issued->nonce)->not->toBe('')
        ->and($issued->codeChallenge)->not->toBe($issued->codeVerifier)
        ->and($raw->state_hash)->toBe(hash('sha256', $issued->state))
        ->and($raw->nonce_hash)->toBe(hash('sha256', $issued->nonce))
        ->and($raw->pkce_code_verifier)->not->toBe($issued->codeVerifier)
        ->and($record->pkce_code_verifier)->toBe($issued->codeVerifier)
        ->and($record->workspace_id)->toBe($context['workspace']->id)
        ->and($record->user_id)->toBe($context['user']->id)
        ->and($record->connector_provider_id)->toBe($provider->id)
        ->and($record->connector_account_id)->toBe($account->id)
        ->and($record->scopes_json)->toBe(['read', 'write'])
        ->and($record->context_json['account_hint'])->toBe('primary');

    $consumed = $service->consume($issued->state, $issued->nonce);

    expect($consumed->consumed_at)->not->toBeNull()
        ->and($consumed->id)->toBe($record->id);

    expect(fn () => $service->consume($issued->state, $issued->nonce))
        ->toThrow(InvalidArgumentException::class, 'already been consumed');
});

it('rejects expired oauth states and invalid nonce values', function () {
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'expiring_oauth']);
    $service = app(ConnectorOAuthStateService::class);

    $issued = $service->issue(['provider_key' => $provider->provider_key]);

    expect(fn () => $service->consume($issued->state, 'wrong-nonce'))
        ->toThrow(InvalidArgumentException::class, 'nonce');

    ConnectorOAuthState::query()
        ->where('id', $issued->record->id)
        ->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $service->consume($issued->state, $issued->nonce))
        ->toThrow(InvalidArgumentException::class, 'expired');
});

it('generates generic oauth authorization urls with state nonce and pkce', function () {
    $context = makeConnectorPlatformContext();
    $definition = genericOAuthProviderDefinition('generic_authorizer');
    $registry = new DataConnectorRegistry(['generic_authorizer' => $definition], app(), app(ConnectorProviderConfigValidator::class));
    $generator = new ConnectorOAuthAuthorizationUrlGenerator(
        $registry,
        app(ConnectorOAuthStateService::class),
        app(ConnectorProviderConfigValidator::class),
    );

    $authorization = $generator->generate('generic_authorizer', [
        'workspace_id' => $context['workspace']->id,
        'user_id' => $context['user']->id,
        'scopes' => ['content.read'],
    ]);

    parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

    expect($authorization->url)->toStartWith('https://provider.example.test/oauth/authorize?')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('generic-client-id')
        ->and($query['redirect_uri'])->toBe('https://app.example.test/connectors/oauth/callback')
        ->and($query['scope'])->toBe('content.read')
        ->and($query['state'])->toBe($authorization->state->state)
        ->and($query['nonce'])->toBe($authorization->state->nonce)
        ->and($query['code_challenge'])->toBe($authorization->state->codeChallenge)
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($authorization->url)->not->toContain($authorization->state->codeVerifier)
        ->and($authorization->state->record->fresh()->scopes_json)->toBe(['content.read']);
});

it('stores exchanged oauth tokens encrypted and supports refresh and revoke lifecycle', function () {
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_token_provider']);
    $account = makeConnectorAccount($context, $provider);
    $state = app(ConnectorOAuthStateService::class)->issue([
        'workspace_id' => $context['workspace']->id,
        'user_id' => $context['user']->id,
        'connector_provider_id' => $provider->id,
        'connector_account_id' => $account->id,
        'provider_key' => $provider->provider_key,
        'redirect_uri' => 'https://app.example.test/connectors/oauth/callback',
    ]);

    $client = new class implements ConnectorOAuthTokenClient
    {
        public array $exchanges = [];

        public array $refreshes = [];

        public array $revocations = [];

        public function exchangeAuthorizationCode(array $oauthConfig, ConnectorOAuthState $state, string $code, array $payload = []): array
        {
            $this->exchanges[] = compact('oauthConfig', 'state', 'code', 'payload');

            return [
                'access_token' => 'plain-access-one',
                'refresh_token' => 'plain-refresh-one',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'content.read',
            ];
        }

        public function refreshAccessToken(array $oauthConfig, string $refreshToken, array $payload = []): array
        {
            $this->refreshes[] = compact('oauthConfig', 'refreshToken', 'payload');

            return [
                'access_token' => 'plain-access-two',
                'token_type' => 'Bearer',
                'expires_in' => 7200,
            ];
        }

        public function revokeToken(array $oauthConfig, string $token, array $payload = []): array
        {
            $this->revocations[] = compact('oauthConfig', 'token', 'payload');

            return ['revoked' => true];
        }
    };

    $manager = new ConnectorOAuthTokenManager(
        $client,
        app(ConnectorTokenVault::class),
        app(ConnectorProviderConfigValidator::class),
    );

    $oauthConfig = genericOAuthProviderDefinition('generic_token_provider')['config_json']['oauth'];
    $token = $manager->exchangeAndStore($account, $state->record->fresh(), 'auth-code-123', $oauthConfig);

    $rawToken = DB::table('connector_tokens')->where('id', $token->id)->first();

    expect($token->fresh()->access_token)->toBe('plain-access-one')
        ->and($rawToken->access_token)->not->toBe('plain-access-one')
        ->and($rawToken->refresh_token)->not->toBe('plain-refresh-one')
        ->and($token->rotation_metadata_json['oauth_state_id'])->toBe($state->record->id)
        ->and($client->exchanges[0]['code'])->toBe('auth-code-123');

    $refreshed = $manager->refresh($account, $oauthConfig);

    expect($refreshed->access_token)->toBe('plain-access-two')
        ->and($refreshed->refresh_token)->toBe('plain-refresh-one')
        ->and($token->fresh()->revoked_at)->not->toBeNull()
        ->and($client->refreshes[0]['refreshToken'])->toBe('plain-refresh-one');

    $manager->revoke($refreshed, $oauthConfig);

    expect($refreshed->fresh()->revoked_at)->not->toBeNull()
        ->and($client->revocations[0]['token'])->toBe('plain-access-two');
});

it('validates provider oauth configuration without requiring provider specific adapters', function () {
    $validator = app(ConnectorProviderConfigValidator::class);
    $validator->validateProviderDefinition('generic_config', genericOAuthProviderDefinition('generic_config'));

    expect(fn () => $validator->validateProviderDefinition('broken_config', [
        'provider_key' => 'broken_config',
        'name' => 'Broken Config',
        'supports_oauth' => true,
        'config_json' => [
            'oauth' => [
                'authorization_url' => 'https://provider.example.test/oauth/authorize',
                'client_id' => 'client-id',
                'redirect_uri' => 'https://app.example.test/callback',
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'token_url');

    expect(fn () => new DataConnectorRegistry([
        'mismatch' => [
            'provider_key' => 'other',
            'name' => 'Mismatch',
        ],
    ], app(), $validator))->toThrow(InvalidArgumentException::class, 'mismatched');
});

it('validates provider adapter class definitions generically', function () {
    $validator = app(ConnectorProviderConfigValidator::class);

    $valid = genericOAuthProviderDefinition('generic_fake');
    $valid['adapter'] = GenericFakeConnectorAdapter::class;

    $validator->validateProviderDefinition('generic_fake', $valid);

    $missing = $valid;
    $missing['adapter'] = 'App\\Services\\DataConnectors\\MissingConnectorAdapter';

    expect(fn () => $validator->validateProviderDefinition('generic_fake', $missing))
        ->toThrow(InvalidArgumentException::class, 'does not exist');

    $wrongContract = $valid;
    $wrongContract['adapter'] = GenericNonConnectorAdapter::class;

    expect(fn () => $validator->validateProviderDefinition('generic_fake', $wrongContract))
        ->toThrow(InvalidArgumentException::class, 'must implement DataConnectorAdapter');
});

it('supports provider agnostic fake adapters without provider specific branches', function () {
    $definition = genericOAuthProviderDefinition('generic_fake');
    $definition['adapter'] = GenericFakeConnectorAdapter::class;

    $registry = new DataConnectorRegistry(['generic_fake' => $definition], app(), app(ConnectorProviderConfigValidator::class));
    $adapter = $registry->adapter('generic_fake');

    $context = makeConnectorPlatformContext('connector-fake-provider');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_fake']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account, [
        'dataset_key' => 'generic_dataset',
        'dataset_type' => 'generic_resource',
    ]);
    $run = app(ConnectorSyncRunLogger::class)->start($account, $dataset);

    expect($adapter->providerKey())->toBe('generic_fake')
        ->and($adapter->discoverDatasets($account)[0]['dataset_key'])->toBe('generic_dataset');

    $adapter->syncDataset($dataset, $run);

    expect($run->fresh()->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED)
        ->and($run->fresh()->metrics_json['synced'])->toBeTrue();
});

it('logs sync run lifecycle transitions', function () {
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'google_search_console']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account);

    $logger = app(ConnectorSyncRunLogger::class);
    $run = $logger->start($account, $dataset, ConnectorSyncRun::TYPE_MANUAL, [
        'cursor_before_json' => ['page' => 1],
    ]);

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_RUNNING)
        ->and($run->attempts)->toBe(1)
        ->and($run->workspace_id)->toBe($context['workspace']->id);

    $logger->succeed($run, ['rows' => 42], ['page' => 2]);

    $run->refresh();
    $dataset->refresh();
    $account->refresh();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED)
        ->and($run->metrics_json['rows'])->toBe(42)
        ->and($run->cursor_after_json['page'])->toBe(2)
        ->and($run->finished_at)->not->toBeNull()
        ->and($dataset->last_sync_at)->not->toBeNull()
        ->and($account->last_synced_at)->not->toBeNull();
});

it('accepts valid sync run transitions and rejects invalid terminal rewrites', function () {
    $context = makeConnectorPlatformContext('connector-transition');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_transition']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account);
    $logger = app(ConnectorSyncRunLogger::class);

    $run = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_PENDING,
        'started_at' => null,
    ]);

    $logger->transition($run, ConnectorSyncRun::STATUS_RUNNING);
    $logger->succeed($run, ['rows' => 1]);

    expect($run->fresh()->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED);

    expect(fn () => $logger->transition($run->fresh(), ConnectorSyncRun::STATUS_RUNNING))
        ->toThrow(InvalidArgumentException::class, 'cannot transition');

    expect(fn () => $logger->fail($run->fresh(), 'Late failure rewrite.'))
        ->toThrow(InvalidArgumentException::class, 'cannot transition');
});

it('cancels pending or running sync runs without allowing later success', function () {
    $context = makeConnectorPlatformContext('connector-cancel');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_cancel']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account);
    $logger = app(ConnectorSyncRunLogger::class);
    $run = $logger->start($account, $dataset);

    $logger->cancel($run, 'User cancelled sync.', ['requested_by' => 'test-user']);

    $run->refresh();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_CANCELLED)
        ->and($run->cancelled_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->retry_json['cancelled'])->toBeTrue()
        ->and($run->retry_json['requested_by'])->toBe('test-user');

    expect(fn () => $logger->succeed($run, ['rows' => 5]))
        ->toThrow(InvalidArgumentException::class, 'cannot transition');
});

it('recovers stale running sync runs as stale failures', function () {
    $context = makeConnectorPlatformContext('connector-stale');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_stale']);
    $account = makeConnectorAccount($context, $provider);
    $logger = app(ConnectorSyncRunLogger::class);

    $stale = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'status' => ConnectorSyncRun::STATUS_RUNNING,
        'started_at' => now()->subHours(3),
        'attempts' => 1,
    ]);

    $fresh = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'status' => ConnectorSyncRun::STATUS_RUNNING,
        'started_at' => now()->subMinutes(5),
        'attempts' => 1,
    ]);

    expect($logger->recoverStaleRunning(now()->subHour()))->toBe(1);

    expect($stale->fresh()->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($stale->fresh()->isStaleFailure())->toBeTrue()
        ->and($stale->fresh()->retry_json['stale'])->toBeTrue()
        ->and($fresh->fresh()->status)->toBe(ConnectorSyncRun::STATUS_RUNNING);
});

it('records generic retry and backoff metadata without storing secrets', function () {
    $context = makeConnectorPlatformContext('connector-retry');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_retry']);
    $account = makeConnectorAccount($context, $provider);
    $run = app(ConnectorSyncRunLogger::class)->start($account);
    $nextRetryAt = now()->addMinutes(10);

    app(ConnectorSyncRunLogger::class)->recordRetryBackoff($run, [
        'retryable' => true,
        'strategy' => 'exponential_backoff',
        'attempt' => 2,
        'backoff_seconds' => 600,
        'access_token' => 'plain-secret-token',
    ], $nextRetryAt);

    $run->refresh();

    expect($run->next_retry_at?->isSameSecond($nextRetryAt))->toBeTrue()
        ->and($run->retry_json['retryable'])->toBeTrue()
        ->and($run->retry_json['strategy'])->toBe('exponential_backoff')
        ->and($run->retry_json['access_token'])->toBe('[redacted]');
});

it('records connector health events', function () {
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'linkedin']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account, ['dataset_key' => 'engagement']);

    $event = app(ConnectorHealthService::class)->record(
        account: $account,
        severity: ConnectorHealthEvent::SEVERITY_WARNING,
        eventType: 'token.near_expiry',
        message: 'Token expires soon.',
        context: ['expires_in_minutes' => 20],
        dataset: $dataset,
    );

    expect($event->workspace_id)->toBe($context['workspace']->id)
        ->and($event->connector_dataset_id)->toBe($dataset->id)
        ->and($event->severity)->toBe(ConnectorHealthEvent::SEVERITY_WARNING)
        ->and($event->context_json['expires_in_minutes'])->toBe(20);
});

it('keeps health events append only while rolling latest health to accounts and datasets', function () {
    $context = makeConnectorPlatformContext('connector-health-rollup');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_health']);
    $account = makeConnectorAccount($context, $provider);
    $dataset = makeConnectorDataset($account);
    $health = app(ConnectorHealthService::class);

    $warning = $health->record(
        account: $account,
        severity: ConnectorHealthEvent::SEVERITY_WARNING,
        eventType: 'sync.warning',
        message: 'Sync is delayed.',
        context: ['access_token' => 'plain-secret-token'],
        dataset: $dataset,
    );
    $resolved = $health->resolve($account, 'Sync recovered.', ['note' => 'manual retry'], $dataset);

    $account->refresh();
    $dataset->refresh();

    expect(ConnectorHealthEvent::query()->where('connector_account_id', $account->id)->count())->toBe(2)
        ->and(ConnectorHealthEvent::query()->find($warning->id))->not->toBeNull()
        ->and($warning->fresh()->context_json['access_token'])->toBe('[redacted]')
        ->and($account->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY)
        ->and($account->health_severity)->toBe(ConnectorHealthEvent::SEVERITY_INFO)
        ->and($account->latest_health_event_id)->toBe($resolved->id)
        ->and($dataset->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY)
        ->and($dataset->health_severity)->toBe(ConnectorHealthEvent::SEVERITY_INFO)
        ->and($dataset->latest_health_event_id)->toBe($resolved->id)
        ->and($health->latestForAccount($account)?->id)->toBe($resolved->id)
        ->and($health->latestForDataset($dataset)?->id)->toBe($resolved->id);
});

it('rolls critical health to account only when no dataset is attached', function () {
    $context = makeConnectorPlatformContext('connector-health-account');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'generic_health_account']);
    $account = makeConnectorAccount($context, $provider);
    $event = app(ConnectorHealthService::class)->record(
        account: $account,
        severity: ConnectorHealthEvent::SEVERITY_CRITICAL,
        eventType: 'oauth.revoked',
        message: 'Connector authorization was revoked.',
    );

    expect($account->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_CRITICAL)
        ->and($account->fresh()->health_severity)->toBe(ConnectorHealthEvent::SEVERITY_CRITICAL)
        ->and($account->fresh()->latest_health_event_id)->toBe($event->id);
});

it('exposes health retention configuration as a non destructive placeholder', function () {
    Config::set('data_connectors.health.retention.enabled', true);
    Config::set('data_connectors.health.retention.days', 45);

    expect(app(ConnectorHealthService::class)->retentionPolicy())->toMatchArray([
        'enabled' => true,
        'days' => 45,
        'destructive_cleanup_enabled' => false,
    ]);
});

it('keeps connector records isolated by tenant workspace', function () {
    $first = makeConnectorPlatformContext('connector-tenant-a');
    $second = makeConnectorPlatformContext('connector-tenant-b');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'google_search_console']);

    $firstAccount = makeConnectorAccount($first, $provider, ['account_name' => 'Tenant A']);
    $secondAccount = makeConnectorAccount($second, $provider, ['account_name' => 'Tenant B']);

    $visible = ConnectorAccount::query()
        ->forWorkspace($first['workspace'])
        ->pluck('id')
        ->all();

    expect($visible)->toBe([$firstAccount->id])
        ->and($visible)->not->toContain($secondAccount->id);

    $this->actingAs($first['user'])
        ->get(route('app.connectors.show', $secondAccount))
        ->assertNotFound();
});

it('protects connector account dataset and sync run policies', function () {
    $ownerContext = makeConnectorPlatformContext('connector-policy-owner');
    $otherContext = makeConnectorPlatformContext('connector-policy-other');
    $provider = ConnectorProvider::factory()->create(['provider_key' => 'linkedin']);
    $account = makeConnectorAccount($ownerContext, $provider);
    $dataset = makeConnectorDataset($account);
    $run = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'dataset_key' => $dataset->dataset_key,
    ]);

    $viewer = User::query()->create([
        'name' => 'Connector Viewer',
        'email' => 'connector-viewer+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $ownerContext['organization']->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
    ]);

    expect(Gate::forUser($ownerContext['user'])->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $dataset))->toBeTrue()
        ->and(Gate::forUser($viewer)->denies('update', $dataset))->toBeTrue()
        ->and(Gate::forUser($ownerContext['user'])->allows('view', $run))->toBeTrue()
        ->and(Gate::forUser($otherContext['user'])->denies('view', $account))->toBeTrue()
        ->and(Gate::forUser($otherContext['user'])->denies('view', $dataset))->toBeTrue()
        ->and(Gate::forUser($otherContext['user'])->denies('view', $run))->toBeTrue();
});

it('loads connector index and detail pages', function () {
    $this->seed(ConnectorProviderSeeder::class);
    $context = makeConnectorPlatformContext();
    $provider = ConnectorProvider::query()->where('provider_key', 'google_search_console')->firstOrFail();
    $account = makeConnectorAccount($context, $provider, ['account_name' => 'Primary GSC']);
    makeConnectorDataset($account, ['display_name' => 'example.test']);
    app(ConnectorHealthService::class)->record(
        account: $account,
        severity: ConnectorHealthEvent::SEVERITY_WARNING,
        eventType: 'dataset.discovery_deferred',
        message: 'Google Search Console returned 0 properties for this OAuth token.',
    );

    $this->actingAs($context['user'])
        ->get(route('app.connectors.index'))
        ->assertOk()
        ->assertSee('Data connectors')
        ->assertSee('Google Search Console')
        ->assertSee('Connect')
        ->assertSee('Primary GSC');

    $this->actingAs($context['user'])
        ->get(route('app.connectors.show', $account))
        ->assertOk()
        ->assertSee('Primary GSC')
        ->assertSee('Datasets')
        ->assertSee('Sync run history')
        ->assertSee('Manual Sync')
        ->assertSee('Latest health event')
        ->assertSee('Google Search Console returned 0 properties for this OAuth token.')
        ->assertSee('example.test');
});

function makeConnectorPlatformContext(string $slug = 'connector-platform'): array
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
        ['key' => 'connector-platform-plan'],
        [
            'name' => 'Connector Platform Plan',
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
        'name' => 'Connector Owner',
        'email' => 'connector-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'user' => $user,
    ];
}

function makeConnectorAccount(array $context, ConnectorProvider $provider, array $overrides = []): ConnectorAccount
{
    return ConnectorAccount::query()->create(array_merge([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Connector Account',
        'external_account_id' => 'external-'.Str::lower(Str::random(8)),
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => 'healthy',
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'latest_health_event_id' => null,
        'health_checked_at' => null,
        'metadata_json' => [],
    ], $overrides));
}

function makeConnectorDataset(ConnectorAccount $account, array $overrides = []): ConnectorDataset
{
    return ConnectorDataset::query()->create(array_merge([
        'connector_account_id' => $account->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'dataset_key' => 'default',
        'dataset_type' => 'property',
        'external_dataset_id' => 'dataset-'.Str::lower(Str::random(8)),
        'display_name' => 'Default dataset',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'health_status' => 'healthy',
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'latest_health_event_id' => null,
        'health_checked_at' => null,
        'config_json' => [],
        'metadata_json' => [],
    ], $overrides));
}

function genericOAuthProviderDefinition(string $providerKey): array
{
    return [
        'provider_key' => $providerKey,
        'name' => 'Generic OAuth Provider',
        'category' => ConnectorProvider::CATEGORY_OTHER,
        'status' => ConnectorProvider::STATUS_ACTIVE,
        'supports_oauth' => true,
        'supports_sync' => true,
        'supports_webhooks' => false,
        'adapter' => null,
        'config_json' => [
            'required_scopes' => ['content.read', 'content.write'],
            'oauth' => [
                'authorization_url' => 'https://provider.example.test/oauth/authorize',
                'token_url' => 'https://provider.example.test/oauth/token',
                'revoke_url' => 'https://provider.example.test/oauth/revoke',
                'client_id' => 'generic-client-id',
                'client_secret' => 'generic-client-secret',
                'redirect_uri' => 'https://app.example.test/connectors/oauth/callback',
                'scopes' => ['content.read', 'content.write'],
                'scope_separator' => ' ',
                'include_nonce' => true,
                'authorization_params' => [
                    'access_type' => 'offline',
                ],
            ],
        ],
    ];
}

final class GenericFakeConnectorAdapter implements DataConnectorAdapter
{
    public function providerKey(): string
    {
        return 'generic_fake';
    }

    public function discoverDatasets(ConnectorAccount $account): array
    {
        return [[
            'provider_key' => $account->provider_key,
            'dataset_key' => 'generic_dataset',
            'dataset_type' => 'generic_resource',
            'display_name' => 'Generic dataset',
        ]];
    }

    public function syncDataset(ConnectorDataset $dataset, ConnectorSyncRun $run): ConnectorSyncRun
    {
        return app(ConnectorSyncRunLogger::class)->succeed($run, [
            'synced' => true,
            'dataset_key' => $dataset->dataset_key,
        ]);
    }
}

final class GenericNonConnectorAdapter
{
}
