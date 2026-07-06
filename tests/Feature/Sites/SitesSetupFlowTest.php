<?php

use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\LicenseKey;
use App\Models\Plan;
use App\Models\PluginRelease;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\CreditWalletService;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a site within workspace max_sites limit', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'feature_key' => 'wp_sites_limit',
        'value_type' => 'int',
        'value_int' => 2,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'refreshed_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('app.sites.store'), [
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Primary WP',
        'site_url' => 'https://example.com/',
    ]);

    $response->assertRedirect();

    $site = ClientSite::query()->where('workspace_id', $workspace->id)->where('name', 'Primary WP')->first();
    expect($site)->not->toBeNull();
    expect((string) $site->base_url)->toBe('https://example.com');

    $token = SiteToken::query()->where('client_site_id', $site->id)->first();
    expect($token)->not->toBeNull();
    expect($token->token_hash)->not->toBe('');
});

it('uses active platform plan included sites when no site entitlement has been refreshed', function () {
    [, $workspace] = makeManagerWithWorkspace();

    $plan = Subscription::query()
        ->where('workspace_id', $workspace->id)
        ->firstOrFail()
        ->plan;

    $plan->forceFill([
        'limits' => [
            'sites' => 2,
            'users' => 5,
            'extra_site_price_cents' => 2900,
        ],
        'seat_limit' => 5,
    ])->save();

    ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Existing',
        'site_url' => 'https://existing.example.com',
        'base_url' => 'https://existing.example.com',
        'allowed_domains' => ['existing.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $siteUsage = app(WorkspaceEntitlementsService::class)->siteUsage($workspace);

    expect($siteUsage['max_sites'])->toBe(2)
        ->and($siteUsage['sites_used'])->toBe(1)
        ->and($siteUsage['sites_remaining'])->toBe(1)
        ->and($siteUsage['site_limit_reached'])->toBeFalse()
        ->and($siteUsage['extra_site_price_cents'])->toBe(2900);
});

it('shows site type selector on add site form', function () {
    [$user] = makeManagerWithWorkspace();

    $this->actingAs($user)
        ->get(route('app.sites'))
        ->assertOk()
        ->assertSee('Site type')
        ->assertSee('WordPress')
        ->assertSee('Laravel');
});

it('blocks creating a site when max_sites limit is exceeded', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'feature_key' => 'wp_sites_limit',
        'value_type' => 'int',
        'value_int' => 1,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'refreshed_at' => now(),
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Existing',
        'site_url' => 'https://existing.example.com',
        'base_url' => 'https://existing.example.com',
        'allowed_domains' => ['existing.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $response = $this->from(route('app.sites'))->actingAs($user)->post(route('app.sites.store'), [
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Second',
        'site_url' => 'https://second.example.com',
    ]);

    $response->assertRedirect(route('app.sites'));
    $response->assertSessionHasErrors(['sites']);
});

it('blocks creating a site in a second workspace when organization max_sites is reached', function () {
    [$user, $workspaceA] = makeManagerWithWorkspace();

    $workspaceB = Workspace::query()->create([
        'name' => 'Second WS',
        'organization_id' => $workspaceA->organization_id,
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceA->id,
        'organization_id' => $workspaceA->organization_id,
        'feature_key' => 'wp_sites_limit',
        'value_type' => 'int',
        'value_int' => 1,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'refreshed_at' => now(),
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspaceA->id,
        'type' => 'wordpress',
        'name' => 'Existing org site',
        'site_url' => 'https://existing-org.example.com',
        'base_url' => 'https://existing-org.example.com',
        'allowed_domains' => ['existing-org.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $response = $this->from(route('app.sites'))->actingAs($user)->post(route('app.sites.store'), [
        'workspace_id' => $workspaceB->id,
        'type' => 'wordpress',
        'name' => 'Should be blocked',
        'site_url' => 'https://second-ws.example.com',
    ]);

    $response->assertRedirect(route('app.sites'));
    $response->assertSessionHasErrors(['sites']);

    expect(ClientSite::query()->where('workspace_id', $workspaceB->id)->count())->toBe(0);
});

it('stores hashed api keys and authenticates bearer requests', function () {
    $workspace = makeWorkspaceOnly();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Auth Site',
        'site_url' => 'https://auth.example.com',
        'base_url' => 'https://auth.example.com',
        'allowed_domains' => ['auth.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));

    $token = SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Auth key',
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['drafts:read'],
        'abilities' => ['drafts:read'],
        'revoked' => false,
    ]);

    expect($token->token_hash)->not->toBe($plain);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'auth.example.com',
    ])->getJson('/api/v1/drafts');

    $response->assertOk();
    $token->refresh();
    expect($token->last_used_at)->not->toBeNull();
});

