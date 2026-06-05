<?php

use App\Models\FeatureFlag;
use App\Models\Notification as WorkspaceNotification;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows superadmin access to the refactored admin routes', function () {
    $superadmin = makeAdminUser('superadmin');

    $routes = [
        route('admin.dashboard'),
        route('admin.system-health.index'),
        route('admin.llm-monitor.index'),
        route('admin.queues.index'),
        route('admin.webhooks.index'),
        route('admin.sites'),
        route('admin.organizations'),
        route('admin.users'),
        route('admin.support.index'),
        route('admin.editorial-taxonomy.index'),
        route('admin.brand-profiles.index'),
        route('admin.content-policies.index'),
        route('admin.feature-flags.index'),
        route('admin.announcements.index'),
        route('admin.announcements.create'),
        route('admin.billing.index'),
        route('admin.invoices.index'),
    ];

    foreach ($routes as $url) {
        $this->actingAs($superadmin)->get($url)->assertOk();
    }
});

it('returns forbidden for non-admin users on refactored admin routes', function () {
    [, $client] = makeClientWorkspaceUser('forbidden-client');

    $this->actingAs($client)->get(route('admin.system-health.index'))->assertStatus(403);
    $this->actingAs($client)->get(route('admin.feature-flags.index'))->assertStatus(403);
    $this->actingAs($client)->get(route('admin.announcements.index'))->assertStatus(403);
});

it('renders admin sidebar with the new section layout and item order', function () {
    $superadmin = makeAdminUser('superadmin');

    $response = $this->actingAs($superadmin)->get(route('admin.dashboard'));

    $response->assertOk()
        ->assertSeeInOrder(['Platform', 'Customers', 'Product', 'Finance'])
        ->assertSeeInOrder(['Dashboard', 'System Health', 'LLM Monitor', 'Queues', 'Webhooks', 'Sites'])
        ->assertSeeInOrder(['Organizations', 'Users', 'Support'])
        ->assertSeeInOrder(['Editorial Taxonomy', 'Default Brand Profiles', 'Content Policies', 'Feature Flags', 'Announcements'])
        ->assertSeeInOrder(['Billing', 'Invoices'])
        ->assertDontSee('Operations');
});

it('allows admin users to toggle a feature flag', function () {
    $admin = makeAdminUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.feature-flags.store'), [
            'key' => 'new_test_flag',
            'description' => 'Test flag',
            'enabled' => 0,
        ])
        ->assertRedirect();

    $flag = FeatureFlag::query()->where('key', 'new_test_flag')->first();

    expect($flag)->not->toBeNull();
    expect((bool) $flag->enabled)->toBeFalse();

    $this->actingAs($admin)
        ->patch(route('admin.feature-flags.update', $flag), [
            'enabled' => 1,
            'description' => 'Updated test flag',
        ])
        ->assertRedirect();

    expect((bool) $flag->fresh()->enabled)->toBeTrue();
});

it('creates announcement notifications scoped to a workspace and visible only to that workspace users', function () {
    [$workspaceA, $clientA] = makeClientWorkspaceUser('audience-a');
    [$workspaceB, $clientB] = makeClientWorkspaceUser('audience-b');
    $superadmin = makeAdminUser('superadmin');

    $title = 'Launch wave announcement';

    $this->actingAs($superadmin)
        ->post(route('admin.announcements.store'), [
            'target' => 'selected',
            'workspace_ids' => [(string) $workspaceA->id],
            'title' => $title,
            'body' => 'Feature rollout starts today.',
            'cta_label' => 'View release notes',
            'cta_url' => '/dashboard',
        ])
        ->assertRedirect(route('admin.announcements.index'));

    $notification = WorkspaceNotification::query()
        ->workspaceScoped()
        ->where('workspace_id', (string) $workspaceA->id)
        ->where('type', WorkspaceNotification::TYPE_ANNOUNCEMENT)
        ->where('title', $title)
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->workspace_id)->toBe((string) $workspaceA->id);
    expect((string) $notification->target_scope)->toBe(WorkspaceNotification::TARGET_SCOPE_WORKSPACE);
    expect((bool) $notification->is_admin_only)->toBeFalse();

    $this->actingAs($clientA)
        ->get(route('app.notifications.index'))
        ->assertOk()
        ->assertSee($title);

    $this->actingAs($clientA)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee($title);

    $this->actingAs($clientB)
        ->get(route('app.notifications.index'))
        ->assertOk()
        ->assertDontSee($title);

    $this->actingAs($clientB)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee($title);

    expect(WorkspaceNotification::query()
        ->workspaceScoped()
        ->where('workspace_id', (string) $workspaceB->id)
        ->where('title', $title)
        ->exists())->toBeFalse();
});

function makeAdminUser(string $role): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Nav Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-nav-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $isAdmin = in_array($role, ['admin', 'superadmin'], true);

    return User::query()->create([
        'name' => ucfirst($role) . ' Nav User',
        'email' => $role . '-nav+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $isAdmin,
        'admin_role' => $role,
    ]);
}

function makeClientWorkspaceUser(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Client Org ' . $suffix,
        'slug' => 'client-org-' . $suffix . '-' . Str::lower(Str::random(4)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Client ' . $suffix,
        'billing_address_line1' => 'Clientstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace ' . $suffix,
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'admin-nav-test-plan'],
        [
            'name' => 'Admin Navigation Test Plan',
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
        'name' => 'Client User ' . $suffix,
        'email' => 'client-' . $suffix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    return [$workspace, $user];
}
