<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\SiteCompetitor;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSiteCompetitorContext(int $competitorLimit): array
{
    $organization = Organization::query()->create([
        'name' => 'Competitor Org',
        'slug' => 'competitor-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Competitor Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Competitor Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Competitor Site',
        'site_url' => 'https://competitor-test.example.com',
        'base_url' => 'https://competitor-test.example.com',
        'allowed_domains' => ['competitor-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'competitor-plan-' . Str::random(4),
        'slug' => 'competitor-plan-' . Str::random(4),
        'name' => 'Competitor Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 3, 'workspaces' => 1],
        'is_active' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'competitor_slots_limit',
        'value_type' => 'int',
        'value_int' => $competitorLimit,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    return [$user, $workspace, $site];
}

it('enforces competitor slots limit per site in competitor page', function () {
    [$user, $workspace, $site] = makeSiteCompetitorContext(1);

    $this->actingAs($user)->post(route('app.sites.competitors.store', $site), [
        'name' => 'Comp A',
        'domain' => 'comp-a.example.com',
        'notes' => 'Main SERP competitor',
    ])->assertRedirect();

    $this->actingAs($user)->from(route('app.sites.competitors.index', $site))->post(route('app.sites.competitors.store', $site), [
        'name' => 'Comp B',
        'domain' => 'comp-b.example.com',
        'notes' => 'Second competitor',
    ])->assertRedirect(route('app.sites.competitors.index', $site))
        ->assertSessionHasErrors(['competitors']);

    $activeCount = SiteCompetitor::query()
        ->where('workspace_id', $workspace->id)
        ->where('client_site_id', $site->id)
        ->where('is_active', true)
        ->count();

    expect($activeCount)->toBe(1);
});
