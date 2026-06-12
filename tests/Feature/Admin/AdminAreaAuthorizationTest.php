<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('redirects guests to app login when admin area middleware is hit directly', function () {
    Route::middleware('admin.area')->get('/_test/admin-area-guest-redirect', fn () => 'ok');

    $this->get('/_test/admin-area-guest-redirect')
        ->assertRedirect(route('login'));
});

it('restores admin account when an impersonated session hits admin routes', function () {
    $superadmin = makeAdminAreaUser('superadmin');
    $impersonatedUser = makeAdminAreaUser('user');

    $response = $this->actingAs($impersonatedUser)
        ->withSession([
            'admin_impersonator_id' => (string) $superadmin->id,
            'impersonated_workspace_id' => (string) Str::uuid(),
        ])
        ->get(route('admin.dashboard'));

    $response->assertRedirect(route('admin.dashboard'))
        ->assertSessionMissing('admin_impersonator_id')
        ->assertSessionMissing('impersonated_workspace_id');

    expect(auth()->id())->toBe($superadmin->id);
});

it('redirects to app login when impersonation admin cannot be restored', function () {
    $impersonatedUser = makeAdminAreaUser('user');

    $response = $this->actingAs($impersonatedUser)
        ->withSession([
            'admin_impersonator_id' => (string) Str::uuid(),
            'impersonated_workspace_id' => (string) Str::uuid(),
        ])
        ->get(route('admin.dashboard'));

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors('impersonation')
        ->assertSessionMissing('admin_impersonator_id')
        ->assertSessionMissing('impersonated_workspace_id');
});

it('blocks regular users from admin routes', function () {
    $user = makeAdminAreaUser('user');

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertStatus(403);
});

it('allows admin into dashboard but blocks billing endpoints', function () {
    $admin = makeAdminAreaUser('admin');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.billing.index'))
        ->assertStatus(403);
});

it('allows admin users to view their own admin profile', function () {
    $admin = makeAdminAreaUser('admin');
    $otherAdmin = makeAdminAreaUser('admin');

    $this->actingAs($admin)
        ->get(route('admin.users.show', $admin))
        ->assertOk()
        ->assertSee($admin->email);

    $this->actingAs($admin)
        ->get(route('admin.users.show', $otherAdmin))
        ->assertNotFound();
});

it('allows admin users to update only their own password from their profile', function () {
    $admin = makeAdminAreaUser('admin');
    $otherAdmin = makeAdminAreaUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.users.password.update', $admin), [
            'current_password' => 'secret',
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ])
        ->assertRedirect(route('admin.users.show', $admin))
        ->assertSessionHas('status', 'Password updated.');

    expect(Hash::check('NewSecurePass123!', (string) $admin->fresh()->password))->toBeTrue();

    $this->actingAs($admin)
        ->post(route('admin.users.password.update', $otherAdmin), [
            'current_password' => 'NewSecurePass123!',
            'password' => 'OtherSecurePass123!',
            'password_confirmation' => 'OtherSecurePass123!',
        ])
        ->assertStatus(403);

    expect(Hash::check('OtherSecurePass123!', (string) $otherAdmin->fresh()->password))->toBeFalse();
});

it('allows superadmin access to protected admin routes', function () {
    $superadmin = makeAdminAreaUser('superadmin');

    $this->actingAs($superadmin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($superadmin)
        ->get(route('admin.billing.index'))
        ->assertOk();

    $this->actingAs($superadmin)
        ->get(route('admin.llm.settings'))
        ->assertOk();
});

it('allows only superadmin to change user admin roles and writes audit log', function () {
    $admin = makeAdminAreaUser('admin');
    $superadmin = makeAdminAreaUser('superadmin');
    $target = makeAdminAreaUser('user');

    $this->actingAs($admin)
        ->post(route('admin.users.role.update', $target), [
            'admin_role' => 'admin',
        ])
        ->assertStatus(403);

    $this->actingAs($superadmin)
        ->post(route('admin.users.role.update', $target), [
            'admin_role' => 'admin',
        ])
        ->assertRedirect();

    $target->refresh();

    expect($target->is_admin)->toBeTrue()
        ->and($target->admin_role)->toBe('admin');

    $audit = AuditLog::query()
        ->where('action', 'admin.user.role.updated')
        ->where('subject_type', User::class)
        ->where('subject_id', (string) $target->id)
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(data_get($audit->before, 'admin_role'))->toBe('user')
        ->and(data_get($audit->after, 'admin_role'))->toBe('admin')
        ->and($audit->actor_id)->toBe((string) $superadmin->id);
});

function makeAdminAreaUser(string $adminRole): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Auth Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-auth-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $isAdmin = in_array($adminRole, ['admin', 'superadmin'], true);

    return User::query()->create([
        'name' => ucfirst($adminRole) . ' User',
        'email' => $adminRole . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $isAdmin,
        'admin_role' => $adminRole,
    ]);
}
