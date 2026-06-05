<?php

use App\Models\ClientSite;
use App\Models\CreditPack;
use App\Models\Invoice;
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

it('shows billing and available credits for approved workspace users', function () {
    $organization = Organization::create([
        'name' => 'Client Org',
        'slug' => 'client-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Client Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Client Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Client Site',
        'site_url' => 'https://client.example.com',
        'allowed_domains' => ['client.example.com'],
        'is_active' => true,
    ]);

    CreditPack::create([
        'key' => 'starter-pack',
        'name' => 'Starter Pack',
        'credits_amount' => 500,
        'price_cents' => 2900,
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 150,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'test']
    );

    $user = User::create([
        'name' => 'Workspace Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertSee('Billing & Credits')
        ->assertSee('Starter Pack')
        ->assertSee('150');
});

it('blocks non workspace managers from buying packs', function () {
    $organization = Organization::create([
        'name' => 'Client Org',
        'slug' => 'client-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Client Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Client Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Client Site',
        'site_url' => 'https://client.example.com',
        'allowed_domains' => ['client.example.com'],
        'is_active' => true,
    ]);

    CreditPack::create([
        'key' => 'starter-pack',
        'name' => 'Starter Pack',
        'credits_amount' => 500,
        'price_cents' => 2900,
        'currency' => 'EUR',
        'is_active' => true,
    ]);

    $editor = User::create([
        'name' => 'Workspace Editor',
        'email' => 'editor+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($editor)
        ->post(route('app.billing.packs.purchase'), [
            'client_site_id' => (string) $site->id,
            'pack_key' => 'starter-pack',
        ])
        ->assertForbidden();
});

it('allows owner to update organization billing profile from billing page', function () {
    $organization = Organization::create([
        'name' => 'Client Org',
        'slug' => 'client-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Client Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    User::create([
        'name' => 'Workspace Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $owner = User::query()->where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($owner)
        ->post(route('app.billing.profile.update'), [
            'company_name' => 'Infodation B.V.',
            'address_line1' => 'Herengracht 1',
            'address_line2' => '',
            'postal_code' => '1015AA',
            'city' => 'Amsterdam',
            'country_code' => 'nl',
            'vat_number' => 'NL123456789B01',
            'kvk_number' => '12345678',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Billing details updated.');

    $organization->refresh();

    expect($organization->billing_company_name)->toBe('Infodation B.V.');
    expect($organization->billing_address_line1)->toBe('Herengracht 1');
    expect($organization->billing_country_code)->toBe('NL');
});

it('shows subscription payment invoices scoped to the user organization', function () {
    $organization = Organization::create([
        'name' => 'Billing Scope Org',
        'slug' => 'billing-scope-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Billing Scope Org',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Billing Scope Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Billing Scope Site',
        'site_url' => 'https://scope.example.com',
        'allowed_domains' => ['scope.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'scope-growth',
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
        'provider_customer_id' => 'cst_scope_1',
        'provider_subscription_id' => 'sub_scope_1',
    ]);

    $intent = PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 12900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_scope_subscription_001',
        'idempotency_key' => 'subscription:' . $subscription->id . ':scope',
        'paid_at' => now(),
        'last_provider_status' => 'paid',
        'meta' => ['purpose' => 'subscription_renewal'],
    ]);

    $invoice = Invoice::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'payment_intent_id' => $intent->id,
        'credit_pack_purchase_id' => null,
        'type' => 'subscription',
        'number' => 'INV-SCOPE-001',
        'status' => 'issued',
        'currency' => 'EUR',
        'pricing_mode' => 'vat_inclusive',
        'subtotal_net' => '106.61',
        'vat_amount' => '22.39',
        'total_gross' => '129.00',
        'subtotal_cents' => 10661,
        'tax_cents' => 2239,
        'total_cents' => 12900,
        'vat_rate' => '21.00',
        'vat_type' => 'standard',
        'reverse_charge' => false,
        'document_type' => 'invoice',
        'issued_at' => now(),
        'paid_at' => now(),
        'billing_company_name' => 'Billing Scope Org',
        'billing_address_line1' => 'Damrak 1',
        'billing_address_line2' => null,
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
        'billing_vat_number' => null,
        'billing_kvk_number' => null,
        'immutable_hash' => hash('sha256', 'scope'),
        'meta' => [],
    ]);

    $user = User::create([
        'name' => 'Scope Owner',
        'email' => 'scope-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.billing.index', ['tab' => 'payments']))
        ->assertOk()
        ->assertSee('tr_scope_subscription_001')
        ->assertSee('INV-SCOPE-001');
});
