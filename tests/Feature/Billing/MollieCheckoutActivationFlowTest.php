<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('activates growth and grants included credits from mollie webhook idempotently', function () {
    $organization = Organization::create([
        'name' => 'Growth Org',
        'slug' => 'growth-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Growth Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Growth Site',
        'site_url' => 'https://growth.example.com',
        'allowed_domains' => ['growth.example.com'],
        'is_active' => true,
    ]);

    $growthPlan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'growth-test',
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
        'plan_id' => $growthPlan->id,
        'interval' => 'month',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 400,
        'seat_limit' => 5,
        'status' => 'pending_mandate',
    ]);

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 37900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_sub_growth_001',
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

    $provider = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
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
                'provider_customer_id' => 'cst_growth_1',
                'provider_mandate_id' => 'mdt_growth_1',
                'provider_subscription_id' => 'sub_growth_1',
                'metadata' => [],
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_growth_1'; }
        public function createRecurringSubscription(Subscription $subscription): array
        {
            return ['provider_subscription_id' => 'sub_growth_1', 'status' => 'active'];
        }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_sub_growth_001'])->assertOk();
    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_sub_growth_001'])->assertOk();

    $subscription->refresh();
    $organization->refresh();

    expect($subscription->status)->toBe('active');
    expect((string) $subscription->plan_id)->toBe((string) $growthPlan->id);
    expect((string) $organization->active_subscription_id)->toBe((string) $subscription->id);
    expect(WebhookEvent::query()->count())->toBe(1);
    expect(data_get($subscription->meta, 'onboarding_paid'))->toBeTrue();
    expect(data_get($subscription->meta, 'onboarding_fee_cents'))->toBe(25000);

    $allowanceEntries = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', $subscription->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->count();

    expect($allowanceEntries)->toBe(1);

    $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe(400);
    expect($subscription->invoices()->first()?->items()->count())->toBe(2);
});

it('keeps billing return read-only and waits for webhook processing', function () {
    $organization = Organization::create([
        'name' => 'Return Org',
        'slug' => 'return-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Return Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Return Site',
        'site_url' => 'https://return.example.com',
        'allowed_domains' => ['return.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'return-pack',
        'name' => 'Return pack',
        'credits_amount' => 200,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'credit_pack_id' => $pack->id,
        'status' => 'pending',
        'credits_amount' => 200,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'meta' => ['pack_key' => 'return-pack'],
    ]);

    $intent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 4900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_return_001',
        'idempotency_key' => 'pack:' . $purchase->id,
    ]);

    $this->get(route('billing.pack.return', ['pi' => $intent->id]))
        ->assertOk()
        ->assertSee('Waiting for webhook confirmation before applying credits.');

    $purchase->refresh();
    $intent->refresh();

    expect($purchase->status)->toBe('pending');
    expect($intent->status)->toBe('open');
    expect(CreditLedgerEntry::query()->count())->toBe(0);
});

it('reconciles paid pack on billing return when webhook has not arrived yet', function () {
    $organization = Organization::create([
        'name' => 'Fallback Org',
        'slug' => 'fallback-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Fallback Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Fallback Site',
        'site_url' => 'https://fallback.example.com',
        'allowed_domains' => ['fallback.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'fallback-pack',
        'name' => 'Fallback pack',
        'credits_amount' => 200,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'credit_pack_id' => $pack->id,
        'status' => 'pending',
        'credits_amount' => 200,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'meta' => ['pack_key' => 'fallback-pack'],
    ]);

    $intent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 4900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_return_sync_001',
        'idempotency_key' => 'pack:' . $purchase->id,
    ]);

    $provider = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
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
                'provider_customer_id' => '',
                'provider_mandate_id' => '',
                'provider_subscription_id' => '',
                'metadata' => [],
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return null; }
        public function createRecurringSubscription(Subscription $subscription): array { return []; }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

    $this->get(route('billing.pack.return', ['pi' => $intent->id]))
        ->assertOk()
        ->assertSee('Payment confirmed and credits are available.');

    $purchase->refresh();
    $intent->refresh();

    expect($purchase->status)->toBe('paid');
    expect($intent->status)->toBe('paid');
    expect($intent->paid_at)->not->toBeNull();
    expect(CreditLedgerEntry::query()->count())->toBe(1);
});

it('shows pending growth subscription on billing page without starter fallback when return arrives before webhook', function () {
    $organization = Organization::create([
        'name' => 'Pending Org',
        'slug' => 'pending-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Pending Workspace',
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

    Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'starter-ui-test',
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

    $growth = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'growth-ui-test',
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
        'plan_id' => $growth->id,
        'interval' => 'month',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 400,
        'seat_limit' => 5,
        'status' => 'pending_mandate',
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    $owner = User::create([
        'name' => 'Pending Owner',
        'email' => 'pending-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertSee('Growth')
        ->assertSee('pending_mandate')
        ->assertSee('Your payment is being processed. Your plan will be activated automatically once your payment is received.')
        ->assertDontSee('Start subscription and mandate setup');
});

it('processes webhook for one workspace without affecting another workspace subscription state', function () {
    $orgA = Organization::create([
        'name' => 'Org A',
        'slug' => 'org-a-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $orgB = Organization::create([
        'name' => 'Org B',
        'slug' => 'org-b-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::create(['name' => 'WS A', 'organization_id' => $orgA->id]);
    $workspaceB = Workspace::create(['name' => 'WS B', 'organization_id' => $orgB->id]);

    $siteA = ClientSite::create([
        'workspace_id' => $workspaceA->id,
        'type' => 'wordpress',
        'name' => 'Site A',
        'site_url' => 'https://site-a.example.com',
        'allowed_domains' => ['site-a.example.com'],
        'is_active' => true,
    ]);
    $siteB = ClientSite::create([
        'workspace_id' => $workspaceB->id,
        'type' => 'wordpress',
        'name' => 'Site B',
        'site_url' => 'https://site-b.example.com',
        'allowed_domains' => ['site-b.example.com'],
        'is_active' => true,
    ]);

    $growth = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'growth-scope-test',
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

    $subA = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgA->id,
        'workspace_id' => $workspaceA->id,
        'client_site_id' => $siteA->id,
        'plan_id' => $growth->id,
        'interval' => 'month',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'status' => 'pending_mandate',
    ]);
    $subB = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgB->id,
        'workspace_id' => $workspaceB->id,
        'client_site_id' => $siteB->id,
        'plan_id' => $growth->id,
        'interval' => 'month',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 5,
        'status' => 'pending_mandate',
    ]);

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subA->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 12900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_scope_001',
        'idempotency_key' => 'subscription_signup:' . $subA->id . ':scope',
        'meta' => ['purpose' => 'subscription_initial'],
    ]);

    $provider = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array { return []; }
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
                'provider_customer_id' => 'cst_scope_1',
                'provider_mandate_id' => 'mdt_scope_1',
                'provider_subscription_id' => 'sub_scope_1',
                'metadata' => [],
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_scope_1'; }
        public function createRecurringSubscription(Subscription $subscription): array
        {
            return ['provider_subscription_id' => 'sub_scope_1', 'status' => 'active'];
        }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$provider]));

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_scope_001'])->assertOk();

    $subA->refresh();
    $subB->refresh();

    expect($subA->status)->toBe('active');
    expect($subB->status)->toBe('pending_mandate');

    $summaryA = app(CreditWalletService::class)->getSummary((string) $siteA->id);
    $summaryB = app(CreditWalletService::class)->getSummary((string) $siteB->id);

    expect((int) $summaryA['available'])->toBe(300);
    expect((int) $summaryB['available'])->toBe(0);
});
