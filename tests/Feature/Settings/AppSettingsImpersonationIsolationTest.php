<?php

use App\Models\CompanyProfile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uses impersonated workspace context on settings page', function () {
    $organization = Organization::create([
        'name' => 'Infodation Org',
        'slug' => 'infodation-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Infodation Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspaceOnygo = Workspace::create([
        'name' => 'Onygo Workspace',
        'organization_id' => $organization->id,
    ]);
    $workspaceInfodation = Workspace::create([
        'name' => 'Infodation Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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
        'workspace_id' => $workspaceInfodation->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    CompanyProfile::create([
        'workspace_id' => $workspaceOnygo->id,
        'company_name' => 'Onygo',
    ]);
    CompanyProfile::create([
        'workspace_id' => $workspaceInfodation->id,
        'company_name' => 'Infodation',
    ]);

    $user = User::create([
        'name' => 'Infodation Admin',
        'email' => 'infodation+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'admin',
        'approved_at' => now(),
        'active' => true,
        'is_admin' => false,
    ]);

    // Company Profile page now shows workspace context (moved from settings to brand section)
    $this->actingAs($user)
        ->withSession([
            'admin_impersonator_id' => 999,
            'impersonated_workspace_id' => (string) $workspaceInfodation->id,
        ])
        ->get('/app/brand/company-profile')
        ->assertOk()
        ->assertSee('Workspace: ' . $workspaceInfodation->name)
        ->assertSee('Infodation')
        ->assertDontSee('Workspace: ' . $workspaceOnygo->name);
});

it('redirects admin users out of app when impersonation session is invalid', function () {
    $organization = Organization::create([
        'name' => 'Onygo Admin Org',
        'slug' => 'onygo-admin-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $admin = User::create([
        'name' => 'Platform Admin',
        'email' => 'platform-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->withSession([
            'admin_impersonator_id' => $admin->id,
            'impersonated_workspace_id' => (string) Str::uuid(),
        ])
        ->get('/app/settings')
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHasErrors('impersonation');
});
