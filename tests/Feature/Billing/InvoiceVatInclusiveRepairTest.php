<?php

use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Workspace;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('calculates vat-inclusive totals correctly for gross 19 eur at 21 percent', function () {
    Storage::fake('local');

    [$organization, $purchase, $intent] = createPaidPurchaseAndIntent([
        'price_cents' => 1900,
        'amount_cents' => 1900,
        'country_code' => 'NL',
        'vat_number' => null,
    ]);

    $invoice = app(InvoiceService::class)->createForPaymentIntent(
        $intent->fresh('billable.clientSite.workspace.organization')
    );

    expect((string) $invoice->pricing_mode)->toBe('vat_inclusive');
    expect((string) $invoice->subtotal_net)->toBe('15.70');
    expect((string) $invoice->vat_amount)->toBe('3.30');
    expect((string) $invoice->total_gross)->toBe('19.00');
    expect($invoice->total_cents)->toBe(1900);
});

it('sets zero vat for reverse charge in vat-inclusive mode', function () {
    Storage::fake('local');

    [$organization, $purchase, $intent] = createPaidPurchaseAndIntent([
        'price_cents' => 1900,
        'amount_cents' => 1900,
        'country_code' => 'BE',
        'vat_number' => 'BE0123456789',
    ]);

    $invoice = app(InvoiceService::class)->createForPaymentIntent(
        $intent->fresh('billable.clientSite.workspace.organization')
    );

    expect((string) $invoice->vat_amount)->toBe('0.00');
    expect((string) $invoice->subtotal_net)->toBe('19.00');
    expect((string) $invoice->total_gross)->toBe('19.00');
});

