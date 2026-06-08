<?php

use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDestinationSyncAttempt;
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
            'data' => [
                'site_id',
            ],
            'meta',
            'errors',
        ])
        ->assertJson([
            'ok' => true,
            'data' => [
                'site_id' => $site->id,
            ],
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

it('accepts heartbeat on canonical Argusly connector endpoint', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Canonical Argusly Site',
        'site_url' => 'https://canonical.example.com',
        'base_url' => 'https://canonical.example.com',
        'allowed_domains' => ['canonical.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'laravel',
        'connector_version' => '0.1.0-argusly',
    ])->assertOk()
        ->assertJsonStructure(['ok', 'data', 'meta', 'errors'])
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.site_id', $site->id)
        ->assertJsonPath('meta.next_recommended_heartbeat_seconds', 300)
        ->assertJsonPath('errors', []);

    $site->refresh();
    expect($site->connector_platform)->toBe('laravel')
        ->and($site->connector_version)->toBe('0.1.0-argusly')
        ->and((string) $site->status)->toBe('connected');
});

it('accepts heartbeat token through X-Argusly-API-Key', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Argusly API Key Site',
        'site_url' => 'https://api-key.example.com',
        'base_url' => 'https://api-key.example.com',
        'allowed_domains' => ['api-key.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'X-Argusly-API-Key' => $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'wp',
        'connector_version' => '0.1.0',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->status)->toBe('connected')
        ->and($site->connector_platform)->toBe('wp');
});

it('accepts arg_site token prefixes on canonical heartbeat endpoint', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Argusly Prefix Site',
        'site_url' => 'https://argusly-prefix.example.com',
        'base_url' => 'https://argusly-prefix.example.com',
        'allowed_domains' => ['argusly-prefix.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'laravel',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
});

it('accepts the canonical X-Argusly-Site claim', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Preferred Header Site',
        'site_url' => 'https://preferred.example.com',
        'base_url' => 'https://preferred.example.com',
        'allowed_domains' => ['preferred.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['heartbeat:write'],
        'abilities' => ['heartbeat:write'],
        'revoked' => false,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'https://preferred.example.com',
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'laravel',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->status)->toBe('connected');
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

    $this->withHeaders(['Authorization' => 'Bearer ' . $plain])
        ->postJson('/api/v1/connectors/heartbeat', [
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
        ->assertJsonPath('data.capabilities.agentic.autonomous_allowed', true)
        ->assertJsonPath('data.capabilities.agentic.rollback_last_update', true);

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
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'laravel',
    ]);

    $response->assertUnauthorized()
        ->assertJsonStructure(['ok', 'data', 'meta', 'errors'])
        ->assertJsonPath('ok', false)
        ->assertJsonPath('data', null)
        ->assertJsonPath('errors.0.message', 'Invalid token');
});

it('returns content index with valid bearer site token', function () {
    [$workspace, $site, $plain] = makeConnectorContentContext(['content:read']);
    $content = makeConnectorContent($workspace, $site, [
        'title' => 'Canonical Connector Content',
        'publish_url_key' => 'canonical-connector-content',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'https://connector-content.example.com',
    ])->getJson('/api/v1/connectors/content?limit=10');

    $response->assertOk()
        ->assertJsonStructure(['ok', 'data', 'meta', 'errors'])
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('errors', [])
        ->assertJsonPath('data.0.id', (string) $content->id)
        ->assertJsonPath('data.0.slug', 'canonical-connector-content');
});

it('returns content detail with valid bearer site token', function () {
    [$workspace, $site, $plain] = makeConnectorContentContext(['content:read']);
    $content = makeConnectorContent($workspace, $site, [
        'title' => 'Detail Connector Content',
        'external_key' => 'detail-external-key',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'https://connector-content.example.com',
    ])->getJson('/api/v1/connectors/content/detail-external-key');

    $response->assertOk()
        ->assertJsonStructure(['ok', 'data', 'meta', 'errors'])
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.id', (string) $content->id)
        ->assertJsonPath('data.title', 'Detail Connector Content')
        ->assertJsonPath('data.rendered_markdown', "# Detail Connector Content\n\n- Locale: en\n- Published: " . now()->toDateString())
        ->assertJsonPath('errors', []);
});

