<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\TaxonomyItem;
use App\Models\TaxonomySet;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns only active taxonomy items from sets assigned to the clients tenant', function () {
    [$organizationA, $workspaceA, $siteA, $plainTokenA] = makeTaxonomyApiClientContext('tenant-a');
    [$organizationB] = makeTaxonomyApiClientContext('tenant-b');

    $setA = TaxonomySet::query()->create([
        'name' => 'Tenant A Set',
        'is_default' => false,
    ]);
    $setB = TaxonomySet::query()->create([
        'name' => 'Tenant B Set',
        'is_default' => false,
    ]);

    TaxonomyItem::query()->create([
        'taxonomy_set_id' => $setA->id,
        'type' => TaxonomyItem::TYPE_INTENT,
        'name' => 'Tenant A Intent',
        'slug' => 'tenant_a_intent',
        'is_active' => true,
    ]);
    TaxonomyItem::query()->create([
        'taxonomy_set_id' => $setA->id,
        'type' => TaxonomyItem::TYPE_AUDIENCE,
        'name' => 'Tenant A Audience',
        'slug' => 'tenant_a_audience',
        'is_active' => true,
    ]);
    TaxonomyItem::query()->create([
        'taxonomy_set_id' => $setA->id,
        'type' => TaxonomyItem::TYPE_INTENT,
        'name' => 'Inactive A Intent',
        'slug' => 'inactive_a_intent',
        'is_active' => false,
    ]);

    TaxonomyItem::query()->create([
        'taxonomy_set_id' => $setB->id,
        'type' => TaxonomyItem::TYPE_INTENT,
        'name' => 'Tenant B Intent',
        'slug' => 'tenant_b_intent',
        'is_active' => true,
    ]);
    TaxonomyItem::query()->create([
        'taxonomy_set_id' => $setB->id,
        'type' => TaxonomyItem::TYPE_AUDIENCE,
        'name' => 'Tenant B Audience',
        'slug' => 'tenant_b_audience',
        'is_active' => true,
    ]);

    DB::table('taxonomy_set_tenant')->insert([
        [
            'taxonomy_set_id' => $setA->id,
            'tenant_id' => $organizationA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'taxonomy_set_id' => $setB->id,
            'tenant_id' => $organizationB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $intentResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plainTokenA,
        'X-Argusly-Site' => 'tenant-a.example.com',
    ])->getJson('/api/v1/taxonomy/intents');

    $intentResponse->assertOk();
    $intentKeys = collect((array) $intentResponse->json('items'))->pluck('key')->all();
    expect($intentKeys)->toContain('tenant_a_intent')
        ->and($intentKeys)->not->toContain('tenant_b_intent')
        ->and($intentKeys)->not->toContain('inactive_a_intent');

    $audienceResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plainTokenA,
        'X-Argusly-Site' => 'tenant-a.example.com',
    ])->getJson('/api/v1/taxonomy/audiences');

    $audienceResponse->assertOk();
    $audienceKeys = collect((array) $audienceResponse->json('items'))->pluck('key')->all();
    expect($audienceKeys)->toContain('tenant_a_audience')
        ->and($audienceKeys)->not->toContain('tenant_b_audience');

    $clientUser = User::query()->where('organization_id', $organizationA->id)->where('is_admin', false)->firstOrFail();
    $this->actingAs($clientUser)
        ->get(route('app.briefs.create'))
        ->assertOk()
        ->assertSee('Tenant A Intent')
        ->assertSee('Tenant A Audience')
        ->assertDontSee('Tenant B Intent')
        ->assertDontSee('Tenant B Audience');
});

/**
 * @return array{0:Organization,1:Workspace,2:ClientSite,3:string}
 */
function makeTaxonomyApiClientContext(string $keyPrefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Taxonomy API Org ' . $keyPrefix,
        'slug' => 'taxonomy-api-org-' . $keyPrefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Taxonomy API BV',
        'billing_address_line1' => 'Mainstreet 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Taxonomy API Workspace ' . $keyPrefix,
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Taxonomy API Site ' . $keyPrefix,
        'site_url' => 'https://' . $keyPrefix . '.example.com',
        'base_url' => 'https://' . $keyPrefix . '.example.com',
        'allowed_domains' => [$keyPrefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'taxonomy-api-plan'],
        [
            'name' => 'Taxonomy API Plan',
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
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    User::query()->create([
        'name' => 'Taxonomy Client ' . $keyPrefix,
        'email' => 'taxonomy-client-' . $keyPrefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $plainToken = 'arg_site_' . Str::random(48);
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plainToken),
        'scopes' => ['briefs:write'],
        'abilities' => ['briefs:write'],
        'revoked' => false,
    ]);

    return [$organization, $workspace, $site, $plainToken];
}
