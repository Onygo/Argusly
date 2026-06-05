<?php

use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\EntitlementRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns pending payment upgrade state with polling enabled', function () {
    [$organization, $workspace, $user, $subscription, $starter, $scale] = makeUpgradeStatusContext('upgrade-status-pending');

    $change = SubscriptionPlanChange::query()->create([
        'id' => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $scale->id,
        'strategy' => 'immediate_proration',
        'status' => SubscriptionPlanChangeStatus::PENDING_PAYMENT,
        'currency' => 'EUR',
        'effective_at' => now(),
    ]);

    $intent = PaymentIntent::query()->create([
        'id' => (string) Str::uuid(),
        'billable_type' => SubscriptionPlanChange::class,
        'billable_id' => $change->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 5000,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_upgrade_status_pending',
        'idempotency_key' => 'upgrade-status:' . $change->id,
    ]);

    $change->payment_intent_id = $intent->id;
    $change->save();

    $this->actingAs($user)
        ->getJson(route('app.api.billing.upgrade-status', $workspace))
        ->assertOk()
        ->assertJsonPath('state', SubscriptionPlanChangeStatus::PENDING_PAYMENT->value)
        ->assertJsonPath('status', SubscriptionPlanChangeStatus::PENDING_PAYMENT->value)
        ->assertJsonPath('payment_status', 'open')
        ->assertJsonPath('is_pending', true)
        ->assertJsonPath('should_poll', true)
        ->assertJsonPath('current_plan', (string) $starter->key)
        ->assertJsonPath('target_plan', (string) $scale->key)
        ->assertJsonPath('effective_plan', (string) $starter->key);
});

it('returns applied upgrade state with refreshed entitlements', function () {
    [$organization, $workspace, $user, $subscription, $starter, $scale] = makeUpgradeStatusContext('upgrade-status-applied');

    $subscription->plan_id = $scale->id;
    $subscription->price_cents = (int) $scale->price_cents;
    $subscription->included_credits_per_interval = (int) $scale->included_credits_per_interval;
    $subscription->save();

    app(EntitlementRefreshService::class)->refreshForSubscription(
        $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
    );

    $change = SubscriptionPlanChange::query()->create([
        'id' => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $scale->id,
        'strategy' => 'immediate_proration',
        'status' => SubscriptionPlanChangeStatus::APPLIED,
        'currency' => 'EUR',
        'effective_at' => now()->subMinute(),
        'applied_at' => now()->subMinute(),
    ]);

    PaymentIntent::query()->create([
        'id' => (string) Str::uuid(),
        'billable_type' => SubscriptionPlanChange::class,
        'billable_id' => $change->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 5000,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_upgrade_status_applied',
        'idempotency_key' => 'upgrade-status:' . $change->id,
        'paid_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('app.api.billing.upgrade-status', $workspace))
        ->assertOk()
        ->assertJsonPath('state', SubscriptionPlanChangeStatus::APPLIED->value)
        ->assertJsonPath('status', SubscriptionPlanChangeStatus::APPLIED->value)
        ->assertJsonPath('is_pending', false)
        ->assertJsonPath('is_final', true)
        ->assertJsonPath('should_poll', false)
        ->assertJsonPath('effective_plan', (string) $scale->key)
        ->assertJsonPath('entitlements.compare_max_models', 4)
        ->assertJsonPath('entitlements.hybrid_drafts_enabled', true)
        ->assertJsonPath('entitlements.monthly_credits', 600)
        ->assertJsonPath('message', 'Scale is active.');

    expect($response->json('updated_at'))->not->toBeNull();
});

it('returns no_change state when no plan change exists', function () {
    [$organization, $workspace, $user, $subscription, $starter, $scale] = makeUpgradeStatusContext('upgrade-status-none');

    app(EntitlementRefreshService::class)->refreshForSubscription(
        $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
    );

    $this->actingAs($user)
        ->getJson(route('app.api.billing.upgrade-status', $workspace))
        ->assertOk()
        ->assertJsonPath('state', 'no_change')
        ->assertJsonPath('status', null)
        ->assertJsonPath('should_poll', false)
        ->assertJsonPath('effective_plan', (string) $starter->key)
        ->assertJsonPath('entitlements.compare_max_models', 1);
});

it('denies access to a workspace outside the users organization', function () {
    [$organization, $workspace, $user] = makeUpgradeStatusContext('upgrade-status-access');

    $otherOrganization = Organization::query()->create([
        'name' => 'Other Org',
        'slug' => 'other-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Other BV',
        'billing_address_line1' => 'Other 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $otherWorkspace = Workspace::query()->create([
        'name' => 'Other Workspace',
        'organization_id' => $otherOrganization->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('app.api.billing.upgrade-status', $otherWorkspace))
        ->assertNotFound();
});

function makeUpgradeStatusContext(string $prefix = 'upgrade-status'): array
{
    $organization = Organization::query()->create([
        'name' => 'Upgrade Status Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Upgrade Status BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_postal_code' => '1011AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Upgrade Status Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Upgrade Status Site',
        'site_url' => 'https://upgrade-status.example.com',
        'allowed_domains' => ['upgrade-status.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Upgrade Status Owner',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $starter = makeUpgradeStatusPlan('starter-' . $prefix . '-' . Str::random(5), 'Starter', 4900, 100);
    $scale = makeUpgradeStatusPlan('scale-' . $prefix . '-' . Str::random(5), 'Scale', 15900, 600);

    makeUpgradeStatusFeatures($starter, 1, false);
    makeUpgradeStatusFeatures($scale, 4, true);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $starter->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'current_period_start' => now()->subDays(2),
        'current_period_end' => now()->addDays(28),
        'provider_customer_id' => 'cst_upgrade_status_' . Str::random(6),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return [$organization, $workspace, $user, $subscription, $starter, $scale];
}

function makeUpgradeStatusPlan(string $key, string $name, int $priceCents, int $credits): Plan
{
    return Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => $key,
        'key' => $key,
        'name' => $name,
        'interval' => 'month',
        'monthly_price_cents' => $priceCents,
        'price_cents' => $priceCents,
        'currency' => 'EUR',
        'included_credits' => $credits,
        'included_credits_per_interval' => $credits,
        'seat_limit' => 10,
        'is_active' => true,
    ]);
}

function makeUpgradeStatusFeatures(Plan $plan, int $maxModels, bool $hybridEnabled): void
{
    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_max_models',
        'value_type' => 'int',
        'value_int' => $maxModels,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_hybrid_enabled',
        'value_type' => 'bool',
        'value_bool' => $hybridEnabled,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_scoring_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_premium_models_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);
}
