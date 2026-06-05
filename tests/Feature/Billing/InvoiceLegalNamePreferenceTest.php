<?php

use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Workspace;
use App\Services\BillingSettingsService;
use App\Services\InvoiceService;
use App\ViewModels\InvoicePdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uses organization legal_name on invoice and remains stable after workspace display name changes', function () {
    $organization = Organization::query()->create([
        'name' => 'PublishLayer Trading',
        'legal_name' => 'PublishLayer Legal B.V.',
        'slug' => 'legal-pref-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'PublishLayer Trading Name',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL123456789B01',
        'billing_kvk_number' => '12345678',
        'billing_address_line1' => 'Herengracht 10',
        'billing_city' => 'Amsterdam',
        'billing_postal_code' => '1015BR',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace Internal Key',
        'display_name' => 'Workspace Display',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Legal Name Site',
        'site_url' => 'https://legal-name.example.com',
        'allowed_domains' => ['legal-name.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'legal-name-pack',
        'name' => 'Legal Name Pack',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'expires_in_months' => 12,
        'never_expires' => false,
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'credit_pack_id' => $pack->id,
        'status' => 'paid',
        'credits_amount' => 100,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'paid_at' => now(),
        'meta' => ['pack_key' => $pack->key],
    ]);

    $intent = PaymentIntent::query()->create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 2500,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_legal_001',
        'paid_at' => now(),
    ]);

    $invoice = app(InvoiceService::class)
        ->createForPaymentIntent($intent->fresh('billable.clientSite.workspace.organization'));

    expect($invoice->billing_company_name)->toBe('PublishLayer Legal B.V.');

    $workspace->update(['display_name' => 'Workspace Renamed Later']);

    $invoice->refresh();
    expect($invoice->billing_company_name)->toBe('PublishLayer Legal B.V.');

    $issuer = app(BillingSettingsService::class)->getInvoiceIssuerProfile();
    $pdfData = InvoicePdfData::fromInvoice($invoice->fresh(['items', 'paymentIntent']), $issuer, null);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pdf' => $pdfData])->render();

    expect($html)->toContain('PublishLayer Legal B.V.');
});

