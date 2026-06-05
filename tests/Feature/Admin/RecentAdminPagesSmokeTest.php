<?php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('loads the recently changed admin pages for a platform admin', function () {
    [$admin] = makeRecentAdminSmokeContext();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.organizations'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertOk()
        ->assertSee('Translations');
});

function makeRecentAdminSmokeContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Recent Admin Smoke Org',
        'slug' => 'recent-admin-smoke-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Workspace::query()->create([
        'name' => 'Recent Admin Smoke Workspace',
        'organization_id' => $organization->id,
    ]);

    $admin = User::query()->create([
        'name' => 'Recent Admin Smoke User',
        'email' => 'recent-admin-smoke-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    return [$admin, $organization];
}