it('repair command dry-run detects incorrect vat-added invoices', function () {
    Storage::fake('local');

    [, , $intent] = createPaidPurchaseAndIntent([
        'price_cents' => 1900,
        'amount_cents' => 1900,
        'country_code' => 'NL',
        'vat_number' => null,
    ]);

    $wrong = createWrongLegacyInvoice($intent, 1900, 399, 2299);

    Artisan::call('billing:repair-invoices-vat-inclusive', [
        '--invoice_id' => (string) $wrong->id,
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('incorrect_found');
    expect($output)->toContain('1');

    $wrong->refresh();
    expect($wrong->status)->toBe('issued');
});

it('repair command with credit-note strategy creates correction documents and is idempotent', function () {
    Storage::fake('local');

    [, , $intent] = createPaidPurchaseAndIntent([
        'price_cents' => 1900,
        'amount_cents' => 1900,
        'country_code' => 'NL',
        'vat_number' => null,
    ]);

    $wrong = createWrongLegacyInvoice($intent, 1900, 399, 2299);

    Artisan::call('billing:repair-invoices-vat-inclusive', [
        '--invoice_id' => (string) $wrong->id,
        '--strategy' => 'credit-note',
    ]);

    $wrong->refresh();

    expect($wrong->status)->toBe('voided_for_correction');
    expect($wrong->corrected_at)->not->toBeNull();

    $creditNote = Invoice::query()->where('credit_note_for_invoice_id', $wrong->id)->first();
    $replacement = Invoice::query()->where('replaces_invoice_id', $wrong->id)->first();

    expect($creditNote)->not->toBeNull();
    expect($replacement)->not->toBeNull();
    expect((string) $creditNote->document_type)->toBe('credit_note');
    expect((string) $replacement->document_type)->toBe('invoice');
    expect((string) $replacement->total_gross)->toBe('19.00');
    expect((string) $replacement->vat_amount)->toBe('3.30');
    expect($replacement->pdf_path)->not->toBeNull();

    Artisan::call('billing:repair-invoices-vat-inclusive', [
        '--invoice_id' => (string) $wrong->id,
        '--strategy' => 'credit-note',
    ]);

    expect(Invoice::query()->where('credit_note_for_invoice_id', $wrong->id)->count())->toBe(1);
    expect(Invoice::query()->where('replaces_invoice_id', $wrong->id)->count())->toBe(1);
});

it('recalculate vat-inclusive command detects incorrect invoice in dry-run mode', function () {
    Storage::fake('local');

    [, , $intent] = createPaidPurchaseAndIntent([
        'price_cents' => 1900,
        'amount_cents' => 1900,
        'country_code' => 'NL',
        'vat_number' => null,
    ]);

    $wrong = createWrongLegacyInvoice($intent, 1900, 399, 2299);

    Artisan::call('invoices:recalculate-vat-inclusive', [
        '--invoice_id' => (string) $wrong->id,
        '--dry-run' => true,
    ]);

    $output = Artisan::output();
    $wrong->refresh();

    expect($output)->toContain('detected_wrong');
    expect($output)->toContain('1');
    expect((string) $wrong->total_gross)->toBe('22.99');
    expect($wrong->corrected_at)->toBeNull();
});

function createPaidPurchaseAndIntent(array $overrides = []): array
{
    $organization = Organization::create([
        'name' => 'VAT Org',
        'slug' => 'vat-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'VAT Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => $overrides['country_code'] ?? 'NL',
        'billing_vat_number' => $overrides['vat_number'] ?? null,
        'billing_kvk_number' => '12345678',
    ]);

    $workspace = Workspace::create([
        'name' => 'VAT Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'VAT Site',
        'site_url' => 'https://vat.example.com',
        'allowed_domains' => ['vat.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'vat-pack-' . Str::random(4),
        'name' => 'VAT pack',
        'credits_amount' => 100,
        'price_cents' => (int) ($overrides['price_cents'] ?? 1900),
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
        'price_cents' => (int) ($overrides['price_cents'] ?? 1900),
        'currency' => 'EUR',
        'paid_at' => now()->subDay(),
        'meta' => ['pack_key' => $pack->key],
    ]);

    $intent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => (int) ($overrides['amount_cents'] ?? 1900),
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_vat_' . Str::random(8),
        'paid_at' => now()->subDay(),
        'last_provider_status' => 'paid',
    ]);

    return [$organization, $purchase, $intent];
}

function createWrongLegacyInvoice(PaymentIntent $intent, int $subtotalCents, int $vatCents, int $totalCents): Invoice
{
    $purchase = $intent->billable;
    $organization = $purchase->clientSite->workspace->organization;

    $invoice = Invoice::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'payment_intent_id' => $intent->id,
        'credit_pack_purchase_id' => $purchase->id,
        'type' => 'credit_pack',
        'document_type' => 'invoice',
        'number' => 'PL' . now()->format('Y') . Str::padLeft((string) rand(10000, 99999), 5, '0'),
        'status' => 'issued',
        'currency' => 'EUR',
        'pricing_mode' => 'vat_inclusive',
        'subtotal_net' => number_format($subtotalCents / 100, 2, '.', ''),
        'vat_amount' => number_format($vatCents / 100, 2, '.', ''),
        'total_gross' => number_format($totalCents / 100, 2, '.', ''),
        'subtotal_cents' => $subtotalCents,
        'tax_cents' => $vatCents,
        'total_cents' => $totalCents,
        'vat_rate' => 21,
        'vat_type' => 'nl_vat',
        'reverse_charge' => false,
        'issued_at' => now()->subDay(),
        'paid_at' => now()->subDay(),
        'billing_company_name' => $organization->billing_company_name,
        'billing_address_line1' => $organization->billing_address_line1,
        'billing_postal_code' => $organization->billing_postal_code,
        'billing_city' => $organization->billing_city,
        'billing_country_code' => $organization->billing_country_code,
        'billing_vat_number' => $organization->billing_vat_number,
        'billing_kvk_number' => $organization->billing_kvk_number,
        'immutable_hash' => hash('sha256', (string) Str::uuid()),
    ]);

    $invoice->items()->create([
        'description' => 'Legacy incorrect VAT invoice',
        'quantity' => 1,
        'unit_price_cents' => $subtotalCents,
        'unit_price_net' => number_format($subtotalCents / 100, 2, '.', ''),
        'subtotal_cents' => $subtotalCents,
        'line_total_net' => number_format($subtotalCents / 100, 2, '.', ''),
        'tax_rate' => 21,
        'tax_cents' => $vatCents,
        'vat_amount' => number_format($vatCents / 100, 2, '.', ''),
        'total_cents' => $totalCents,
        'line_total_gross' => number_format($totalCents / 100, 2, '.', ''),
        'meta' => [],
    ]);

    return $invoice;
}
