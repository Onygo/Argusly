<?php

use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('updates site fields on heartbeat', function () {
    $workspace = makeHeartbeatWorkspace();

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

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
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
    ])->postJson('/api/connector/heartbeat', [
        'platform' => 'laravel',
        'connector_version' => '0.1.0',
        'framework_version' => '11.35.0',
        'php_version' => '8.3.14',
        'app_url' => 'https://laravel.example.com',
        'capabilities' => ['draft_ready_webhook', 'image_download'],
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'ok',
            'site_id',
            'server_time',
            'next_recommended_heartbeat_seconds',
        ])
        ->assertJson([
            'ok' => true,
            'site_id' => $site->id,
            'next_recommended_heartbeat_seconds' => 300,
        ]);

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
    expect($site->connector_platform)->toBe('laravel');
    expect($site->connector_version)->toBe('0.1.0');
    expect($site->last_heartbeat_at)->not->toBeNull();
    expect($site->last_seen_at)->not->toBeNull();
    expect($site->is_active)->toBeTrue();
    expect(data_get($site->capabilities, 'agentic.create_content'))->toBeTrue();
    expect(data_get($site->capabilities, 'agentic.publish_content'))->toBeTrue();
    expect(data_get($site->capabilities, 'agentic.autonomous_allowed'))->toBeFalse();
});

it('stores explicit autonomous capability only when connector reports it', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Agentic WP',
        'site_url' => 'https://agentic-wp.example.com',
        'base_url' => 'https://agentic-wp.example.com',
        'allowed_domains' => ['agentic-wp.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer ' . $plain])
        ->postJson('/api/connector/heartbeat', [
            'platform' => 'wp',
            'connector_version' => '0.1.21-agentic',
            'capabilities' => [
                'agentic' => [
                    'autonomous_allowed' => true,
                    'rollback_last_update' => true,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('capabilities.agentic.autonomous_allowed', true)
        ->assertJsonPath('capabilities.agentic.rollback_last_update', true);

    $site->refresh();
    expect(data_get($site->capabilities, 'agentic.autonomous_allowed'))->toBeTrue()
        ->and(data_get($site->capabilities, 'agentic.preview_content'))->toBeTrue();
});

it('stores connector_meta with php_version and framework_version', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Meta Site',
        'site_url' => 'https://meta.example.com',
        'base_url' => 'https://meta.example.com',
        'allowed_domains' => ['meta.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
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
    ])->postJson('/api/connector/heartbeat', [
        'platform' => 'laravel',
        'connector_version' => '0.2.0',
        'framework_version' => '11.40.0',
        'php_version' => '8.4.0',
        'app_url' => 'https://meta.example.com',
        'environment' => 'production',
        'capabilities' => ['draft_ready_webhook'],
    ]);

    $response->assertOk();

    $site->refresh();
    expect($site->connector_meta)->toBeArray();
    expect($site->connector_meta['php_version'])->toBe('8.4.0');
    expect($site->connector_meta['framework_version'])->toBe('11.40.0');
    expect($site->connector_meta['app_url'])->toBe('https://meta.example.com');
    expect($site->connector_meta['environment'])->toBe('production');
    expect($site->connector_meta['capabilities'])->toBe(['draft_ready_webhook']);
});

it('rejects heartbeat with invalid token', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid_token_here',
    ])->postJson('/api/connector/heartbeat', [
        'platform' => 'laravel',
    ]);

    $response->assertUnauthorized();
});

it('rejects heartbeat without heartbeat:write scope', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'No Scope Site',
        'site_url' => 'https://noscope.example.com',
        'base_url' => 'https://noscope.example.com',
        'allowed_domains' => ['noscope.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:read'],
        'abilities' => ['drafts:read'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/connector/heartbeat', [
        'platform' => 'wp',
    ]);

    $response->assertForbidden();
});

it('accepts heartbeat on legacy /wp/heartbeat route', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Legacy WP Site',
        'site_url' => 'https://legacy.example.com',
        'base_url' => 'https://legacy.example.com',
        'allowed_domains' => ['legacy.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
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
    ])->postJson('/api/wp/heartbeat', [
        'site_url' => 'https://legacy.example.com',
        'wp_version' => '6.7.1',
        'plugin_version' => '1.3.0',
        'capabilities' => ['briefs' => true],
    ]);

    $response->assertOk()
        ->assertJson(['ok' => true]);

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
    expect((string) $site->wp_version)->toBe('6.7.1');
    expect((string) $site->plugin_version)->toBe('1.3.0');
});

it('defaults platform to wp on legacy /wp/heartbeat route', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Default Platform Site',
        'site_url' => 'https://defaultplat.example.com',
        'base_url' => 'https://defaultplat.example.com',
        'allowed_domains' => ['defaultplat.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    // No platform field provided
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/wp/heartbeat', [
        'site_url' => 'https://defaultplat.example.com',
    ]);

    $response->assertOk();

    $site->refresh();
    expect($site->connector_platform)->toBe('wp');
});

