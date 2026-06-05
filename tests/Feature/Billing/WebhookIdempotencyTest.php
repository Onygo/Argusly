<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('handles mollie webhook idempotently without duplicate credits or invoices', function () {
    $organization = Organization::create([
        'name' => 'Webhook Org',
        'slug' => 'webhook-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Webhook Org BV',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Webhook Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Webhook Site',
        'site_url' => 'https://webhook.example.com',
        'allowed_domains' => ['webhook.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'webhook-pack',
        'name' => 'Webhook pack',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'expires_in_months' => 12,
        'never_expires' => false,
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'credit_pack_id' => $pack->id,
        'status' => 'pending',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'meta' => ['pack_key' => 'webhook-pack'],
    ]);

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 2500,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_webhook_001',
        'idempotency_key' => 'pack:' . $purchase->id,
    ]);

    $fakeProvider = new class implements PaymentProvider
    {
        public function name(): string
        {
            return 'mollie';
        }

        public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array
        {
            return [];
        }

        public function createSubscriptionPaymentIntent(\App\Models\Subscription $subscription, PaymentIntent $intent): array
        {
            return [];
        }

        public function fetchActiveMandateId(string $customerId): ?string
        {
            return 'mdt_test_001';
        }

        public function createRecurringSubscription(\App\Models\Subscription $subscription): array
        {
            return ['provider_subscription_id' => 'sub_test_001', 'status' => 'active'];
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
                'metadata' => [],
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
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$fakeProvider]));

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_webhook_001'])->assertOk();
    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_webhook_001'])->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
    expect(Invoice::query()->count())->toBe(1);

    $purchase->refresh();

    expect($purchase->status)->toBe('paid');

    $packAllocations = \App\Models\CreditLedgerEntry::query()
        ->where('source_type', CreditPackPurchase::class)
        ->where('source_id', $purchase->id)
        ->where('type', 'pack_purchase')
        ->count();

    expect($packAllocations)->toBe(1);
});

it('creates recovered payment intent and invoice for subscription webhook without existing intent', function () {
    $organization = Organization::create([
        'name' => 'Webhook Recovery Org',
        'slug' => 'webhook-recovery-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Webhook Recovery Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Recovery Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Recovery Site',
        'site_url' => 'https://recovery.example.com',
        'allowed_domains' => ['recovery.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'recovery-growth',
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
        'status' => 'active',
        'provider' => 'mollie',
        'provider_customer_id' => 'cst_recovery_1',
        'provider_subscription_id' => 'sub_recovery_1',
    ]);

    $fakeProvider = new class implements PaymentProvider
    {
        public function name(): string
        {
            return 'mollie';
        }

        public function createPackPaymentIntent(CreditPackPurchase $purchase, PaymentIntent $intent): array
        {
            return [];
        }

        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [];
        }

        public function fetchActiveMandateId(string $customerId): ?string
        {
            return 'mdt_recovery_1';
        }

        public function createRecurringSubscription(Subscription $subscription): array
        {
            return ['provider_subscription_id' => 'sub_recovery_1', 'status' => 'active'];
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
                'provider_customer_id' => 'cst_recovery_1',
                'provider_mandate_id' => 'mdt_recovery_1',
                'provider_subscription_id' => 'sub_recovery_1',
                'amount' => ['currency' => 'EUR', 'value' => '129.00'],
                'metadata' => [],
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
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$fakeProvider]));

    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_recovery_001'])->assertOk();
    $this->post('/api/v1/webhooks/mollie', ['id' => 'tr_recovery_001'])->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
    expect(PaymentIntent::query()->where('provider_payment_id', 'tr_recovery_001')->count())->toBe(1);
    expect(Invoice::query()->where('organization_id', $organization->id)->count())->toBe(1);

    $intent = PaymentIntent::query()->where('provider_payment_id', 'tr_recovery_001')->firstOrFail();
    expect((string) $intent->billable_type)->toBe(Subscription::class);
    expect((string) $intent->billable_id)->toBe((string) $subscription->id);
});
