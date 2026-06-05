<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Subscription return sync', function () {

    it('activates subscription from return URL when webhook has not arrived', function () {
        $organization = Organization::create([
            'name' => 'Return Sync Org',
            'slug' => 'return-sync-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Return Sync Company',
            'billing_address_line1' => '123 Test Street',
            'billing_postal_code' => '12345',
            'billing_city' => 'Test City',
            'billing_country_code' => 'NL',
        ]);

        $workspace = Workspace::create([
            'name' => 'Return Sync Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Return Sync Site',
            'site_url' => 'https://return-sync.example.com',
            'allowed_domains' => ['return-sync.example.com'],
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'return-sync-plan',
            'slug' => 'growth',
            'name' => 'Growth',
            'interval' => 'month',
            'monthly_price_cents' => 12900,
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits' => 400,
            'included_credits_per_interval' => 400,
            'seat_limit' => 5,
            'limits' => [
                'has_required_onboarding' => true,
                'onboarding_label' => 'Guided onboarding',
                'onboarding_fee_cents' => 25000,
            ],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 400,
            'seat_limit' => 5,
            'status' => 'pending_mandate',
            'provider' => 'mollie',
            'provider_customer_id' => 'cst_return_sync',
        ]);

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => 37900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_return_sync_sub_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
            'meta' => [
                'purpose' => 'subscription_initial',
                'subscription_id' => (string) $subscription->id,
                'organization_id' => (string) $organization->id,
                'line_items' => [
                    [
                        'code' => 'subscription',
                        'type' => 'recurring',
                        'label' => 'Growth subscription',
                        'amount_cents' => 12900,
                        'description' => 'Growth billed monthly.',
                    ],
                    [
                        'code' => 'onboarding',
                        'type' => 'one_time',
                        'label' => 'Guided Onboarding',
                        'amount_cents' => 25000,
                        'description' => 'Guided onboarding is charged once during the initial checkout only.',
                    ],
                ],
                'total_due_today_cents' => 37900,
                'onboarding_amount_cents' => 25000,
            ],
        ]);

        // Mock the provider to return paid status
        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array
            {
                return [
                    'id' => $providerPaymentId,
                    'status' => 'paid',
                    'is_paid' => true,
                    'is_failed' => false,
                    'is_canceled' => false,
                    'is_expired' => false,
                    'is_refunded' => false,
                    'provider_customer_id' => 'cst_return_sync',
                    'provider_mandate_id' => 'mdt_return_sync',
                    'provider_subscription_id' => 'sub_return_sync',
                    'metadata' => [],
                ];
            }
            public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_return_sync'; }
            public function createRecurringSubscription(Subscription $subscription): array
            {
                return ['provider_subscription_id' => 'sub_return_sync', 'status' => 'active'];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        // Hit the billing return URL (simulates user returning from Mollie)
        $this->get(route('billing.pack.return', ['pi' => $intent->id]))
            ->assertOk()
            ->assertSee('Subscription activated on Growth. Guided Onboarding payment confirmed.');

        $subscription->refresh();
        $intent->refresh();
        $organization->refresh();

        expect($subscription->status)->toBe('active');
        expect($subscription->provider_mandate_id)->toBe('mdt_return_sync');
        expect($subscription->provider_subscription_id)->toBe('sub_return_sync');
        expect($intent->status)->toBe('paid');
        expect($intent->paid_at)->not->toBeNull();
        expect((string) $organization->active_subscription_id)->toBe((string) $subscription->id);
        expect(data_get($subscription->meta, 'onboarding_paid'))->toBeTrue();
        expect(data_get($subscription->meta, 'onboarding_fee_cents'))->toBe(25000);
        expect($subscription->invoices()->first()?->items()->count())->toBe(2);

        // Verify credits were granted
        $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
        expect((int) $summary['available'])->toBe(400);
    });

    it('reactivates subscription on return when intent is already paid but subscription is pending', function () {
        $organization = Organization::create([
            'name' => 'Reactivate Org',
            'slug' => 'reactivate-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Reactivate Company',
            'billing_address_line1' => '456 Test Lane',
            'billing_postal_code' => '67890',
            'billing_city' => 'Reactivate City',
            'billing_country_code' => 'NL',
        ]);

        $workspace = Workspace::create([
            'name' => 'Reactivate Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Reactivate Site',
            'site_url' => 'https://reactivate.example.com',
            'allowed_domains' => ['reactivate.example.com'],
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'reactivate-plan',
            'slug' => 'growth',
            'name' => 'Growth',
            'interval' => 'month',
            'monthly_price_cents' => 12900,
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits' => 400,
            'included_credits_per_interval' => 400,
            'seat_limit' => 5,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 400,
            'seat_limit' => 5,
            'status' => 'pending_mandate', // Still pending despite intent being paid
            'provider' => 'mollie',
            'provider_customer_id' => 'cst_reactivate',
        ]);

        // Intent is already marked as paid (e.g., webhook arrived but didn't activate subscription)
        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(5),
            'amount_cents' => 12900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_reactivate_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
            'meta' => [
                'purpose' => 'subscription_initial',
            ],
        ]);

        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array { return []; }
            public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_reactivate'; }
            public function createRecurringSubscription(Subscription $subscription): array
            {
                return ['provider_subscription_id' => 'sub_reactivate', 'status' => 'active'];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $this->get(route('billing.pack.return', ['pi' => $intent->id]))
            ->assertOk()
            ->assertSee('Subscription activated on Growth.');

        $subscription->refresh();

        expect($subscription->status)->toBe('active');
        expect($subscription->provider_mandate_id)->toBe('mdt_reactivate');
    });

    it('shows processing state when payment is still pending at provider', function () {
        $organization = Organization::create([
            'name' => 'Pending Payment Org',
            'slug' => 'pending-payment-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $workspace = Workspace::create([
            'name' => 'Pending Payment Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Pending Site',
            'site_url' => 'https://pending.example.com',
            'allowed_domains' => ['pending.example.com'],
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'pending-plan',
            'slug' => 'starter',
            'name' => 'Starter',
            'interval' => 'month',
            'monthly_price_cents' => 4900,
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits' => 100,
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'status' => 'pending_mandate',
            'provider' => 'mollie',
        ]);

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => 4900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_pending_sub_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
        ]);

        // Provider still shows open status
        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array
            {
                return [
                    'id' => $providerPaymentId,
                    'status' => 'open',
                    'is_paid' => false,
                    'is_failed' => false,
                    'is_canceled' => false,
                    'is_expired' => false,
                    'metadata' => [],
                ];
            }
            public function fetchActiveMandateId(string $customerId): ?string { return null; }
            public function createRecurringSubscription(Subscription $subscription): array { return []; }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $this->get(route('billing.pack.return', ['pi' => $intent->id]))
            ->assertOk()
            ->assertSee('Subscription activation is in progress');

        $subscription->refresh();

        expect($subscription->status)->toBe('pending_mandate');
    });

    it('shows failed state when payment fails at provider', function () {
        $organization = Organization::create([
            'name' => 'Failed Payment Org',
            'slug' => 'failed-payment-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $workspace = Workspace::create([
            'name' => 'Failed Payment Workspace',
            'organization_id' => $organization->id,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'failed-plan',
            'slug' => 'starter',
            'name' => 'Starter',
            'interval' => 'month',
            'monthly_price_cents' => 4900,
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits' => 100,
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'status' => 'pending_mandate',
            'provider' => 'mollie',
        ]);

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => 4900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_failed_sub_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
        ]);

        // Provider returns failed status
        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array
            {
                return [
                    'id' => $providerPaymentId,
                    'status' => 'failed',
                    'is_paid' => false,
                    'is_failed' => true,
                    'is_canceled' => false,
                    'is_expired' => false,
                    'metadata' => [],
                ];
            }
            public function fetchActiveMandateId(string $customerId): ?string { return null; }
            public function createRecurringSubscription(Subscription $subscription): array { return []; }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $this->get(route('billing.pack.return', ['pi' => $intent->id]))
            ->assertOk()
            ->assertSee('Payment was not completed');

        $intent->refresh();

        expect($intent->status)->toBe('failed');
        expect($intent->failed_at)->not->toBeNull();
    });

    it('grants credits idempotently on multiple return visits', function () {
        $organization = Organization::create([
            'name' => 'Idempotent Org',
            'slug' => 'idempotent-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Idempotent Company',
            'billing_address_line1' => '789 Test Ave',
            'billing_postal_code' => '11111',
            'billing_city' => 'Idempotent City',
            'billing_country_code' => 'NL',
        ]);

        $workspace = Workspace::create([
            'name' => 'Idempotent Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Idempotent Site',
            'site_url' => 'https://idempotent.example.com',
            'allowed_domains' => ['idempotent.example.com'],
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'idempotent-plan',
            'slug' => 'growth',
            'name' => 'Growth',
            'interval' => 'month',
            'monthly_price_cents' => 12900,
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits' => 300,
            'included_credits_per_interval' => 300,
            'seat_limit' => 5,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 12900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 300,
            'seat_limit' => 5,
            'status' => 'pending_mandate',
            'provider' => 'mollie',
            'provider_customer_id' => 'cst_idempotent',
        ]);

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => 12900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_idempotent_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
            'meta' => ['purpose' => 'subscription_initial'],
        ]);

        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array
            {
                return [
                    'id' => $providerPaymentId,
                    'status' => 'paid',
                    'is_paid' => true,
                    'is_failed' => false,
                    'is_canceled' => false,
                    'is_expired' => false,
                    'provider_customer_id' => 'cst_idempotent',
                    'provider_mandate_id' => 'mdt_idempotent',
                    'provider_subscription_id' => 'sub_idempotent',
                    'metadata' => [],
                ];
            }
            public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_idempotent'; }
            public function createRecurringSubscription(Subscription $subscription): array
            {
                return ['provider_subscription_id' => 'sub_idempotent', 'status' => 'active'];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        // First visit activates subscription
        $this->get(route('billing.pack.return', ['pi' => $intent->id]))->assertOk();

        // Second visit should not duplicate credits
        $this->get(route('billing.pack.return', ['pi' => $intent->id]))->assertOk();

        // Third visit should not duplicate credits
        $this->get(route('billing.pack.return', ['pi' => $intent->id]))->assertOk();

        // Verify credits were granted only once
        $allowanceEntries = CreditLedgerEntry::query()
            ->where('source_type', Subscription::class)
            ->where('source_id', $subscription->id)
            ->where('type', CreditWalletService::TYPE_ALLOWANCE)
            ->count();

        expect($allowanceEntries)->toBe(1);

        $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
        expect((int) $summary['available'])->toBe(300);
    });

    it('handles canceled payment on return', function () {
        $organization = Organization::create([
            'name' => 'Canceled Org',
            'slug' => 'canceled-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $workspace = Workspace::create([
            'name' => 'Canceled Workspace',
            'organization_id' => $organization->id,
        ]);

        $plan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'canceled-plan',
            'slug' => 'starter',
            'name' => 'Starter',
            'interval' => 'month',
            'monthly_price_cents' => 4900,
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits' => 100,
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'interval' => 'month',
            'price_cents' => 4900,
            'currency' => 'EUR',
            'included_credits_per_interval' => 100,
            'seat_limit' => 2,
            'status' => 'pending_mandate',
            'provider' => 'mollie',
        ]);

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => 4900,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_canceled_sub_001',
            'idempotency_key' => 'subscription_signup:' . $subscription->id . ':test',
        ]);

        // User canceled the payment
        $provider = new class implements PaymentProvider
        {
            public function name(): string { return 'mollie'; }
            public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
            public function parseWebhook(string $rawBody): array { return []; }
            public function fetchPayment(string $providerPaymentId): array
            {
                return [
                    'id' => $providerPaymentId,
                    'status' => 'canceled',
                    'is_paid' => false,
                    'is_failed' => false,
                    'is_canceled' => true,
                    'is_expired' => false,
                    'metadata' => [],
                ];
            }
            public function fetchActiveMandateId(string $customerId): ?string { return null; }
            public function createRecurringSubscription(Subscription $subscription): array { return []; }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $this->get(route('billing.pack.return', ['pi' => $intent->id]))
            ->assertOk()
            ->assertSee('Payment was not completed');

        $intent->refresh();
        $subscription->refresh();

        expect($intent->status)->toBe('canceled');
        expect($intent->canceled_at)->not->toBeNull();
        expect($subscription->status)->toBe('pending_mandate');
    });

});
