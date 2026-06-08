<?php

use App\Models\ApiKey;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use App\Services\Sites\SiteApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows site-linked legacy credentials in developer api view without exposing full secrets', function () {
    [$owner, $workspace, $site] = makeDeveloperCredentialContext('dev-compat-ui');

    $plain = 'arg_site_' . Str::lower(Str::random(48));
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Primary WordPress plugin key',
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['briefs:read', 'drafts:read', 'content:push'],
        'abilities' => ['briefs:read', 'drafts:read', 'content:push'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('app.developer.api'))
        ->assertOk()
        ->assertSee('Connected integration credentials')
        ->assertSee('Legacy site key')
        ->assertSee('WordPress integration key')
        ->assertSee('Site: '.$site->name)
        ->assertSee(substr($plain, 0, 14))
        ->assertDontSee($plain);
});

it('shows legacy organization key and keeps legacy auth compatible', function () {
    [$owner, $workspace] = makeDeveloperCredentialContext('dev-compat-org-key');

    $plain = 'pl_org_' . Str::lower(Str::random(48));
    $workspace->organization->setApiKey($plain);
    $workspace->organization->api_enabled = true;
    $workspace->organization->save();

    $this->actingAs($owner)
        ->get(route('app.developer.api'))
        ->assertOk()
        ->assertSee('Legacy organization API key')
        ->assertSee('Legacy org key')
        ->assertDontSee($plain);

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Workspace-Id' => (string) $workspace->id,
    ])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.workspace.id', (string) $workspace->id);
});

it('imports legacy credentials with an idempotent backfill command', function () {
    [, $workspace, $site] = makeDeveloperCredentialContext('dev-compat-import');

    $plain = 'arg_site_' . Str::lower(Str::random(48));
    $token = SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Laravel connector key',
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['briefs:read'],
        'abilities' => ['briefs:read'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    $this->artisan('argusly:backfill-workspace-api-keys', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    $this->artisan('argusly:backfill-workspace-api-keys', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    expect(ApiKey::query()
        ->where('workspace_id', $workspace->id)
        ->where('is_legacy_import', true)
        ->where('origin_type', ApiKey::ORIGIN_TYPE_SITE_TOKEN)
        ->where('origin_id', (string) $token->id)
        ->count())->toBe(1);
});

it('blocks revoking imported legacy credentials from developer ui', function () {
    [$owner, $workspace, $site] = makeDeveloperCredentialContext('dev-compat-revoke');

    $plain = 'arg_site_' . Str::lower(Str::random(48));
    $token = SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Primary key',
        'token_hash' => hash('sha256', $plain),
        'token_encrypted' => Crypt::encryptString($plain),
        'key_prefix' => substr($plain, 0, 14),
        'scopes' => ['briefs:read'],
        'abilities' => ['briefs:read'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    $this->artisan('argusly:backfill-workspace-api-keys', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    $imported = ApiKey::query()
        ->where('workspace_id', $workspace->id)
        ->where('is_legacy_import', true)
        ->where('origin_type', ApiKey::ORIGIN_TYPE_SITE_TOKEN)
        ->where('origin_id', (string) $token->id)
        ->firstOrFail();

    $this->actingAs($owner)
        ->post(route('app.developer.api-keys.revoke', $imported))
        ->assertRedirect()
        ->assertSessionHasErrors('api_key');
});

it('keeps new workspace api keys operational after compatibility changes', function () {
    [, $workspace] = makeDeveloperCredentialContext('dev-compat-new-keys');

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Headless key',
        scopes: [ApiScopes::USAGE_READ],
    );

    $this->withHeader('Authorization', 'Bearer ' . $created['plain_text_key'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.workspace.id', (string) $workspace->id);
});

it('generates Argusly prefixes for new site and workspace credentials', function () {
    [, $workspace, $site] = makeDeveloperCredentialContext('dev-compat-argusly-prefixes');

    [, $sitePlain] = app(SiteApiKeyService::class)->createForSite($site, ['heartbeat:write']);
    $workspaceKey = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Argusly workspace key',
        scopes: [ApiScopes::USAGE_READ],
    );

    expect($sitePlain)->toStartWith('arg_site_')
        ->and($workspaceKey['plain_text_key'])->toStartWith('arg_ws_');
});

it('denies developer credential access for unauthorized roles', function () {
    [, $workspace] = makeDeveloperCredentialContext('dev-compat-authz');

    $editor = User::query()->create([
        'name' => 'Developer Editor',
        'email' => 'developer-editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $workspace->organization_id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($editor)
        ->get(route('app.developer.api'))
        ->assertStatus(403);
});

function makeDeveloperCredentialContext(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Developer Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Developer Org BV',
        'billing_address_line1' => 'Integrationstraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Developer Workspace',
        'display_name' => 'Developer Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Demo Integration Site',
        'site_url' => 'https://developer-' . Str::lower(Str::random(6)) . '.example.com',
        'base_url' => 'https://developer-' . Str::lower(Str::random(6)) . '.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Developer Test Plan',
            'slug' => $prefix . '-plan',
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
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Developer Owner',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$owner, $workspace, $site];
}