it('acknowledges connector content sync results', function () {
    [$workspace, $site, $plain] = makeConnectorContentContext(['content:read', 'content:write']);
    $content = makeConnectorContent($workspace, $site, [
        'title' => 'Sync Result Content',
    ]);
    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'https://connector-content.example.com',
        'X-Argusly-Destination-Id' => $destination->id,
        'X-Argusly-Idempotency-Key' => 'ack-test-key-1',
    ])->postJson('/api/v1/connectors/content/' . $content->id . '/sync-results', [
        'status' => 'synced',
        'remote_id' => 'remote-123',
        'remote_url' => 'https://connector-content.example.com/posts/sync-result-content',
    ]);

    $response->assertAccepted()
        ->assertJsonStructure(['ok', 'data', 'meta', 'errors'])
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.content_id', (string) $content->id)
        ->assertJsonPath('data.status', 'synced')
        ->assertJsonPath('errors', []);

    $attempt = ContentDestinationSyncAttempt::query()->firstOrFail();
    expect((string) $attempt->workspace_id)->toBe((string) $workspace->id)
        ->and((string) $attempt->content_destination_id)->toBe((string) $destination->id)
        ->and((string) $attempt->content_id)->toBe((string) $content->id)
        ->and($attempt->sync_type)->toBe('connector_result')
        ->and($attempt->status)->toBe('synced')
        ->and($attempt->idempotency_key)->toBe('ack-test-key-1');
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

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
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
    ])->postJson('/api/v1/connectors/heartbeat', [
        'platform' => 'wp',
    ]);

    $response->assertForbidden();
});

it('accepts wordpress heartbeat payloads on the canonical endpoint', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Argusly WP Site',
        'site_url' => 'https://argusly-wp.example.com',
        'base_url' => 'https://argusly-wp.example.com',
        'allowed_domains' => ['argusly-wp.example.com'],
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
        'site_url' => 'https://argusly-wp.example.com',
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

it('infers wp platform from wordpress site type on the canonical endpoint', function () {
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

    // No platform field provided
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
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

    // No platform field provided, infer it from the site type.
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
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

    // Middleware rejects mismatched site_url with 403 before reaching controller
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'site_url' => 'https://wrong.example.com',
        'platform' => 'wp',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('errors.0.message', 'Token site scope mismatch');
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

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'site_url' => 'https://yoast.example.com',
        'plugins' => [
            'wordpress-seo/wp-seo.php',
            'argusly/argusly.php',
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

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
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

it('detects argusly provider from heartbeat payload when no third-party seo plugin is active', function () {
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Argusly SEO Site',
        'site_url' => 'https://argusly-seo.example.com',
        'base_url' => 'https://argusly-seo.example.com',
        'allowed_domains' => ['argusly-seo.example.com'],
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

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
    ])->postJson('/api/v1/connectors/heartbeat', [
        'site_url' => 'https://argusly-seo.example.com',
        'plugins' => [
            'argusly/argusly.php',
        ],
        'capabilities' => [
            'seo' => [
                'provider' => 'argusly',
            ],
        ],
        'platform' => 'wp',
    ])->assertOk();

    $site->refresh();
    expect((string) $site->seo_provider)->toBe('argusly');
    expect((bool) $site->supports_meta_title)->toBeTrue();
    expect((bool) $site->supports_meta_description)->toBeTrue();
    expect((bool) $site->supports_canonical)->toBeTrue();
    expect((bool) $site->supports_og_tags)->toBeTrue();
    expect((string) data_get($site->capabilities, 'seo.provider'))->toBe('argusly');
});

/**
 * @param array<int, string> $scopes
 * @return array{0: Workspace, 1: ClientSite, 2: string}
 */
function makeConnectorContentContext(array $scopes): array
{
    $workspace = makeHeartbeatWorkspace();

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Connector Content Site',
        'site_url' => 'https://connector-content.example.com',
        'base_url' => 'https://connector-content.example.com',
        'allowed_domains' => ['connector-content.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plain = 'arg_site_' . bin2hex(random_bytes(32));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => $scopes,
        'abilities' => $scopes,
        'revoked' => false,
    ]);

    return [$workspace, $site, $plain];
}

/**
 * @param array<string, mixed> $overrides
 */
function makeConnectorContent(Workspace $workspace, ClientSite $site, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Connector Content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'approved',
        'source' => 'api',
        'publish_status' => 'published',
        'published_url' => 'https://connector-content.example.com/posts/connector-content',
        'publish_url_key' => 'connector-content',
        'canonical_url_key' => 'connector-content',
    ], $overrides));
}

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
