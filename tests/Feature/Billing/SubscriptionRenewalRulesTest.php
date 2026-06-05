<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('grants monthly credits on first paid subscription payment', function () {
    [$organization, $workspace, $site, $plan, $subscription] = billingFixture(status: 'pending_mandate');

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => (int) $subscription->price_cents,
        'currency' => (string) $subscription->currency,
        'provider_payment_id' => 'tr_initial_001',
        'idempotency_key' => 'subscription_signup:' . $subscription->id . ':initial',
        'meta' => [
            'purpose' => 'subscription_initial',
            'subscription_id' => (string) $subscription->id,
            'organization_id' => (string) $organization->id,
        ],
    ]);

    useMollieFakeProvider(
        payments: [
            'tr_initial_001' => paidPaymentStatus('tr_initial_001', 'sub_initial_001'),
        ],
        subscriptions: [
            'sub_initial_001' => [
                'status' => 'active',
                'next_payment_at' => now()->addMonth()->startOfDay()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ],
        ]
    );

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_initial_001'])->assertOk();

    $subscription->refresh();

    expect($subscription->status)->toBe('active')
        ->and($subscription->next_payment_at)->not->toBeNull();

    $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_initial_001');
    expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(1);

    $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe((int) $subscription->included_credits_per_interval);
});

it('grants renewal credits exactly once for one paid renewal payment', function () {
    [, , $site, , $subscription] = billingFixture(
        status: 'active',
        currentPeriodStart: now()->subMonth()->startOfDay(),
        currentPeriodEnd: now()->subDay()->startOfDay(),
        providerCustomerId: 'cst_renew',
        providerSubscriptionId: 'sub_renew_001'
    );

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => (int) $subscription->price_cents,
        'currency' => (string) $subscription->currency,
        'provider_payment_id' => 'tr_renew_001',
        'idempotency_key' => 'subscription_renewal:' . $subscription->id . ':001',
        'meta' => [
            'purpose' => 'subscription_renewal',
            'subscription_id' => (string) $subscription->id,
        ],
    ]);

    useMollieFakeProvider(
        payments: [
            'tr_renew_001' => paidPaymentStatus('tr_renew_001', 'sub_renew_001'),
        ],
        subscriptions: [
            'sub_renew_001' => [
                'status' => 'active',
                'next_payment_at' => now()->addMonth()->startOfDay()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ],
        ]
    );

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_renew_001'])->assertOk();

    $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_renew_001');
    expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(1);

    $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe((int) $subscription->included_credits_per_interval);
});

it('does not double grant credits on duplicate renewal webhook retries', function () {
    [, , , , $subscription] = billingFixture(
        status: 'active',
        currentPeriodStart: now()->subMonth()->startOfDay(),
        currentPeriodEnd: now()->subDay()->startOfDay(),
        providerCustomerId: 'cst_retry',
        providerSubscriptionId: 'sub_retry_001'
    );

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => (int) $subscription->price_cents,
        'currency' => (string) $subscription->currency,
        'provider_payment_id' => 'tr_retry_001',
        'idempotency_key' => 'subscription_renewal:' . $subscription->id . ':retry',
        'meta' => [
            'purpose' => 'subscription_renewal',
            'subscription_id' => (string) $subscription->id,
        ],
    ]);

    useMollieFakeProvider(
        payments: [
            'tr_retry_001' => paidPaymentStatus('tr_retry_001', 'sub_retry_001'),
        ],
        subscriptions: [
            'sub_retry_001' => [
                'status' => 'active',
                'next_payment_at' => now()->addMonth()->startOfDay()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ],
        ]
    );

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_retry_001'])->assertOk();
    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_retry_001'])->assertOk();

    $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_retry_001');
    expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(1)
        ->and(WebhookEvent::query()->where('provider', 'mollie')->where('provider_event_id', 'tr_retry_001')->count())->toBe(1);
});

it('does not grant credits on failed renewal payment and marks subscription past_due', function () {
    [, , , , $subscription] = billingFixture(
        status: 'active',
        currentPeriodStart: now()->subMonth()->startOfDay(),
        currentPeriodEnd: now()->subDay()->startOfDay(),
        providerCustomerId: 'cst_failed',
        providerSubscriptionId: 'sub_failed_001'
    );

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => (int) $subscription->price_cents,
        'currency' => (string) $subscription->currency,
        'provider_payment_id' => 'tr_failed_001',
        'idempotency_key' => 'subscription_renewal:' . $subscription->id . ':failed',
        'meta' => [
            'purpose' => 'subscription_renewal',
            'subscription_id' => (string) $subscription->id,
        ],
    ]);

    useMollieFakeProvider(
        payments: [
            'tr_failed_001' => [
                'id' => 'tr_failed_001',
                'status' => 'failed',
                'is_paid' => false,
                'is_failed' => true,
                'is_canceled' => false,
                'is_expired' => false,
                'is_refunded' => false,
                'provider_customer_id' => 'cst_failed',
                'provider_mandate_id' => 'mdt_failed',
                'provider_subscription_id' => 'sub_failed_001',
                'amount' => ['currency' => 'EUR', 'value' => '29.00'],
                'metadata' => [],
            ],
        ],
        subscriptions: [
            'sub_failed_001' => [
                'status' => 'active',
                'next_payment_at' => now()->addMonth()->startOfDay()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ],
        ]
    );

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_failed_001'])->assertOk();

    $subscription->refresh();

    $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_failed_001');
    expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(0)
        ->and($subscription->status)->toBe('past_due');
});

