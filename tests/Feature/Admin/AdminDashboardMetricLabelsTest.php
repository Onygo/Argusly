<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders readable dashboard metric labels instead of raw keys', function () {
    $admin = makeAdminDashboardLabelsUser();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Briefs Created Last 7 Days')
        ->assertSee('Drafts Generated Last 30 Days')
        ->assertDontSee('total_briefs_7d')
        ->assertDontSee('drafts_count_30d');
});

function makeAdminDashboardLabelsUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Dashboard Labels Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-dashboard-labels-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Dashboard Labels User',
        'email' => 'admin-dashboard-labels+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