it('updates the name of an active site from the app sites detail page', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Demo WordPress',
        'site_url' => 'https://demo.example.com',
        'base_url' => 'https://demo.example.com',
        'allowed_domains' => ['demo.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $response = $this->actingAs($user)->post(route('app.sites.update', $site), [
        'name' => 'Demo Workspace',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Site name updated.');

    expect($site->fresh()->name)->toBe('Demo Workspace');
});

it('updates site status to connected on wp heartbeat', function () {
    $workspace = makeWorkspaceOnly();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Heartbeat Site',
        'site_url' => 'https://hb.example.com',
        'base_url' => 'https://hb.example.com',
        'allowed_domains' => ['hb.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'site_url' => 'https://hb.example.com',
        'wp_version' => '6.7.1',
        'plugin_version' => '1.2.0',
        'capabilities' => ['briefs' => true],
    ]);

    $response->assertOk();

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
    expect((string) $site->wp_version)->toBe('6.7.1');
    expect((string) $site->plugin_version)->toBe('1.2.0');
});

it('checks laravel connector activity endpoint using the site key payload', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Connector Site',
        'site_url' => 'https://laravel.argusly.local',
        'base_url' => 'https://laravel.argusly.local',
        'allowed_domains' => ['laravel.argusly.local'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    Http::fake([
        'https://laravel.argusly.local/argusly/connector/activity' => Http::response([
            'last_webhook_received_at' => now()->subMinutes(5)->toIso8601String(),
            'last_processed_at' => now()->subMinutes(4)->toIso8601String(),
            'last_heartbeat_at' => now()->subMinute()->toIso8601String(),
            'recent_events_count_24h' => 5,
            'failed_events_count_24h' => 0,
        ], 200),
    ]);

    $this->actingAs($user)
        ->from(route('app.sites.show', $site))
        ->post(route('app.sites.test-laravel', $site))
        ->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHas('status', 'Laravel connector activity check succeeded.');

    Http::assertSent(function ($request) use ($plain): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://laravel.argusly.local/argusly/connector/activity'
            && $request['site_key'] === $plain
            && $request['site_token'] === $plain
            && is_string($request['site_id'] ?? null)
            && is_string($request['workspace_id'] ?? null);
    });

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
    expect($site->last_error)->toBeNull();
    expect($site->last_healthcheck_at)->not->toBeNull();
});

it('retries laravel connector activity with another active site key when first key is rejected', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Connector Retry Site',
        'site_url' => 'https://laravel.argusly.local',
        'base_url' => 'https://laravel.argusly.local',
        'allowed_domains' => ['laravel.argusly.local'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $oldPlain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $oldPlain),
        'token_encrypted' => Crypt::encryptString($oldPlain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
        'last_used_at' => now()->subMinutes(30),
    ]);

    $newPlain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $newPlain),
        'token_encrypted' => Crypt::encryptString($newPlain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
        'last_used_at' => now(),
    ]);

    Http::fake([
        'https://laravel.argusly.local/argusly/connector/activity' => function ($request) use ($newPlain) {
            if (($request['site_key'] ?? null) === $newPlain) {
                return Http::response([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'site_key' => ['The selected site_key is invalid.'],
                    ],
                ], 422);
            }

            return Http::response([
                'last_webhook_received_at' => now()->subMinutes(3)->toIso8601String(),
                'last_processed_at' => now()->subMinutes(2)->toIso8601String(),
                'last_heartbeat_at' => now()->subMinute()->toIso8601String(),
                'recent_events_count_24h' => 2,
                'failed_events_count_24h' => 0,
            ], 200);
        },
    ]);

    $this->actingAs($user)
        ->from(route('app.sites.show', $site))
        ->post(route('app.sites.test-laravel', $site))
        ->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHas('status', 'Laravel connector activity check succeeded.');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => ($request['site_key'] ?? null) === $newPlain);
    Http::assertSent(fn ($request): bool => ($request['site_key'] ?? null) === $oldPlain
        && ($request['site_id'] ?? null) === (string) $site->id
        && ($request['workspace_id'] ?? null) === (string) $workspace->id);

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
    expect($site->last_error)->toBeNull();
});

