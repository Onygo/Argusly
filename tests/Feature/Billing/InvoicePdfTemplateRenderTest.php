<?php

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillingSettingsService;
use App\Services\InvoiceService;
use App\ViewModels\InvoicePdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders invoice pdf html with required totals fields', function () {
    $invoice = createInvoiceForPdfTemplateTest();

    app(BillingSettingsService::class)->putInvoiceIssuerProfile(array_merge(
        app(BillingSettingsService::class)->getInvoiceIssuerProfile(),
        ['logo_path' => '']
    ));

    $issuer = app(BillingSettingsService::class)->getInvoiceIssuerProfile();
    $pdfData = InvoicePdfData::fromInvoice($invoice->fresh(['items', 'paymentIntent']), $issuer, null);

    $html = view('pdf.invoice', [
        'invoice' => $invoice,
        'pdf' => $pdfData,
    ])->render();

    expect($html)->toContain((string) $invoice->number);
    expect($html)->toContain((string) $pdfData->subtotalNet);
    expect($html)->toContain((string) $pdfData->vatAmount);
    expect($html)->toContain((string) $pdfData->totalGross);
    expect($html)->toContain('invoice-brand-wordmark');
    expect($html)->toContain('Argusly');
    expect($html)->toContain('aria-label="Argusly brand icon"');
    expect($html)->toContain('font-family: Arial, sans-serif;');
    expect($html)->toContain('body,');
});

it('renders repeating table header css rule for multi-item invoice', function () {
    $invoice = createInvoiceForPdfTemplateTest();

    $invoice->items()->create([
        'description' => 'Extra line item',
        'quantity' => 1,
        'unit_price_cents' => 500,
        'unit_price_net' => '5.00',
        'subtotal_cents' => 500,
        'line_total_net' => '5.00',
        'tax_rate' => '21.00',
        'tax_cents' => 105,
        'vat_amount' => '1.05',
        'total_cents' => 605,
        'line_total_gross' => '6.05',
        'meta' => [],
    ]);

    $issuer = app(BillingSettingsService::class)->getInvoiceIssuerProfile();
    $pdfData = InvoicePdfData::fromInvoice($invoice->fresh(['items', 'paymentIntent']), $issuer, null);

    $html = view('pdf.invoice', [
        'invoice' => $invoice,
        'pdf' => $pdfData,
    ])->render();

    expect($html)->toContain('display: table-header-group;');
});

it('does not duplicate full seller address block between header and footer', function () {
    $invoice = createInvoiceForPdfTemplateTest();

    app(BillingSettingsService::class)->putInvoiceIssuerProfile([
        'company_name' => 'Argusly (part of Onygo)',
        'address_line1' => 'Stationssingel 6',
        'address_line2' => '',
        'postal_code' => '4103 XJ',
        'city' => 'Culemborg',
        'country_code' => 'NL',
        'vat_number' => '',
        'kvk_number' => '70804028',
        'email' => 'billing@argusly.com',
        'website' => 'argusly.com',
        'logo_path' => 'images/pl-logo.svg',
    ]);

    $issuer = app(BillingSettingsService::class)->getInvoiceIssuerProfile();
    $pdfData = InvoicePdfData::fromInvoice($invoice->fresh(['items', 'paymentIntent']), $issuer, null);

    $html = view('pdf.invoice', [
        'invoice' => $invoice,
        'pdf' => $pdfData,
    ])->render();

    expect(substr_count($html, 'Stationssingel 6'))->toBe(1);
    expect(substr_count($html, 'KvK 70804028'))->toBe(1);
    expect(substr_count($html, 'Argusly (part of Onygo)'))->toBeLessThanOrEqual(2);
});

it('renders issuer logo image when issuer logo data is provided', function () {
    $invoice = createInvoiceForPdfTemplateTest();
    $issuer = app(BillingSettingsService::class)->getInvoiceIssuerProfile();
    $pdfData = InvoicePdfData::fromInvoice(
        $invoice->fresh(['items', 'paymentIntent']),
        $issuer,
        'data:image/png;base64,ZmFrZWltYWdl'
    );

    $html = view('pdf.invoice', [
        'invoice' => $invoice,
        'pdf' => $pdfData,
    ])->render();

    expect($html)->toContain('src="data:image/png;base64,ZmFrZWltYWdl"');
    expect($html)->toContain('alt="Argusly logo"');
});

it('returns a pdf response for invoice preview', function () {
    $invoice = createInvoiceForPdfTemplateTest();

    $admin = User::create([
        'name' => 'Billing Admin',
        'email' => 'billing-admin+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $invoice->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.invoices.preview', $invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('renders pdf bytes for invoice with long billing details', function () {
    $invoice = createInvoiceForPdfTemplateTest([
        'billing_company_name' => 'Very Long Customer Company Name For PDF Rendering Validation B.V.',
        'billing_address_line1' => 'Some Extremely Long Address Line To Validate Header Wrapping 1234 Unit 56',
        'billing_address_line2' => 'Attn: Finance Department, Floor 12, Wing C',
        'billing_postal_code' => '1234AB',
        'billing_city' => 's-Hertogenbosch',
    ]);

    $bytes = app(InvoiceService::class)->renderPdfBytes($invoice);

    expect($bytes)->toStartWith('%PDF');
});

function createInvoiceForPdfTemplateTest(array $invoiceOverrides = []): Invoice
{
    $organization = Organization::create([
        'name' => 'PDF Render Org',
        'slug' => 'pdf-render-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'PDF Render Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL123456789B01',
        'billing_kvk_number' => '12345678',
    ]);

    $invoiceData = array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'type' => 'credit_pack',
        'document_type' => 'invoice',
        'number' => 'PL' . now()->format('Y') . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'status' => 'issued',
        'currency' => 'EUR',
        'pricing_mode' => 'vat_inclusive',
        'subtotal_net' => '15.70',
        'vat_amount' => '3.30',
        'total_gross' => '19.00',
        'subtotal_cents' => 1570,
        'tax_cents' => 330,
        'total_cents' => 1900,
        'vat_rate' => '21.00',
        'vat_type' => 'nl_vat',
        'reverse_charge' => false,
        'issued_at' => now(),
        'paid_at' => now(),
        'billing_company_name' => 'PDF Render Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL123456789B01',
        'billing_kvk_number' => '12345678',
        'immutable_hash' => hash('sha256', (string) Str::uuid()),
    ], $invoiceOverrides);

    $invoice = Invoice::create($invoiceData);

    $invoice->items()->create([
        'description' => 'Credit pack purchase',
        'quantity' => 1,
        'unit_price_cents' => 1570,
        'unit_price_net' => '15.70',
        'subtotal_cents' => 1570,
        'line_total_net' => '15.70',
        'tax_rate' => '21.00',
        'tax_cents' => 330,
        'vat_amount' => '3.30',
        'total_cents' => 1900,
        'line_total_gross' => '19.00',
        'meta' => [],
    ]);

    return $invoice;
}
