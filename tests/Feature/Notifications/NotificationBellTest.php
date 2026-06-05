<?php

use App\Models\Notification;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders only unread app notifications in the bell and refreshes correctly after reading one', function () {
    [$workspace, $user] = makeBellClientWorkspaceUser('app-bell-read');

    $unread = createWorkspaceNotification($workspace, [
        'title' => 'Unread workspace alert',
    ]);

    createWorkspaceNotification($workspace, [
        'title' => 'Already read workspace alert',
        'read_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Unread workspace alert')
        ->assertDontSee('Already read workspace alert');

    $response = $this->actingAs($user)
        ->postJson(route('app.notifications.read', $unread));

    $response->assertOk()
        ->assertJsonPath('notificationBell.unread_count', 0);

    expect($response->json('menu_html'))->toContain('No new notifications');
    expect($response->json('menu_html'))->not->toContain('Unread workspace alert');
    expect($unread->fresh()?->read_at)->not->toBeNull();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('Unread workspace alert')
        ->assertSee('No new notifications');
});

it('decrements the app bell unread count and keeps other users notifications untouched on mark all read', function () {
    [$workspace, $user] = makeBellClientWorkspaceUser('app-bell-mark-all');

    $otherUser = User::query()->create([
        'name' => 'Other Bell User',
        'email' => 'other-bell-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => 'secret',
        'organization_id' => $workspace->organization_id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    createWorkspaceNotification($workspace, [
        'title' => 'Workspace wide alert',
    ]);

    createWorkspaceNotification($workspace, [
        'title' => 'Personal unread alert',
        'user_id' => $user->id,
        'type' => Notification::TYPE_ACTION_REQUIRED,
    ]);

    $otherUsersNotification = createWorkspaceNotification($workspace, [
        'title' => 'Other user personal alert',
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('app.notifications.read-all'), [
            'workspace_id' => (string) $workspace->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('notificationBell.unread_count', 0);

    expect($response->json('menu_html'))->toContain('No new notifications');
    expect(Notification::query()
        ->where('workspace_id', (string) $workspace->id)
        ->where(function ($query) use ($user): void {
            $query->whereNull('user_id')
                ->orWhere('user_id', $user->id);
        })
        ->whereNull('read_at')
        ->count())->toBe(0);
    expect($otherUsersNotification->fresh()?->read_at)->toBeNull();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('Workspace wide alert')
        ->assertDontSee('Personal unread alert')
        ->assertSee('No new notifications');
});

it('renders only unread admin notifications in the bell and updates instantly after reading one', function () {
    $admin = makeBellAdminUser('superadmin');
    $otherAdmin = makeBellAdminUser('admin');

    $unread = Notification::query()->create([
        'target_scope' => Notification::TARGET_SCOPE_ADMIN,
        'is_admin_only' => true,
        'user_id' => null,
        'type' => Notification::TYPE_SYSTEM,
        'title' => 'Unread admin alert',
        'body' => 'Pending admin action.',
    ]);

    Notification::query()->create([
        'target_scope' => Notification::TARGET_SCOPE_ADMIN,
        'is_admin_only' => true,
        'user_id' => null,
        'type' => Notification::TYPE_SYSTEM,
        'title' => 'Read admin alert',
        'body' => 'Already handled.',
        'read_at' => now(),
    ]);

    $otherAdminsNotification = Notification::query()->create([
        'target_scope' => Notification::TARGET_SCOPE_ADMIN,
        'is_admin_only' => true,
        'user_id' => $otherAdmin->id,
        'type' => Notification::TYPE_ACTION_REQUIRED,
        'title' => 'Other admin private alert',
        'body' => 'Only for another admin.',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Unread admin alert')
        ->assertDontSee('Read admin alert')
        ->assertDontSee('Other admin private alert');

    $response = $this->actingAs($admin)
        ->postJson(route('admin.notifications.read', $unread));

    $response->assertOk()
        ->assertJsonPath('notificationBell.unread_count', 0);

    expect($response->json('menu_html'))->toContain('No new notifications');
    expect($unread->fresh()?->read_at)->not->toBeNull();
    expect($otherAdminsNotification->fresh()?->read_at)->toBeNull();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Unread admin alert')
        ->assertSee('No new notifications');
});

function makeBellClientWorkspaceUser(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Bell Client Org ' . $suffix,
        'slug' => 'bell-client-org-' . $suffix . '-' . Str::lower(Str::random(4)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Bell Client ' . $suffix,
        'billing_address_line1' => 'Bellstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Bell Workspace ' . $suffix,
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'notification-bell-test-plan'],
        [
            'name' => 'Notification Bell Test Plan',
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
        'name' => 'Bell Client User ' . $suffix,
        'email' => 'bell-client-' . $suffix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => 'secret',
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    return [$workspace, $user];
}

function makeBellAdminUser(string $role): User
{
    $organization = Organization::query()->create([
        'name' => 'Bell Admin Org ' . $role . ' ' . Str::lower(Str::random(4)),
        'slug' => 'bell-admin-org-' . $role . '-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => ucfirst($role) . ' Bell Admin',
        'email' => 'bell-admin-' . $role . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => 'secret',
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => $role,
    ]);
}

function createWorkspaceNotification(Workspace $workspace, array $overrides = []): Notification
{
    return Notification::query()->create(array_merge([
        'workspace_id' => (string) $workspace->id,
        'target_scope' => Notification::TARGET_SCOPE_WORKSPACE,
        'is_admin_only' => false,
        'user_id' => null,
        'type' => Notification::TYPE_SYSTEM,
        'title' => 'Bell notification',
        'body' => 'Bell notification body.',
        'read_at' => null,
    ], $overrides));
}
