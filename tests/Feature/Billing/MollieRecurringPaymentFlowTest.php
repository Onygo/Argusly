<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Mollie Recurring Payment Flow', function () {
    it('uses sequenceType first for initial subscription payment without mandate', function () {
        [$organization, , , $plan, $subscription] = createBillingFixture(status: 'pending_mandate');

        $capturedPayload = null;

        $provider = new class($capturedPayload) implements PaymentProvider
        {
            public function __construct(private mixed &$capturedPayload)
            {
            }

            public function name(): string
            {
                return 'mollie';
            }

            public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array
            {
                return [];
            }

            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
            {
                // Capture that we were called for a subscription without mandate
                $this->capturedPayload = [
                    'subscription_id' => (string) $subscription->id,
                    'has_mandate' => ! empty($subscription->provider_mandate_id),
                ];

                return [
                    'provider_payment_id' => 'tr_first_001',
                    'checkout_url' => 'https://checkout.mollie.com/first',
                    'status' => 'open',
                    'provider_customer_id' => 'cst_first_001',
                    'provider_mandate_id' => '',
                    'provider_subscription_id' => '',
                    'is_recurring' => false,
                ];
            }

            public function fetchActiveMandateId(string $customerId): ?string
            {
                return null; // No mandate yet
            }

            public function createRecurringSubscription(Subscription $subscription): array
            {
                return [];
            }

            public function fetchPayment(string $providerPaymentId): array
            {
                return [];
            }

            public function parseWebhook(string $rawBody): array
            {
                return [];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'pending',
            'amount_cents' => (int) $subscription->price_cents,
            'currency' => 'EUR',
            'idempotency_key' => 'test:' . $subscription->id,
            'meta' => ['purpose' => 'subscription_initial'],
        ]);

        $providerRegistry = app(PaymentProviderRegistry::class);
        $result = $providerRegistry->get('mollie')->createSubscriptionPaymentIntent($subscription, $intent);

        expect($capturedPayload['has_mandate'])->toBeFalse();
        expect($result['is_recurring'])->toBeFalse();
        expect($result['checkout_url'])->not->toBeEmpty();
    });

    it('uses sequenceType recurring for proration payment when mandate exists', function () {
        [$organization, , , $plan, $subscription] = createBillingFixture(
            status: 'active',
            providerCustomerId: 'cst_existing_001',
            providerMandateId: 'mdt_valid_001'
        );

        $capturedSequenceType = null;

        $provider = new class($capturedSequenceType) implements PaymentProvider
        {
            public function __construct(private mixed &$capturedSequenceType)
            {
            }

            public function name(): string
            {
                return 'mollie';
            }

            public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array
            {
                return [];
            }

            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
            {
                $this->capturedSequenceType = ! empty($subscription->provider_mandate_id) ? 'recurring' : 'first';

                return [
                    'provider_payment_id' => 'tr_recurring_001',
                    'checkout_url' => '', // No checkout URL for recurring
                    'status' => 'pending', // Will become paid via webhook
                    'provider_customer_id' => 'cst_existing_001',
                    'provider_mandate_id' => 'mdt_valid_001',
                    'provider_subscription_id' => '',
                    'is_recurring' => true,
                ];
            }

            public function fetchActiveMandateId(string $customerId): ?string
            {
                return 'mdt_valid_001';
            }

            public function createRecurringSubscription(Subscription $subscription): array
            {
                return [];
            }

            public function fetchPayment(string $providerPaymentId): array
            {
                return [];
            }

            public function parseWebhook(string $rawBody): array
            {
                return [];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'pending',
            'amount_cents' => 1000,
            'currency' => 'EUR',
            'idempotency_key' => 'proration:' . $subscription->id,
            'meta' => ['purpose' => 'plan_change_adjustment'],
        ]);

        $providerRegistry = app(PaymentProviderRegistry::class);
        $result = $providerRegistry->get('mollie')->createSubscriptionPaymentIntent($subscription, $intent);

        expect($capturedSequenceType)->toBe('recurring');
        expect($result['is_recurring'])->toBeTrue();
        expect($result['checkout_url'])->toBeEmpty(); // No checkout for recurring
    });

    it('plan change proration works without checkout URL when mandate exists', function () {
        [$organization, , , $currentPlan, $subscription] = createBillingFixture(
            status: 'active',
            providerCustomerId: 'cst_proration_001',
            providerMandateId: 'mdt_proration_001',
            providerSubscriptionId: 'sub_proration_001'
        );

        $targetPlan = Plan::create([
            'id' => (string) Str::uuid(),
            'key' => 'target-plan',
            'slug' => 'target',
            'name' => 'Target Plan',
            'interval' => 'month',
            'monthly_price_cents' => 9900,
            'price_cents' => 9900,
            'currency' => 'EUR',
            'included_credits' => 200,
            'included_credits_per_interval' => 200,
            'seat_limit' => 5,
            'is_active' => true,
        ]);

        $provider = createRecurringCapableProvider(
            mandateId: 'mdt_proration_001',
            returnRecurring: true
        );

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $planChangeService = app(PlanChangeService::class);

        // This should NOT throw an error even though there's no checkout URL
        $result = $planChangeService->requestChange(
            subscription: $subscription,
            targetPlan: $targetPlan,
            timing: \App\Enums\Billing\PlanChangeTiming::IMMEDIATE_PRORATED
        );

        expect($result['change'])->toBeInstanceOf(SubscriptionPlanChange::class);
        expect($result['payment_intent'])->toBeInstanceOf(PaymentIntent::class);
        expect($result['checkout_url'])->toBeEmpty(); // No checkout for recurring
        expect($result['payment_intent']->status)->toBe('pending');
    });

    it('falls back to first payment when stored mandate is invalid', function () {
        [$organization, , , $plan, $subscription] = createBillingFixture(
            status: 'active',
            providerCustomerId: 'cst_invalid_mandate_001',
            providerMandateId: 'mdt_revoked_001' // This mandate is invalid
        );

        $provider = new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'mollie';
            }

            public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array
            {
                return [];
            }

            public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
            {
                // Simulate: stored mandate is invalid, so fetchActiveMandateId returns null
                // This forces sequenceType to be 'first' with checkout URL
                return [
                    'provider_payment_id' => 'tr_fallback_001',
                    'checkout_url' => 'https://checkout.mollie.com/fallback',
                    'status' => 'open',
                    'provider_customer_id' => 'cst_invalid_mandate_001',
                    'provider_mandate_id' => '',
                    'provider_subscription_id' => '',
                    'is_recurring' => false, // Falls back to first
                ];
            }

            public function fetchActiveMandateId(string $customerId): ?string
            {
                return null; // Mandate is invalid/revoked
            }

            public function createRecurringSubscription(Subscription $subscription): array
            {
                return [];
            }

            public function fetchPayment(string $providerPaymentId): array
            {
                return [];
            }

            public function parseWebhook(string $rawBody): array
            {
                return [];
            }
        };

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $intent = PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'pending',
            'amount_cents' => 1000,
            'currency' => 'EUR',
            'idempotency_key' => 'retry:' . $subscription->id,
            'meta' => ['purpose' => 'plan_change_adjustment'],
        ]);

        $providerRegistry = app(PaymentProviderRegistry::class);
        $result = $providerRegistry->get('mollie')->createSubscriptionPaymentIntent($subscription, $intent);

        // Should fall back to first payment with checkout URL
        expect($result['is_recurring'])->toBeFalse();
        expect($result['checkout_url'])->not->toBeEmpty();
    });

    it('processes recurring renewal payment via webhook and grants credits', function () {
        [$organization, , $site, $plan, $subscription] = createBillingFixture(
            status: 'active',
            providerCustomerId: 'cst_renewal_001',
            providerMandateId: 'mdt_renewal_001',
            providerSubscriptionId: 'sub_renewal_001',
            currentPeriodStart: now()->subMonth(),
            currentPeriodEnd: now()->subDay()
        );

        // Create payment intent for renewal (as Mollie would create it)
        PaymentIntent::create([
            'id' => (string) Str::uuid(),
            'billable_type' => Subscription::class,
            'billable_id' => $subscription->id,
            'provider' => 'mollie',
            'status' => 'open',
            'amount_cents' => (int) $subscription->price_cents,
            'currency' => 'EUR',
            'provider_payment_id' => 'tr_renewal_001',
            'idempotency_key' => 'renewal:' . $subscription->id . ':' . now()->format('Ymd'),
            'meta' => ['purpose' => 'subscription_renewal'],
        ]);

        $provider = createRecurringCapableProvider(
            mandateId: 'mdt_renewal_001',
            payments: [
                'tr_renewal_001' => [
                    'id' => 'tr_renewal_001',
                    'status' => 'paid',
                    'is_paid' => true,
                    'is_failed' => false,
                    'is_canceled' => false,
                    'is_expired' => false,
                    'is_refunded' => false,
                    'provider_customer_id' => 'cst_renewal_001',
                    'provider_mandate_id' => 'mdt_renewal_001',
                    'provider_subscription_id' => 'sub_renewal_001',
                    'amount' => ['currency' => 'EUR', 'value' => '29.00'],
                    'metadata' => [],
                ],
            ],
            subscriptions: [
                'sub_renewal_001' => [
                    'status' => 'active',
                    'next_payment_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                    'canceled_at' => null,
                ],
            ]
        );

        app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

        $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_renewal_001'])->assertOk();

        $subscription->refresh();

        expect($subscription->status)->toBe('active');
        expect($subscription->current_period_start)->not->toBeNull();

        $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_renewal_001');
        expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(1);

        $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
        expect((int) $summary['available'])->toBe((int) $subscription->included_credits_per_interval);
    });
});

