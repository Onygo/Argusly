<?php

use App\Jobs\Integrations\DeliverApiWebhookJob;
use App\Models\ApiWebhook;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use App\Services\Integrations\ApiWebhookPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders the settings workspace hub sections without legacy api controls', function () {
    [$user, $workspace] = makeSettingsHubContext();

    $workspaceName = (string) ($workspace->display_name ?: $workspace->name);

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee('Workspace')
        ->assertSee('Experience')
        ->assertSee('Advanced Mode')
        ->assertSee('Notifications')
        ->assertSee('API access moved')
        ->assertSee('Open Developer settings')
        ->assertSee('Team')
        ->assertSee('Brand')
        ->assertDontSee('Enable API access')
        ->assertDontSee('Save API settings')
        ->assertDontSee('Regenerate API key')
        ->assertSee('Workspace: '.$workspaceName);
});

it('toggles advanced mode visibility through settings without changing permissions', function () {
    [$user] = makeSettingsHubContext();

    $this->actingAs($user)
        ->post(route('app.settings.advanced-mode.update'), [
            'advanced_mode' => '1',
        ])
        ->assertRedirect();

    $this->assertTrue((bool) session('app_advanced_mode_enabled'));

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('data-sidebar-title="Developer"', false)
        ->assertSee('data-sidebar-title="Billing"', false);

    $this->actingAs($user)
        ->post(route('app.settings.advanced-mode.update'), [
            'advanced_mode' => '0',
        ])
        ->assertRedirect();

    $this->assertFalse((bool) session('app_advanced_mode_enabled'));

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-title="Developer"', false)
        ->assertDontSee('data-sidebar-title="Billing"', false);
});

