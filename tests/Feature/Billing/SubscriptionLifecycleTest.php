<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('supports pending_mandate flow then activation with recurring setup', function () {
    $organization = Organization::create([
        'name' => 'Mandate Org',
        'slug' => 'mandate-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Mandate WS',
        'organization_id' => $organization->id,
    ]);

    ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Mandate Site',
        'site_url' => 'https://mandate.example.com',
        'allowed_domains' => ['mandate.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'mandate-plan',
        'name' => 'Mandate Plan',
        'interval' => 'month',
        'monthly_price_cents' => 5000,
        'price_cents' => 5000,
        'currency' => 'EUR',
        'included_credits' => 50,
        'included_credits_per_interval' => 50,
        'seat_limit' => 3,
        'limits' => [
            'has_required_onboarding' => true,
            'onboarding_label' => 'Guided onboarding',
            'onboarding_fee_cents' => 25000,
        ],
        'is_active' => true,
    ]);

    $providerPending = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_signup_1',
                'checkout_url' => 'https://pay.example.com',
                'status' => 'open',
                'provider_customer_id' => 'cst_1',
                'provider_mandate_id' => '',
                'provider_subscription_id' => null,
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return null; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$providerPending]));

    $lifecycle = app(SubscriptionLifecycleService::class);
    $signup = $lifecycle->startSignup($organization, $plan, ['country_code' => 'NL']);

    /** @var Subscription $subscription */
    $subscription = $signup['subscription']->fresh();
    /** @var PaymentIntent $intent */
    $intent = $signup['payment_intent']->fresh();
    expect($subscription->status)->toBe('pending_mandate');
    expect($intent->amount_cents)->toBe(30000);
    expect(data_get($intent->meta, 'line_items.0.code'))->toBe('subscription');
    expect(data_get($intent->meta, 'line_items.1.code'))->toBe('onboarding');

    $lifecycle->activateRecurringIfMandateReady($subscription->fresh(), true, 'tr_mandate_paid_1');
    expect($subscription->fresh()->status)->toBe('pending_mandate');

    $providerActive = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_rec_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$providerActive]));

    $lifecycle = app(SubscriptionLifecycleService::class);
    $lifecycle->activateRecurringIfMandateReady($subscription->fresh());

    $active = $subscription->fresh();
    expect($active->status)->toBe('active');
    expect((string) $active->provider_subscription_id)->toBe('sub_rec_1');

    $summary = app(CreditWalletService::class)->getSummary((string) $active->client_site_id);
    expect((int) $summary['included_remaining'])->toBeGreaterThanOrEqual(50);
});

it('resets period and included credits on renewal idempotently', function () {
    $organization = Organization::create([
        'name' => 'Renew Org',
        'slug' => 'renew-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Renew WS',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Renew Site',
        'site_url' => 'https://renew.example.com',
        'allowed_domains' => ['renew.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'renew-plan',
        'name' => 'Renew Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4000,
        'price_cents' => 4000,
        'currency' => 'EUR',
        'included_credits' => 20,
        'included_credits_per_interval' => 20,
        'seat_limit' => 2,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4000,
        'currency' => 'EUR',
        'included_credits_per_interval' => 20,
        'seat_limit' => 2,
        'status' => 'active',
        'current_period_start' => now()->subMonth()->startOfDay(),
        'current_period_end' => now()->subDay(),
    ]);

    $lifecycle = app(SubscriptionLifecycleService::class);
    $lifecycle->handleRenewalPaid($subscription, 'tr_renewal_001');
    $lifecycle->handleRenewalPaid($subscription->fresh(), 'tr_renewal_001');

    $allowanceEntries = \App\Models\CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', $subscription->id)
        ->where('type', 'allowance')
        ->count();

    expect($allowanceEntries)->toBe(1);
    expect($subscription->fresh()->current_period_end)->not->toBeNull();
});
