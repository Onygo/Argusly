<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('opens register GET without plan and defaults selection to the platform entry plan', function () {
    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'platform_250',
        'slug' => 'platform_250',
        'name' => 'Argusly Platform',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 250,
        'included_credits_per_interval' => 250,
        'seat_limit' => 5,
        'is_active' => true,
        'is_public' => true,
        'billing_type' => 'fixed',
        'sort_order' => 1,
    ]);

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Argusly Platform', false)
        ->assertSee('value="platform_250" selected', false);
});

it('requires a valid active plan on registration submit', function () {
    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'platform_250',
        'slug' => 'platform_250',
        'name' => 'Argusly Platform',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 250,
        'included_credits_per_interval' => 250,
        'seat_limit' => 5,
        'is_active' => true,
        'is_public' => true,
        'billing_type' => 'fixed',
        'sort_order' => 1,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Planless User',
        'email' => 'planless@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Planless Co',
        'plan' => 'invalid-plan',
    ])->assertRedirect(route('pricing'))
        ->assertSessionHasErrors('plan');
});

it('gates dashboard access when billing profile is complete but no active subscription exists', function () {
    $organization = Organization::query()->create([
        'name' => 'Billing Gate Org',
        'slug' => 'billing-gate-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Billing Gate Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    Workspace::query()->create([
        'name' => 'Main Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('status', 'Complete billing onboarding by starting your subscription before using the app.');
});

it('accepts register flow with valid plan and redirects to email code verification', function () {
    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'platform_250',
        'slug' => 'platform_250',
        'name' => 'Argusly Platform',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 250,
        'included_credits_per_interval' => 250,
        'seat_limit' => 5,
        'is_active' => true,
        'is_public' => true,
        'billing_type' => 'fixed',
        'sort_order' => 1,
    ]);

    $this->get(route('register', ['plan' => 'starter']))
        ->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Creator User',
        'email' => 'creator-user@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Creator Co',
        'plan' => 'starter',
    ])->assertRedirect(route('verify-code.show'));
});