it('stops future subscription credit grants after provider cancellation', function () {
    [, , , , $subscription] = billingFixture(
        status: 'active',
        currentPeriodStart: now()->subDays(5)->startOfDay(),
        currentPeriodEnd: now()->addDays(10)->startOfDay(),
        providerCustomerId: 'cst_cancel',
        providerSubscriptionId: 'sub_cancel_001'
    );

    useMollieFakeProvider(
        payments: [
            'tr_after_cancel_001' => paidPaymentStatus('tr_after_cancel_001', 'sub_cancel_001'),
        ],
        subscriptions: [
            'sub_cancel_001' => [
                'status' => 'canceled',
                'next_payment_at' => null,
                'canceled_at' => now()->toDateTimeString(),
            ],
        ]
    );

    $this->post('/api/v1/webhooks/mollie', ['id' => 'sub_cancel_001'])->assertOk();

    $subscription->refresh();
    expect($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->status_reason)->toBe('canceled_effective_period_end');

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => (int) $subscription->price_cents,
        'currency' => (string) $subscription->currency,
        'provider_payment_id' => 'tr_after_cancel_001',
        'idempotency_key' => 'subscription_renewal:' . $subscription->id . ':after_cancel',
        'meta' => [
            'purpose' => 'subscription_renewal',
            'subscription_id' => (string) $subscription->id,
        ],
    ]);

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_after_cancel_001'])->assertOk();

    $allowanceKey = sprintf('allowance:sub:%s:payment:%s', (string) $subscription->id, 'tr_after_cancel_001');
    expect(CreditLedgerEntry::query()->where('idempotency_key', $allowanceKey)->count())->toBe(0);
});

function billingFixture(
    string $status,
    ?\Illuminate\Support\Carbon $currentPeriodStart = null,
    ?\Illuminate\Support\Carbon $currentPeriodEnd = null,
    string $providerCustomerId = '',
    string $providerSubscriptionId = ''
): array {
    $organization = Organization::create([
        'name' => 'Billing Rules Org',
        'slug' => 'billing-rules-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Billing Rules Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Billing Rules Site',
        'site_url' => 'https://billing-rules.example.com',
        'allowed_domains' => ['billing-rules.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'rules-plan-' . Str::random(4),
        'slug' => 'rules-plan-' . Str::lower(Str::random(4)),
        'name' => 'Rules Plan',
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
        'provider_subscription_id' => $providerSubscriptionId,
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return [$organization, $workspace, $site, $plan, $subscription];
}

function useMollieFakeProvider(array $payments, array $subscriptions): void
{
    $provider = new class($payments, $subscriptions) implements PaymentProvider
    {
        public function __construct(private array $payments, private array $subscriptions)
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
            return [];
        }

        public function fetchActiveMandateId(string $customerId): ?string
        {
            return 'mdt_test';
        }

        public function createRecurringSubscription(Subscription $subscription): array
        {
            return ['provider_subscription_id' => (string) ($subscription->provider_subscription_id ?: 'sub_generated_001'), 'status' => 'active'];
        }

        public function fetchPayment(string $providerPaymentId): array
        {
            return $this->payments[$providerPaymentId] ?? paidPaymentStatus($providerPaymentId, 'sub_default_001');
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
                'next_payment_at' => now()->addMonth()->startOfDay()->format('Y-m-d H:i:s'),
                'canceled_at' => null,
            ];
        }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));
}

function paidPaymentStatus(string $paymentId, string $providerSubscriptionId): array
{
    return [
        'id' => $paymentId,
        'status' => 'paid',
        'is_paid' => true,
        'is_failed' => false,
        'is_canceled' => false,
        'is_expired' => false,
        'is_refunded' => false,
        'provider_customer_id' => 'cst_paid_001',
        'provider_mandate_id' => 'mdt_paid_001',
        'provider_subscription_id' => $providerSubscriptionId,
        'amount' => ['currency' => 'EUR', 'value' => '29.00'],
        'metadata' => [],
    ];
}