it('prevents non-advanced roles from enabling advanced mode', function () {
    [$user] = makeSettingsHubContext();
    $user->forceFill(['role' => 'viewer'])->save();

    $this->actingAs($user)
        ->post(route('app.settings.advanced-mode.update'), [
            'advanced_mode' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('advanced_mode');

    $this->assertFalse((bool) session('app_advanced_mode_enabled'));

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee('Advanced Mode unavailable')
        ->assertDontSee(route('app.developer.api'), false);

    $this->actingAs($user)
        ->withSession(['app_advanced_mode_enabled' => true])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('>ADVANCED<', false)
        ->assertDontSee('data-sidebar-title="Developer"', false)
        ->assertDontSee('data-sidebar-title="Billing"', false)
        ->assertDontSee('data-sidebar-title="Lifecycle"', false);
});

it('keeps developer settings hidden from editors even in advanced mode', function () {
    [$user] = makeSettingsHubContext();
    $user->forceFill(['role' => 'editor'])->save();

    $this->actingAs($user)
        ->post(route('app.settings.advanced-mode.update'), [
            'advanced_mode' => '1',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('>ADVANCED<', false)
        ->assertSee('data-sidebar-title="Lifecycle"', false)
        ->assertDontSee('data-sidebar-title="Developer"', false)
        ->assertDontSee('data-sidebar-title="Billing"', false);

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertDontSee(route('app.developer.api'), false);
});

it('keeps workspace and organization updates working', function () {
    [$user, $workspace, $organization] = makeSettingsHubContext();

    $this->actingAs($user)
        ->post(route('app.settings.workspace-name.update'), [
            'display_name' => 'Revenue Workspace',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.settings.organization'), [
            'name' => 'Revenue Labs',
            'custom_domain' => 'app.revenuelabs.test',
        ])
        ->assertRedirect();

    expect((string) $workspace->fresh()->display_name)->toBe('Revenue Workspace');
    expect((string) $organization->fresh()->name)->toBe('Revenue Labs');
    expect((string) $organization->fresh()->custom_domain)->toBe('app.revenuelabs.test');
});

it('keeps notification settings update working', function () {
    [$user, , $organization] = makeSettingsHubContext();

    $this->actingAs($user)
        ->post(route('app.settings.notifications'), [
            'brief_updates' => 1,
            'weekly_summary' => 1,
        ])
        ->assertRedirect();

    $settings = (array) $organization->fresh()->notification_settings;

    expect((bool) ($settings['brief_updates'] ?? false))->toBeTrue();
    expect((bool) ($settings['draft_ready'] ?? true))->toBeFalse();
    expect((bool) ($settings['weekly_summary'] ?? false))->toBeTrue();
});

it('redirects legacy settings api endpoints to developer api without mutating legacy fields', function () {
    [$user, , $organization] = makeSettingsHubContext();
    $organization->api_enabled = true;
    $organization->webhook_url = 'https://legacy-hooks.example.com/argusly';
    $organization->setApiKey('pl_org_'.Str::random(40));
    $organization->save();

    $originalLegacyKey = (string) $organization->api_key;

    $this->actingAs($user)
        ->get(route('app.settings.api.redirect'))
        ->assertRedirect(route('app.developer.api'));

    $this->actingAs($user)
        ->post(route('app.settings.api.redirect'), [
            'api_enabled' => 0,
            'webhook_url' => 'https://attempted-change.example.com/hook',
        ])
        ->assertRedirect(route('app.developer.api'));

    $this->actingAs($user)
        ->post(route('app.settings.api.regenerate.redirect'))
        ->assertRedirect(route('app.developer.api'));

    $organization->refresh();
    expect($organization->api_enabled)->toBeTrue();
    expect((string) $organization->webhook_url)->toBe('https://legacy-hooks.example.com/argusly');
    expect((string) $organization->api_key)->toBe($originalLegacyKey);
});

it('loads developer api page from dedicated route', function () {
    [$user] = makeSettingsHubContext();

    $this->actingAs($user)
        ->get(route('app.developer.api'))
        ->assertOk()
        ->assertSee('Developer')
        ->assertSee('Create API key')
        ->assertSee('Existing keys');
});

it('keeps existing workspace api keys valid for integration auth', function () {
    [$user, $workspace] = makeSettingsHubContext();

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Legacy integration key',
        scopes: [ApiScopes::USAGE_READ],
        createdBy: $user->id,
    );

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$created['plain_text_key'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.workspace.id', (string) $workspace->id);
});

it('keeps webhook delivery publishing behavior intact', function () {
    [$user, $workspace] = makeSettingsHubContext();

    $webhook = ApiWebhook::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Legacy workspace webhook',
        'target_url' => 'https://hooks.example.com/argusly',
        'secret' => 'super-secret-value-123456',
        'events' => ['draft.generation.completed'],
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    Queue::fake();

    app(ApiWebhookPublisher::class)->publish(
        workspace: $workspace,
        eventType: 'draft.generation.completed',
        payload: ['draft_id' => 'drft_123'],
    );

    Queue::assertPushed(DeliverApiWebhookJob::class, function (DeliverApiWebhookJob $job) use ($webhook): bool {
        return $job->webhookId === (string) $webhook->id
            && $job->eventType === 'draft.generation.completed';
    });
});

it('keeps the team invite flow working', function () {
    [$user, , $organization] = makeSettingsHubContext();

    $this->actingAs($user)
        ->post(route('app.settings.invites'), [
            'email' => 'new.member@example.com',
            'role' => 'viewer',
        ])
        ->assertRedirect();

    $invite = Invite::query()
        ->where('organization_id', $organization->id)
        ->where('email', 'new.member@example.com')
        ->first();

    expect($invite)->not->toBeNull();
    expect((string) $invite->role)->toBe('viewer');
});

it('renders brand shortcut links on settings', function () {
    [$user] = makeSettingsHubContext();

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee(route('app.brand.company-profile'), false)
        ->assertSee(route('app.brand.voices'), false);
});

it('renders developer navigation links for api, webhooks, and docs', function () {
    [$user] = makeSettingsHubContext();

    $this->actingAs($user)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee(route('app.developer.index'), false)
        ->assertSee(route('app.developer.api'), false)
        ->assertSee(route('app.developer.webhooks'), false)
        ->assertSee(route('app.developer.docs'), false);
});

it('keeps authorization boundaries for settings updates', function () {
    [, $workspace, $organization] = makeSettingsHubContext();

    $editor = User::query()->create([
        'name' => 'Readonly Editor',
        'email' => 'readonly-editor+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($editor)
        ->post(route('app.settings.organization'), [
            'name' => 'Blocked Org Rename',
            'custom_domain' => 'blocked.example.com',
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.settings.workspace-name.update'), [
            'display_name' => 'Blocked Workspace Name',
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.settings.notifications'), [
            'brief_updates' => 1,
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->get(route('app.developer.index'))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->get(route('app.developer.api'))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->get(route('app.developer.webhooks'))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->get(route('app.developer.docs'))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.developer.api-keys.store'), [
            'name' => 'Blocked key',
            'scopes' => [ApiScopes::USAGE_READ],
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.settings.invites'), [
            'email' => 'blocked.invite@example.com',
            'role' => 'viewer',
        ])
        ->assertStatus(403);

    expect((string) $workspace->fresh()->display_name)->toBe((string) ($workspace->display_name ?: $workspace->name));
});

function makeSettingsHubContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Settings Org',
        'slug' => 'settings-org-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Settings Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Settings Workspace',
        'display_name' => 'Settings Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'settings-test-plan'],
        [
            'name' => 'Settings Test Plan',
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
        'seat_limit' => 5,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Settings Owner',
        'email' => 'settings-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $organization];
}
