<?php

use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('supports filtering invoice regeneration by organization in dry run mode', function () {
    $orgA = Organization::create([
        'name' => 'Org A',
        'slug' => 'org-a-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $orgB = Organization::create([
        'name' => 'Org B',
        'slug' => 'org-b-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    Invoice::create([
        'organization_id' => $orgA->id,
        'type' => 'subscription',
        'number' => 'PLTESTA0001',
        'status' => 'issued',
        'currency' => 'EUR',
        'subtotal_cents' => 1000,
        'tax_cents' => 0,
        'total_cents' => 1000,
        'issued_at' => now(),
        'billing_company_name' => 'Org A BV',
    ]);

    Invoice::create([
        'organization_id' => $orgB->id,
        'type' => 'subscription',
        'number' => 'PLTESTB0001',
        'status' => 'issued',
        'currency' => 'EUR',
        'subtotal_cents' => 2000,
        'tax_cents' => 0,
        'total_cents' => 2000,
        'issued_at' => now(),
        'billing_company_name' => 'Org B BV',
    ]);

    $exit = Artisan::call('invoices:regenerate-pdfs', [
        '--organization' => (string) $orgA->id,
        '--dry-run' => true,
    ]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Invoices matched: 1');
});
