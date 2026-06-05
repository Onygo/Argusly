<?php

use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('generates invoices for subscription and credit-pack payments and keeps invoices immutable', function () {
    $organization = Organization::create([
        'name' => 'Invoice Org',
        'slug' => 'invoice-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Invoice Org BV',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL123456789B01',
        'billing_kvk_number' => '12345678',
    ]);

    $workspace = Workspace::create([
        'name' => 'Invoice Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Invoice Site',
        'site_url' => 'https://invoice.example.com',
        'allowed_domains' => ['invoice.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'invoice-pack',
        'name' => 'Invoice pack',
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
        'status' => 'paid',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'paid_at' => now(),
        'meta' => ['pack_key' => 'invoice-pack'],
    ]);

    $packIntent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 2500,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_pack_001',
        'paid_at' => now(),
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'invoice-plan',
        'name' => 'Invoice Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 25,
        'included_credits_per_interval' => 25,
        'seat_limit' => 3,
        'limits' => ['users' => 3],
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 25,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $subscriptionIntent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 4900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_sub_001',
        'paid_at' => now(),
    ]);

    $invoiceService = app(InvoiceService::class);

    $packInvoice = $invoiceService->createForPaymentIntent($packIntent->fresh('billable.clientSite.workspace.organization'));
    $subscriptionInvoice = $invoiceService->createForPaymentIntent($subscriptionIntent->fresh('billable.organization'));

    expect($packInvoice->type)->toBe('credit_pack');
    expect($subscriptionInvoice->type)->toBe('subscription');
    expect($packInvoice->number)->toMatch('/^PL\d{4}\d{5}$/');
    expect($subscriptionInvoice->number)->toMatch('/^PL\d{4}\d{5}$/');
    expect($subscriptionInvoice->number)->not->toBe($packInvoice->number);
    expect($packInvoice->items()->count())->toBe(1);
    expect($subscriptionInvoice->items()->count())->toBe(1);

    $invoiceService->markRefunded($packInvoice, 'refund-001');
    $refreshedPackInvoice = Invoice::query()->findOrFail($packInvoice->id);

    expect($refreshedPackInvoice->status)->toBe('refunded');
    expect($refreshedPackInvoice->refund_reference)->toBe('refund-001');

    $subscriptionInvoice->subtotal_cents = 1;

    expect(fn () => $subscriptionInvoice->save())
        ->toThrow(RuntimeException::class, 'Issued invoice is immutable');
});