it('infers platform from site type when not provided', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Inferred Platform',
        'site_url' => 'https://inferred.example.com',
        'base_url' => 'https://inferred.example.com',
        'allowed_domains' => ['inferred.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    // No platform field provided, use /connector/heartbeat route
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/connector/heartbeat', [
        'connector_version' => '0.1.0',
    ]);

    $response->assertOk();

    $site->refresh();
    expect($site->connector_platform)->toBe('laravel');
});

it('rejects heartbeat with mismatched site_url', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Mismatched Site',
        'site_url' => 'https://correct.example.com',
        'base_url' => 'https://correct.example.com',
        'allowed_domains' => ['correct.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    // Middleware rejects mismatched site_url with 403 before reaching controller
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/connector/heartbeat', [
        'site_url' => 'https://wrong.example.com',
        'platform' => 'wp',
    ]);

    $response->assertForbidden()
        ->assertJson(['error' => 'Token site scope mismatch']);
});

it('computes heartbeat_status attribute correctly', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Status Test',
        'site_url' => 'https://status.example.com',
        'base_url' => 'https://status.example.com',
        'allowed_domains' => ['status.example.com'],
        'is_active' => true,
        'status' => 'connected',
        'last_heartbeat_at' => null,
    ]);

    // No heartbeat = offline
    expect($site->heartbeat_status)->toBe('offline');

    // Recent heartbeat = online
    $site->last_heartbeat_at = now()->subMinutes(5);
    $site->save();
    $site->refresh();
    expect($site->heartbeat_status)->toBe('online');

    // 15 minutes ago = warning
    $site->last_heartbeat_at = now()->subMinutes(15);
    $site->save();
    $site->refresh();
    expect($site->heartbeat_status)->toBe('warning');

    // 45 minutes ago = offline
    $site->last_heartbeat_at = now()->subMinutes(45);
    $site->save();
    $site->refresh();
    expect($site->heartbeat_status)->toBe('offline');
});

it('detects yoast provider and stores seo capability flags from heartbeat plugin list', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Yoast Site',
        'site_url' => 'https://yoast.example.com',
        'base_url' => 'https://yoast.example.com',
        'allowed_domains' => ['yoast.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/wp/heartbeat', [
        'site_url' => 'https://yoast.example.com',
        'plugins' => [
            'wordpress-seo/wp-seo.php',
            'publishlayer/publishlayer.php',
        ],
        'platform' => 'wp',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->seo_provider)->toBe('yoast');
    expect((bool) $site->supports_meta_title)->toBeTrue();
    expect((bool) $site->supports_meta_description)->toBeTrue();
    expect((bool) $site->supports_canonical)->toBeTrue();
    expect((bool) $site->supports_og_tags)->toBeTrue();
    expect((string) data_get($site->capabilities, 'seo.provider'))->toBe('yoast');
});

it('detects rankmath provider and stores seo capability flags from heartbeat plugin list', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'RankMath Site',
        'site_url' => 'https://rankmath.example.com',
        'base_url' => 'https://rankmath.example.com',
        'allowed_domains' => ['rankmath.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/wp/heartbeat', [
        'site_url' => 'https://rankmath.example.com',
        'active_plugins' => [
            'seo-by-rank-math/rank-math.php',
        ],
        'platform' => 'wp',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->seo_provider)->toBe('rankmath');
    expect((bool) $site->supports_meta_title)->toBeTrue();
    expect((bool) $site->supports_meta_description)->toBeTrue();
    expect((bool) $site->supports_canonical)->toBeTrue();
    expect((bool) $site->supports_og_tags)->toBeTrue();
    expect((string) data_get($site->capabilities, 'seo.provider'))->toBe('rankmath');
});

it('detects publishlayer provider from heartbeat payload when no third-party seo plugin is active', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'PublishLayer SEO Site',
        'site_url' => 'https://pl-seo.example.com',
        'base_url' => 'https://pl-seo.example.com',
        'allowed_domains' => ['pl-seo.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'pl_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/wp/heartbeat', [
        'site_url' => 'https://pl-seo.example.com',
        'plugins' => [
            'publishlayer/publishlayer.php',
        ],
        'capabilities' => [
            'seo' => [
                'provider' => 'publishlayer',
            ],
        ],
        'platform' => 'wp',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->seo_provider)->toBe('publishlayer');
    expect((bool) $site->supports_meta_title)->toBeTrue();
    expect((bool) $site->supports_meta_description)->toBeTrue();
    expect((bool) $site->supports_canonical)->toBeTrue();
    expect((bool) $site->supports_og_tags)->toBeTrue();
    expect((string) data_get($site->capabilities, 'seo.provider'))->toBe('publishlayer');
});

function makeHeartbeatWorkspace(): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Heartbeat Org',
        'slug' => 'hb-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Heartbeat Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Heartbeat WS',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'heartbeat-test-plan'],
        [
            'name' => 'Heartbeat Test Plan',
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
