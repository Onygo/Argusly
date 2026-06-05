<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows workspace owner to update workspace display name and writes an audit log entry', function () {
    [$user, $workspace] = makeWorkspaceNamingUser(role: 'owner', isAdmin: false);

    $this->actingAs($user)
        ->post(route('app.settings.workspace-name.update'), [
            'display_name' => 'Editorial Workspace',
        ])
        ->assertRedirect();

    expect($workspace->fresh()->display_name)->toBe('Editorial Workspace');

    $audit = AuditLog::query()->where('action', 'workspace.display_name.updated')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->subject_type)->toBe(Workspace::class)
        ->and($audit->subject_id)->toBe((string) $workspace->id)
        ->and(data_get($audit->before, 'display_name'))->toBe('Primary Workspace')
        ->and(data_get($audit->after, 'display_name'))->toBe('Editorial Workspace');
});

it('blocks workspace member from updating workspace display name', function () {
    [$user] = makeWorkspaceNamingUser(role: 'member', isAdmin: false);

    $this->actingAs($user)
        ->post(route('app.settings.workspace-name.update'), [
            'display_name' => 'Blocked Name',
        ])
        ->assertStatus(403);
});

it('allows platform admin to update workspace display name', function () {
    [$user, $workspace, $organization] = makeWorkspaceNamingUser(role: 'member', isAdmin: true);

    $this->actingAs($user)
        ->post(route('admin.organizations.workspaces.display-name.update', [$organization, $workspace]), [
            'display_name' => 'Admin Updated Workspace',
        ])
        ->assertRedirect();

    expect($workspace->fresh()->display_name)->toBe('Admin Updated Workspace');
});

function makeWorkspaceNamingUser(string $role, bool $isAdmin): array
{
    $organization = Organization::query()->create([
        'name' => 'Workspace Org',
        'slug' => 'workspace-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Workspace Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Primary Workspace',
        'display_name' => 'Primary Workspace',
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
        'name' => 'Workspace User',
        'email' => 'workspace-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $isAdmin,
    ]);

    return [$user, $workspace, $organization];
}
