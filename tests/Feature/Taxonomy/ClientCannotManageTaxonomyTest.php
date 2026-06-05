<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('prevents clients from managing taxonomy and removes client taxonomy page', function () {
    $client = makeTaxonomyClientUser();

    $this->actingAs($client)
        ->get('/editorial-taxonomy')
        ->assertNotFound();

    $this->actingAs($client)
        ->get(route('admin.editorial-taxonomy.index'))
        ->assertStatus(403);

    $this->actingAs($client)
        ->post(route('admin.editorial-taxonomy.sets.store'), [
            'name' => 'Client Attempt',
        ])
        ->assertStatus(403);
});

function makeTaxonomyClientUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Taxonomy Client Org',
        'slug' => 'taxonomy-client-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Taxonomy Client BV',
        'billing_address_line1' => 'Mainstreet 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Taxonomy Client Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'taxonomy-client-plan'],
        [
            'name' => 'Taxonomy Client Plan',
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

    return User::query()->create([
        'name' => 'Taxonomy Client',
        'email' => 'taxonomy-client+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);
}

