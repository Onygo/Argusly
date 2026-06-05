<?php

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditPackPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('blocks credit-pack purchases without an active subscription', function () {
    $organization = Organization::create([
        'name' => 'No Plan Org',
        'slug' => 'no-plan-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'No Plan Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'No Plan Site',
        'site_url' => 'https://no-plan.example.com',
        'allowed_domains' => ['no-plan.example.com'],
        'is_active' => true,
    ]);

    CreditPack::create([
        'key' => 'pack-credits',
        'name' => 'Pack credits',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'is_active' => true,
        'expires_in_months' => 12,
        'never_expires' => false,
    ]);

    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('app.billing.packs.purchase'), [
            'client_site_id' => (string) $site->id,
            'pack_key' => 'pack-credits',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('billing');
});

it('allows pending purchase creation when active subscription exists', function () {
    $organization = Organization::create([
        'name' => 'Active Plan Org',
        'slug' => 'active-plan-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Active Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Active Site',
        'site_url' => 'https://active-site.example.com',
        'allowed_domains' => ['active-site.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'starter-subscription',
        'name' => 'Starter',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3],
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
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

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    CreditPack::create([
        'key' => 'pack-credits',
        'name' => 'Pack credits',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'is_active' => true,
        'expires_in_months' => 12,
        'never_expires' => false,
    ]);

    $purchase = app(CreditPackPurchaseService::class)->createPending((string) $site->id, 'pack-credits', $organization);

    expect($purchase->status)->toBe('pending');
});

it('allows pending purchase creation when an active access override exists without a subscription', function () {
    $organization = Organization::create([
        'name' => 'Override Plan Org',
        'slug' => 'override-plan-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Override Plan Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Override Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Override Site',
        'site_url' => 'https://override-site.example.com',
        'allowed_domains' => ['override-site.example.com'],
        'is_active' => true,
    ]);

    CreditPack::create([
        'key' => 'pack-credits',
        'name' => 'Pack credits',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'is_active' => true,
        'expires_in_months' => 12,
        'never_expires' => false,
    ]);

    $owner = User::create([
        'name' => 'Override Owner',
        'email' => 'override-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(7),
    ]);

    $purchase = app(CreditPackPurchaseService::class)->createPending(
        (string) $site->id,
        'pack-credits',
        $organization,
        $owner,
    );

    expect($purchase->status)->toBe('pending');
});