function createBillingFixture(
    string $status,
    ?\Illuminate\Support\Carbon $currentPeriodStart = null,
    ?\Illuminate\Support\Carbon $currentPeriodEnd = null,
    string $providerCustomerId = '',
    string $providerMandateId = '',
    string $providerSubscriptionId = ''
): array {
    $organization = Organization::create([
        'name' => 'Recurring Test Org',
        'slug' => 'recurring-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Recurring Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Recurring Test Site',
        'site_url' => 'https://recurring-test.example.com',
        'allowed_domains' => ['recurring-test.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'recurring-test-plan-' . Str::random(4),
        'slug' => 'recurring-test-' . Str::lower(Str::random(4)),
        'name' => 'Recurring Test Plan',
        'interval' => 'month',
        'monthly_price_cents' => 2900,
        'price_cents' => 2900,
        'currency' => 'EUR',
        'included_credits' => 75,
        'included_credits_per_interval' => 75,
        'seat_limit' => 3,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 2900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 75,
        'seat_limit' => 3,
        'status' => $status,
        'current_period_start' => $currentPeriodStart,
        'current_period_end' => $currentPeriodEnd,
        'provider' => 'mollie',
        'provider_customer_id' => $providerCustomerId,
        'provider_mandate_id' => $providerMandateId,
        'provider_subscription_id' => $providerSubscriptionId,
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return [$organization, $workspace, $site, $plan, $subscription];
}

function createRecurringCapableProvider(
    string $mandateId = 'mdt_test_001',
    bool $returnRecurring = true,
    array $payments = [],
    array $subscriptions = []
): PaymentProvider {
    return new class($mandateId, $returnRecurring, $payments, $subscriptions) implements PaymentProvider
    {
        public function __construct(
            private string $mandateId,
            private bool $returnRecurring,
            private array $payments,
            private array $subscriptions
        ) {
        }

        public function name(): string
        {
            return 'mollie';
        }

        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array
        {
            return [];
        }

        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_recurring_' . Str::random(6),
                'checkout_url' => $this->returnRecurring ? '' : 'https://checkout.mollie.com/test',
                'status' => 'pending',
                'provider_customer_id' => (string) ($subscription->provider_customer_id ?? 'cst_test'),
                'provider_mandate_id' => $this->mandateId,
                'provider_subscription_id' => '',
                'is_recurring' => $this->returnRecurring,
            ];
        }

        public function fetchActiveMandateId(string $customerId): ?string
        {
            return $this->mandateId;
        }

        public function createRecurringSubscription(Subscription $subscription): array
        {
            return [
                'provider_subscription_id' => (string) ($subscription->provider_subscription_id ?: 'sub_test_001'),
                'status' => 'active',
            ];
        }

        public function fetchPayment(string $providerPaymentId): array
        {
            return $this->payments[$providerPaymentId] ?? [
                'id' => $providerPaymentId,
                'status' => 'paid',
                'is_paid' => true,
                'is_failed' => false,
                'is_canceled' => false,
                'is_expired' => false,
                'is_refunded' => false,
                'provider_customer_id' => 'cst_test',
                'provider_mandate_id' => $this->mandateId,
                'provider_subscription_id' => 'sub_test_001',
                'amount' => ['currency' => 'EUR', 'value' => '29.00'],
                'metadata' => [],
            ];
        }

        public function parseWebhook(string $rawBody): array
        {
            parse_str($rawBody, $parsed);
            $id = (string) ($parsed['id'] ?? '');

            if (str_starts_with($id, 'sub_')) {
                return [
                    'provider_event_id' => 'sub:' . $id . ':test',
                    'event_type' => 'subscription.updated',
                    'provider_payment_id' => '',
                    'provider_subscription_id' => $id,
                ];
            }

            return [
                'provider_event_id' => $id,
                'event_type' => 'payment.updated',
                'provider_payment_id' => $id,
            ];
        }

        /** @return array{status:string,next_payment_at:?string,canceled_at:?string} */
        public function fetchSubscriptionDetails(string $customerId, string $subscriptionId): array
        {
            return $this->subscriptions[$subscriptionId] ?? [
                'status' => 'active',
                'next_payment_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ];
        }
    };
}
