<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\PlanChangeTiming;
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
use App\Services\Billing\PlanEntitlementService;
use App\Services\Entitlements\EntitlementRefreshService;
use App\Services\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps old entitlements until immediate upgrade payment is paid', function () {
    config()->set('billing.entitlements.cache_ttl_seconds', 3600);

    [$organization, $workspace, $site, $subscription, $starter, $scale] = makeImmediateUpgradeContext();

    app(EntitlementRefreshService::class)->refreshForSubscription(
        $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
    );

    $entitlements = app(PlanEntitlementService::class);
    expect($entitlements->getWorkspaceEntitlements($workspace)['compare_max_models'])->toBe(1);

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_immediate_upgrade_1',
                'checkout_url' => 'https://pay.example.test/upgrade',
                'status' => 'open',
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_upgrade_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_upgrade_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    }]));

    $result = app(PlanChangeService::class)->requestChange($subscription, $scale, PlanChangeTiming::IMMEDIATE_PRORATED);

    /** @var SubscriptionPlanChange $change */
    $change = $result['change'];

    expect($change->status)->toBe(SubscriptionPlanChangeStatus::PENDING_PAYMENT)
        ->and($change->proration_amount_cents)->toBeGreaterThan(0)
        ->and($result['checkout_url'])->not->toBeNull();

    $subscription->refresh();

    expect((string) $subscription->plan_id)->toBe((string) $starter->id)
        ->and($entitlements->getWorkspaceEntitlements($workspace)['compare_max_models'])->toBe(1);

    /** @var PaymentIntent $intent */
    $intent = $result['payment_intent'];
    $intent->status = 'paid';
    $intent->paid_at = now();
    $intent->save();

    app(PlanChangeService::class)->applyAfterPayment($change->fresh(['paymentIntent', 'subscription.organization', 'toPlan', 'fromPlan']));

    $subscription->refresh();

    $updatedEntitlements = $entitlements->getWorkspaceEntitlements($workspace);

    expect((string) $subscription->plan_id)->toBe((string) $scale->id)
        ->and($updatedEntitlements['compare_max_models'])->toBe(4)
        ->and($updatedEntitlements['hybrid_drafts_enabled'])->toBeTrue();
});

it('does not activate immediate upgrade when payment is not yet paid', function () {
    [$organization, $workspace, $site, $subscription, $starter, $scale] = makeImmediateUpgradeContext('immediate-upgrade-unpaid');

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_immediate_upgrade_2',
                'checkout_url' => 'https://pay.example.test/upgrade-unpaid',
                'status' => 'open',
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_upgrade_2'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_upgrade_2', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    }]));

    $result = app(PlanChangeService::class)->requestChange($subscription, $scale, PlanChangeTiming::IMMEDIATE_PRORATED);

    /** @var SubscriptionPlanChange $change */
    $change = $result['change'];

    $change = app(PlanChangeService::class)->applyAfterPayment($change->fresh(['paymentIntent', 'subscription.organization', 'toPlan', 'fromPlan']));

    $subscription->refresh();

    expect((string) $subscription->plan_id)->toBe((string) $starter->id)
        ->and($change->status)->toBe(SubscriptionPlanChangeStatus::PENDING_PAYMENT);
});

it('shows a pending upgrade state on the billing page while payment is pending', function () {
    [$organization, $workspace, $site, $subscription, $starter, $scale] = makeImmediateUpgradeContext('immediate-upgrade-ui');

    $owner = User::query()->create([
        'name' => 'Immediate Upgrade Owner',
        'email' => 'immediate-upgrade-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_immediate_upgrade_ui_1',
                'checkout_url' => 'https://pay.example.test/upgrade-ui',
                'status' => 'open',
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_upgrade_ui_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_upgrade_ui_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    }]));

    app(PlanChangeService::class)->requestChange($subscription, $scale, PlanChangeTiming::IMMEDIATE_PRORATED);

    $this->actingAs($owner)
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertSee('Upgrade payment pending')
        ->assertSee('New plan entitlements unlock after payment confirmation.');
});

it('does not corrupt a final applied plan change when webhook retries arrive', function () {
    [$organization, $workspace, $site, $subscription, $starter, $scale] = makeImmediateUpgradeContext('immediate-upgrade-webhook-retry');

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

    $intent = PaymentIntent::query()->create([
        'id' => (string) Str::uuid(),
        'billable_type' => SubscriptionPlanChange::class,
        'billable_id' => $change->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 5000,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_upgrade_retry_1',
        'idempotency_key' => 'upgrade-retry:' . $change->id,
        'paid_at' => now()->subMinute(),
    ]);

    $change->payment_intent_id = $intent->id;
    $change->save();

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_upgrade_retry_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_upgrade_retry_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array
        {
            return [
                'id' => $providerPaymentId,
                'status' => 'failed',
                'is_paid' => false,
                'is_failed' => true,
                'is_canceled' => false,
                'is_expired' => false,
                'is_refunded' => false,
            ];
        }
        public function parseWebhook(string $rawBody): array
        {
            parse_str($rawBody, $parsed);
            $id = (string) ($parsed['id'] ?? '');

            return [
                'provider_event_id' => $id,
                'event_type' => 'payment.updated',
                'provider_payment_id' => $id,
            ];
        }
    }]));

    $this->postJson(route('webhooks.mollie'), ['id' => 'tr_upgrade_retry_1'])
        ->assertOk();

    expect($change->fresh()->status)->toBe(SubscriptionPlanChangeStatus::APPLIED);
});

function makeImmediateUpgradeContext(string $prefix = 'immediate-upgrade'): array
{
    $organization = Organization::query()->create([
        'name' => 'Immediate Upgrade Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Immediate Upgrade BV',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Immediate Upgrade Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Immediate Upgrade Site',
        'site_url' => 'https://immediate-upgrade.example.com',
        'allowed_domains' => ['immediate-upgrade.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $starter = makeImmediateUpgradePlan('starter-' . $prefix . '-' . Str::random(5), 'Starter', 4900, 120);
    $scale = makeImmediateUpgradePlan('scale-' . $prefix . '-' . Str::random(5), 'Scale', 14900, 600);

    makeImmediateUpgradeFeatures($starter, 1, false);
    makeImmediateUpgradeFeatures($scale, 4, true);

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
        'included_credits_per_interval' => 120,
        'seat_limit' => 3,
        'current_period_start' => now()->subDays(5),
        'current_period_end' => now()->addDays(25),
        'provider_customer_id' => 'cst_' . Str::random(6),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return [$organization, $workspace, $site, $subscription, $starter, $scale];
}

function makeImmediateUpgradePlan(string $key, string $name, int $priceCents, int $credits): Plan
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

function makeImmediateUpgradeFeatures(Plan $plan, int $maxModels, bool $hybridEnabled): void
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
