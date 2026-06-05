<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows impersonate actions on the organizations index for platform admins', function () {
    [$organization, $admin] = makeOrganizationsIndexContext('admin');

    $this->actingAs($admin)
        ->get(route('admin.organizations'))
        ->assertOk()
        ->assertSee('Impersonate')
        ->assertSee('Open this organization as workspace user');
});

it('blocks normal organization users from the organizations index and impersonation route', function () {
    [$organization] = makeOrganizationsIndexContext('superadmin');

    $user = User::query()->create([
        'name' => 'Regular Org User',
        'email' => 'regular-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('admin.organizations'))
        ->assertStatus(403);

    $this->actingAs($user)
        ->post(route('admin.organizations.impersonate', $organization))
        ->assertStatus(403);
});

it('impersonates the primary workspace from the organizations index and redirects to the app dashboard', function () {
    [$organization, $admin, $workspaceA] = makeOrganizationsIndexContext('admin');

    $response = $this->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $organization));

    $response->assertRedirect(route('app.dashboard'))
        ->assertSessionHas('admin_impersonator_id', (string) $admin->id)
        ->assertSessionHas('impersonated_workspace_id', (string) $workspaceA->id)
        ->assertSessionHas('status');

    expect(auth()->user()?->is_admin)->toBeFalse()
        ->and((int) auth()->user()?->organization_id)->toBe($organization->id);
});

it('supports choosing a workspace when the organization has multiple workspaces', function () {
    [$organization, $admin, $workspaceA, $workspaceB] = makeOrganizationsIndexContext('superadmin');

    $this->actingAs($admin)
        ->get(route('admin.organizations'))
        ->assertOk()
        ->assertSee('Choose workspace')
        ->assertSee($workspaceA->display_name)
        ->assertSee($workspaceB->display_name);

    $response = $this->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $organization), [
            'workspace_id' => (string) $workspaceB->id,
        ]);

    $response->assertRedirect(route('app.dashboard'))
        ->assertSessionHas('impersonated_workspace_id', (string) $workspaceB->id);
});

it('writes an audit log when impersonation starts from the organizations index', function () {
    [$organization, $admin, $workspaceA] = makeOrganizationsIndexContext('superadmin');

    $this->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $organization))
        ->assertRedirect(route('app.dashboard'));

    $audit = AuditLog::query()
        ->where('action', 'impersonation_started')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $admin->id)
        ->and(data_get($audit->after, 'organization_id'))->toBe($organization->id)
        ->and(data_get($audit->after, 'workspace_id'))->toBe((string) $workspaceA->id)
        ->and((string) $audit->ip)->not->toBe('');
});

it('returns from impersonation back to the original admin session', function () {
    [$organization, $admin] = makeOrganizationsIndexContext('superadmin');

    $this->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $organization))
        ->assertRedirect(route('app.dashboard'));

    $this->post(route('impersonation.stop'))
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionMissing('admin_impersonator_id')
        ->assertSessionMissing('impersonated_workspace_id');

    expect(auth()->id())->toBe($admin->id)
        ->and(auth()->user()?->is_admin)->toBeTrue();
});

it('switches impersonation safely instead of nesting sessions', function () {
    [$organization, $admin, $workspaceA, $workspaceB] = makeOrganizationsIndexContext('superadmin');

    $this->actingAs($admin)
        ->withSession([
            'admin_impersonator_id' => (string) $admin->id,
            'impersonated_workspace_id' => (string) $workspaceA->id,
        ])
        ->post(route('admin.organizations.impersonate', $organization), [
            'workspace_id' => (string) $workspaceB->id,
        ])
        ->assertRedirect(route('app.dashboard'))
        ->assertSessionHas('admin_impersonator_id', (string) $admin->id)
        ->assertSessionHas('impersonated_workspace_id', (string) $workspaceB->id);

    $audit = AuditLog::query()
        ->where('action', 'impersonation_switched')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(data_get($audit->after, 'workspace_id'))->toBe((string) $workspaceB->id);
});

function makeOrganizationsIndexContext(string $adminRole): array
{
    $organization = Organization::query()->create([
        'name' => 'Organizations Index Org',
        'slug' => 'organizations-index-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::query()->create([
        'name' => 'Workspace Alpha',
        'display_name' => 'Workspace Alpha',
        'organization_id' => $organization->id,
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $workspaceB = Workspace::query()->create([
        'name' => 'Workspace Beta',
        'display_name' => 'Workspace Beta',
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    User::query()->create([
        'name' => 'Workspace Owner',
        'email' => 'workspace-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $adminOrg = Organization::query()->create([
        'name' => 'Admin Index Control Org',
        'slug' => 'admin-index-control-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $admin = User::query()->create([
        'name' => ucfirst($adminRole) . ' Admin',
        'email' => $adminRole . '-org-index+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $adminOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => in_array($adminRole, ['admin', 'superadmin'], true),
        'admin_role' => $adminRole,
    ]);

    return [$organization, $admin, $workspaceA, $workspaceB];
}
