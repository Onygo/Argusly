<?php

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows an admin to create a user access override and shows it in the admin ui', function () {
    $admin = makeAccessOverrideAdmin();
    $user = makeManagedOverrideUser();

    $this->actingAs($admin)
        ->post(route('admin.users.access-overrides.store', $user), [
            'type' => AccessOverrideType::EARLY_ACCESS->value,
            'starts_at' => now()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(30)->format('Y-m-d H:i:s'),
            'reason' => 'Pilot access for onboarding review',
            'notes' => 'Support and demo tenant.',
        ])
        ->assertRedirect(route('admin.users.show', $user));

    $override = AccessOverride::query()->where('user_id', $user->id)->first();

    expect($override)->not->toBeNull()
        ->and($override?->status)->toBe(AccessOverrideStatus::ACTIVE)
        ->and($override?->created_by_user_id)->toBe($admin->id);

    $this->actingAs($admin)
        ->get(route('admin.users.show', $user))
        ->assertOk()
        ->assertSee('Create replacement override')
        ->assertSee('Pilot access for onboarding review')
        ->assertSee('Active');
});

it('prevents duplicate open overrides for the same user scope', function () {
    $admin = makeAccessOverrideAdmin();
    $user = makeManagedOverrideUser();

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'created_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->from(route('admin.users.show', $user))
        ->post(route('admin.users.access-overrides.store', $user), [
            'type' => AccessOverrideType::TRIAL_OVERRIDE->value,
            'starts_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('admin.users.show', $user))
        ->assertSessionHasErrors('access_override');

    expect(AccessOverride::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('allows an admin to stop an active override immediately', function () {
    $admin = makeAccessOverrideAdmin();
    $user = makeManagedOverrideUser();

    $override = AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'created_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.access-overrides.stop', [$user, $override]))
        ->assertRedirect(route('admin.users.show', $user));

    expect($override->fresh()->status)->toBe(AccessOverrideStatus::CANCELLED)
        ->and($override->fresh()->ended_by_user_id)->toBe($admin->id)
        ->and($override->fresh()->ended_at)->not->toBeNull();
});

function makeAccessOverrideAdmin(): User
{
    $organization = Organization::query()->create([
        'name' => 'Override Admin Org',
        'slug' => 'override-admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Override Admin',
        'email' => 'override-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

function makeManagedOverrideUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Managed Override Org',
        'slug' => 'managed-override-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Workspace::query()->create([
        'name' => 'Managed Override Workspace',
        'organization_id' => $organization->id,
    ]);

    return User::query()->create([
        'name' => 'Managed User',
        'email' => 'managed+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
}
