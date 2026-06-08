<?php

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\CreditPack;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ClientSite;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('redirects users without billing details to company onboarding from dashboard', function () {
    $user = createUserForBillingGate();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertRedirect(route('app.onboarding.company.show'));
});

it('allows company onboarding route for users without billing details', function () {
    $user = createUserForBillingGate();

    $this->actingAs($user)
        ->get(route('app.onboarding.company.show'))
        ->assertOk()
        ->assertSee('Vul je bedrijfsgegevens in om te kunnen starten met Argusly');
});

it('reaches subscription start flow even when billing details are incomplete', function () {
    $user = createUserForBillingGate();

    $this->actingAs($user)
        ->post(route('app.billing.subscription.start'), [
            'plan_id' => (string) Str::uuid(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('billing');
});

it('blocks buying credits when billing details are incomplete', function () {
    $user = createUserForBillingGate();

    $workspace = Workspace::query()->where('organization_id', $user->organization_id)->firstOrFail();
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Gate Site',
        'site_url' => 'https://gate.example.com',
        'allowed_domains' => ['gate.example.com'],
        'is_active' => true,
    ]);

    CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'gate-pack',
        'name' => 'Gate Pack',
        'credits_amount' => 100,
        'price_cents' => 1500,
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.billing.packs.purchase'), [
            'client_site_id' => (string) $site->id,
            'pack_key' => 'gate-pack',
        ])
        ->assertRedirect(route('app.onboarding.company.show'));
});

it('allows normal app access when billing details are complete', function () {
    $user = createUserForBillingGate([
        'billing_company_name' => 'Complete Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->where('organization_id', $user->organization_id)->firstOrFail();

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'gate-plan',
        'slug' => 'growth',
        'name' => 'Gate Plan',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 200,
        'included_credits_per_interval' => 200,
        'seat_limit' => 5,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $user->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 200,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $organization = $user->organization;
    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk();
});

it('allows dashboard access when an active access override exists', function () {
    $user = createUserForBillingGate();

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::ACTIVE,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(14),
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk();
});

it('does not bypass billing gating before a scheduled override starts', function () {
    $user = createUserForBillingGate();

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::TRIAL_OVERRIDE,
        'status' => AccessOverrideStatus::SCHEDULED,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(14),
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertRedirect(route('app.onboarding.company.show'));
});

it('does not bypass billing gating when the override is cancelled', function () {
    $user = createUserForBillingGate();

    AccessOverride::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => AccessOverrideType::EARLY_ACCESS,
        'status' => AccessOverrideStatus::CANCELLED,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(2),
        'ended_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertRedirect(route('app.onboarding.company.show'));
});

/**
 * @param array<string,mixed> $organizationOverrides
 */
function createUserForBillingGate(array $organizationOverrides = []): User
{
    $organization = Organization::create(array_merge([
        'name' => 'Gate Org',
        'slug' => 'gate-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ], $organizationOverrides));

    Workspace::create([
        'name' => 'Gate Workspace',
        'organization_id' => $organization->id,
    ]);

    return User::create([
        'name' => 'Gate Owner',
        'email' => 'gate-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);
}
