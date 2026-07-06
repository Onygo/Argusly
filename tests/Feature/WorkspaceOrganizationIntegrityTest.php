<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires organization when creating workspace', function () {
    expect(fn () => Workspace::create(['name' => 'Missing Org Workspace']))
        ->toThrow(QueryException::class);
});

it('requires a valid workspace when creating client site token', function () {
    config()->set('argusly.admin_key', 'test-admin-key');

    $payload = [
        'workspace_id' => (string) Str::uuid(),
        'site' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'name' => 'Example',
            'allowed_domains' => ['example.com'],
        ],
        'scopes' => ['briefs:write'],
    ];

    $this->withHeaders(['X-Admin-Key' => 'test-admin-key'])
        ->postJson('/api/v1/auth/site-tokens', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workspace_id']);
});

it('does not show client sites from other organizations in app sites', function () {
    $orgA = Organization::create([
        'name' => 'Org A',
        'slug' => 'org-a',
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Org A BV',
        'billing_address_line1' => 'Mainstraat 1',
        'billing_country_code' => 'NL',
    ]);
    $orgB = Organization::create([
        'name' => 'Org B',
        'slug' => 'org-b',
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::create([
        'name' => 'Workspace A',
        'organization_id' => $orgA->id,
    ]);
    $workspaceB = Workspace::create([
        'name' => 'Workspace B',
        'organization_id' => $orgB->id,
    ]);

    $siteA = ClientSite::create([
        'workspace_id' => $workspaceA->id,
        'type' => 'wordpress',
        'name' => 'Site A',
        'site_url' => 'https://a.example.com',
        'allowed_domains' => ['a.example.com'],
        'is_active' => true,
    ]);
    ClientSite::create([
        'workspace_id' => $workspaceB->id,
        'type' => 'wordpress',
        'name' => 'Site B',
        'site_url' => 'https://b.example.com',
        'allowed_domains' => ['b.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::firstOrCreate(
        ['key' => 'workspace-integrity-test-plan'],
        [
            'name' => 'Workspace Integrity Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgA->id,
        'workspace_id' => $workspaceA->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::create([
        'name' => 'Org A User',
        'email' => 'orga@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $orgA->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $response = $this->actingAs($user)->get(route('app.sites'));

    $response->assertOk();
    $response->assertSee($siteA->name);
    $response->assertDontSee('Site B');
});