it('shows connector validation errors when laravel activity payload is rejected', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Validation Site',
        'site_url' => 'https://laravel.argusly.local',
        'base_url' => 'https://laravel.argusly.local',
        'allowed_domains' => ['laravel.argusly.local'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    Http::fake([
        'https://laravel.argusly.local/argusly/connector/activity' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => [
                'site_key' => ['The selected site_key is invalid.'],
            ],
        ], 422),
    ]);

    $this->actingAs($user)
        ->from(route('app.sites.show', $site))
        ->post(route('app.sites.test-laravel', $site))
        ->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHasErrors(['sites']);

    $message = session('errors')?->first('sites') ?? '';
    expect($message)->toContain('The selected site_key is invalid.');
    expect($message)->toContain('(HTTP 422)');

    $site->refresh();
    expect((string) $site->status)->toBe('error');
    expect((string) $site->last_error)->toContain('The selected site_key is invalid.');
});

it('shows no recent laravel connector activity without a synthetic http 422', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Quiet Site',
        'site_url' => 'https://quiet-laravel.argusly.local',
        'base_url' => 'https://quiet-laravel.argusly.local',
        'allowed_domains' => ['quiet-laravel.argusly.local'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
        'last_used_at' => now()->subHour(),
    ]);

    Http::fake([
        'https://quiet-laravel.argusly.local/argusly/connector/activity' => Http::response([
            'ok' => true,
            'last_webhook_received_at' => null,
            'last_processed_at' => null,
            'last_heartbeat_at' => null,
            'recent_events_count_24h' => 0,
            'failed_events_count_24h' => 0,
        ], 200),
        'https://quiet-laravel.argusly.local/argusly/activity' => Http::response([], 404),
    ]);

    $this->actingAs($user)
        ->from(route('app.sites.show', $site))
        ->post(route('app.sites.test-laravel', $site))
        ->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHasErrors(['sites']);

    $message = session('errors')?->first('sites') ?? '';
    expect($message)->toContain('No recent Laravel connector activity detected for this site token.');
    expect($message)->not->toContain('(HTTP 422)');

    $site->refresh();
    expect((string) $site->status)->toBe('error');
    expect((string) $site->last_error)->toBe('No recent Laravel connector activity detected for this site token.');
});

it('shows wordpress-specific actions and hides laravel-specific actions on site detail', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'WP Site',
        'site_url' => 'https://wp.example.com',
        'base_url' => 'https://wp.example.com',
        'allowed_domains' => ['wp.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Push to WP')
        ->assertSee('Download WP plugin')
        ->assertSee('Generate update license')
        ->assertSee('Test connection')
        ->assertDontSee('Connector setup')
        ->assertDontSee('Check Laravel connector activity');
});

