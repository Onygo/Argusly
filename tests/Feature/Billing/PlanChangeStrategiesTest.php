<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\PlanChangeTiming;
use App\Models\ClientSite;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlanChangeService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('handles immediate upgrade with proration payment and adjustment invoice', function () {
    $organization = Organization::create([
        'name' => 'Upgrade Org',
        'slug' => 'upgrade-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Upgrade Org BV',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Upgrade WS',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Upgrade Site',
        'site_url' => 'https://upgrade.example.com',
        'allowed_domains' => ['upgrade.example.com'],
        'is_active' => true,
    ]);

    $starter = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'starter-upgrade',
        'name' => 'Starter',
        'interval' => 'month',
        'monthly_price_cents' => 2000,
        'price_cents' => 2000,
        'currency' => 'EUR',
        'included_credits' => 10,
        'included_credits_per_interval' => 10,
        'seat_limit' => 2,
        'is_active' => true,
    ]);

    $pro = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'pro-upgrade',
        'name' => 'Pro',
        'interval' => 'month',
        'monthly_price_cents' => 8000,
        'price_cents' => 8000,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 10,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'client_site_id' => $site->id,
        'plan_id' => $starter->id,
        'interval' => 'month',
        'price_cents' => 2000,
        'currency' => 'EUR',
        'included_credits_per_interval' => 10,
        'seat_limit' => 2,
        'status' => 'active',
        'current_period_start' => now()->subDays(5),
        'current_period_end' => now()->addDays(25),
        'provider_customer_id' => 'cst_upgrade',
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    $fakeProvider = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return ['provider_payment_id' => 'tr_plan_change_1', 'checkout_url' => 'https://pay.example.com/change', 'status' => 'open'];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$fakeProvider]));

    $planChanges = app(PlanChangeService::class);
    $result = $planChanges->requestChange($subscription, $pro, PlanChangeTiming::IMMEDIATE_PRORATED);

    /** @var SubscriptionPlanChange $change */
    $change = $result['change'];
    /** @var PaymentIntent $intent */
    $intent = $result['payment_intent'];

    expect($change->proration_amount_cents)->toBeGreaterThan(0);
    expect($intent)->not->toBeNull();

    $intent->status = 'paid';
    $intent->paid_at = now();
    $intent->save();

    $planChanges->applyAfterPayment($change->fresh(['subscription', 'paymentIntent', 'toPlan', 'fromPlan']));

    $subscription->refresh();
    expect((string) $subscription->plan_id)->toBe((string) $pro->id);

    expect(Invoice::query()->where('payment_intent_id', $intent->id)->exists())->toBeTrue();
    expect(Invoice::query()->where('type', 'plan_change_adjustment')->exists())->toBeTrue();
});

it('schedules downgrade next period and blocks activation when seat compliance fails', function () {
    $organization = Organization::create([
        'name' => 'Downgrade Org',
        'slug' => 'downgrade-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Downgrade WS',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Downgrade Site',
        'site_url' => 'https://downgrade.example.com',
        'allowed_domains' => ['downgrade.example.com'],
        'is_active' => true,
    ]);

    User::create([
        'name' => 'Owner One',
        'email' => 'owner1+' . Str::random(5) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    User::create([
        'name' => 'Owner Two',
        'email' => 'owner2+' . Str::random(5) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'admin',
        'approved_at' => now(),
        'active' => true,
    ]);

    $pro = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'pro-downgrade',
        'name' => 'Pro',
        'interval' => 'month',
        'monthly_price_cents' => 9000,
        'price_cents' => 9000,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 10,
        'is_active' => true,
    ]);

    $starter = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'starter-downgrade',
        'name' => 'Starter',
        'interval' => 'month',
        'monthly_price_cents' => 3000,
        'price_cents' => 3000,
        'currency' => 'EUR',
        'included_credits' => 20,
        'included_credits_per_interval' => 20,
        'seat_limit' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'client_site_id' => $site->id,
        'plan_id' => $pro->id,
        'interval' => 'month',
        'price_cents' => 9000,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 10,
        'status' => 'active',
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDay(),
    ]);

    app(PlanChangeService::class)->requestChange($subscription, $starter, PlanChangeTiming::NEXT_PERIOD);

    $subscription->refresh();
    expect((string) $subscription->pending_plan_id)->toBe((string) $starter->id);

    app(SubscriptionLifecycleService::class)->handleRenewalPaid($subscription);

    $subscription->refresh();
    expect((string) $subscription->plan_id)->toBe((string) $pro->id);
    expect((string) $subscription->pending_plan_id)->toBe((string) $starter->id);
    expect((string) $subscription->status_reason)->toBe('pending_plan_blocked_seat_non_compliance');
});
