<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns 404 for client network linking routes when feature is disabled', function () {
    config()->set('features.network_linking', false);

    [$user, $workspace] = makeClientWithWorkspace();

    $this->actingAs($user)
        ->get(route('app.network-linking.index'))
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('app.network-linking.profile.update', $workspace), [
            'target_site_url' => 'https://example.com',
        ])
        ->assertNotFound();
});

it('hides network linking from the client navigation when feature is disabled', function () {
    config()->set('features.network_linking', false);

    [$user] = makeClientWithWorkspace();

    $this->actingAs($user)
        ->get(route('app.sites'))
        ->assertOk()
        ->assertDontSee('Network Linking');
});

function makeClientWithWorkspace(): array
{
    $organization = Organization::query()->create([
        'name' => 'Feature Flag Org',
        'slug' => 'feature-flag-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Feature Flag BV',
        'billing_address_line1' => 'Feature Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Feature Flag Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'feature-flag-network-linking-plan'],
        [
            'name' => 'Feature Flag Network Linking Plan',
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
        'name' => 'Feature User',
        'email' => 'feature-user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    return [$user, $workspace];
}