it('generates a one-time wordpress update license key from site detail', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Licensed WP Site',
        'site_url' => 'https://licensed-wp.example.com',
        'base_url' => 'https://licensed-wp.example.com',
        'allowed_domains' => ['licensed-wp.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $response = $this->actingAs($user)
        ->from(route('app.sites.show', $site))
        ->post(route('app.sites.plugin-license-key.generate', $site));

    $response->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHas('plugin_license_generated_for', (string) $site->id)
        ->assertSessionHas('plugin_license_plain_key');

    $plain = (string) session('plugin_license_plain_key');
    expect($plain)->toStartWith('pl_lic_')
        ->and(LicenseKey::query()->where('workspace_id', $workspace->id)->where('license_key_hash', hash('sha256', $plain))->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('WordPress update license key')
        ->assertSee($plain)
        ->assertSee('Rotate update license');
});

it('blocks wordpress update license generation for laravel sites', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Site',
        'site_url' => 'https://laravel-license.example.com',
        'base_url' => 'https://laravel-license.example.com',
        'allowed_domains' => ['laravel-license.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->post(route('app.sites.plugin-license-key.generate', $site))
        ->assertForbidden();

    expect(LicenseKey::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

it('downloads the latest wordpress plugin from app dashboard route', function () {
    [$user] = makeManagerWithWorkspace();

    Storage::fake('local');
    Storage::disk('local')->put('plugin-releases/argusly-wordpress-1.2.3.zip', 'zip-content');

    PluginRelease::query()->create([
        'version' => '1.0.0',
        'zip_storage_path' => 'plugin-releases/argusly-wordpress-1.0.0.zip',
        'min_wp_version' => null,
        'tested_wp_version' => null,
        'is_security_release' => false,
    ]);
    Storage::disk('local')->put('plugin-releases/argusly-wordpress-1.0.0.zip', 'old-zip-content');

    PluginRelease::query()->create([
        'version' => '1.2.3',
        'zip_storage_path' => 'plugin-releases/argusly-wordpress-1.2.3.zip',
        'min_wp_version' => null,
        'tested_wp_version' => null,
        'is_security_release' => false,
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.wordpress-plugin.download'))
        ->assertOk()
        ->assertDownload('argusly-wordpress-plugin-1.2.3.zip');
});

it('shows an error when wordpress plugin download is requested without a release', function () {
    [$user] = makeManagerWithWorkspace();

    $this->actingAs($user)
        ->get(route('app.sites.wordpress-plugin.download'))
        ->assertRedirect(route('app.sites'))
        ->assertSessionHasErrors(['sites']);
});

it('shows laravel-specific actions and hides wordpress-specific actions on site detail', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Site',
        'site_url' => 'https://laravel.example.com',
        'base_url' => 'https://laravel.example.com',
        'allowed_domains' => ['laravel.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Connector setup')
        ->assertSee('Test connection')
        ->assertSee('ARGUSLY_CONNECTOR_API_URL')
        ->assertSee('ARGUSLY_CONNECTOR_API_KEY')
        ->assertSee('ARGUSLY_CONNECTOR_WORKSPACE_ID')
        ->assertSee('ARGUSLY_CONNECTOR_DESTINATION_KEY')
        ->assertSee('ARGUSLY_CONNECTOR_SITE_NAME')
        ->assertSee('ARGUSLY_CONNECTOR_SITE_URL')
        ->assertSee('ARGUSLY_CONNECTOR_TIMEOUT')
        ->assertSee('ARGUSLY_CONNECTOR_WEBHOOKS_ENABLED')
        ->assertSee('ARGUSLY_CONNECTOR_WEBHOOK_SECRET')
        ->assertSee('Your normal Laravel scheduler is enough')
        ->assertDontSee('ARGUSLY_CONNECTOR_TOKEN')
        ->assertDontSee('ARGUSLY_CONNECTOR_SITE_ID')
        ->assertDontSee('extra connector')
        ->assertDontSee('Plesk cron')
        ->assertDontSee('Push to WP')
        ->assertDontSee('Test WP connection');
});

it('rejects mismatched site-type connection checks', function () {
    [$user, $workspace] = makeManagerWithWorkspace();

    $wordpressSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'WP',
        'site_url' => 'https://wp-guard.example.com',
        'base_url' => 'https://wp-guard.example.com',
        'allowed_domains' => ['wp-guard.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $laravelSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel',
        'site_url' => 'https://laravel-guard.example.com',
        'base_url' => 'https://laravel-guard.example.com',
        'allowed_domains' => ['laravel-guard.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post(route('app.sites.test-wordpress', $laravelSite))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('app.sites.test-laravel', $wordpressSite))
        ->assertForbidden();
});

it('blocks brief creation when entitlement is disabled or quota exceeded', function () {
    $workspace = makeWorkspaceOnly();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Quota Site',
        'site_url' => 'https://quota.example.com',
        'base_url' => 'https://quota.example.com',
        'allowed_domains' => ['quota.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'quota-plan',
        'name' => 'Quota Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 1,
        'is_active' => true,
    ]);

    \App\Models\Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 1,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'plan_id' => $plan->id,
        'feature_key' => 'can_generate_briefs',
        'value_type' => 'bool',
        'value_bool' => false,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'refreshed_at' => now(),
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write'],
        'abilities' => ['briefs:write'],
        'revoked' => false,
    ]);
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $payload = [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://quota.example.com',
            'wp_brief_id' => 'quota-1',
        ],
        'brief' => [
            'title' => 'Blocked brief',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
    ];

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'quota.example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $response->assertStatus(422);

    WorkspaceEntitlement::query()
        ->where('workspace_id', $workspace->id)
        ->where('feature_key', 'can_generate_briefs')
        ->delete();

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'plan_id' => $plan->id,
        'feature_key' => 'briefs_per_month',
        'value_type' => 'int',
        'value_int' => 0,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
        'refreshed_at' => now(),
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'quota.example.com',
    ])->postJson('/api/v1/briefs', array_replace_recursive($payload, [
        'client' => ['wp_brief_id' => 'quota-2'],
    ]));

    $response->assertStatus(422);
});

function makeManagerWithWorkspace(): array
{
    $organization = Organization::query()->create([
        'name' => 'Sites Org',
        'slug' => 'sites-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Sites Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Sites WS',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
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
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $workspace];
}

function makeWorkspaceOnly(): Workspace
{
    [, $workspace] = makeManagerWithWorkspace();

    BrandVoice::query()->firstOrCreate([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Default',
    ], [
        'default_language' => 'en',
        'default_tone' => 'Professional',
        'is_default' => true,
    ]);

    return $workspace;
}
