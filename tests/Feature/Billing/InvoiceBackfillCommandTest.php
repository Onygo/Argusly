<?php

use App\Contracts\PdfRenderer;
use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('backfill creates invoice for paid payment with no invoice', function () {
    Storage::fake('local');

    $renderer = new class implements PdfRenderer
    {
        public int $calls = 0;

        public function renderInvoice(array $data): string
        {
            $this->calls++;
            unset($data);

            return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        }
    };
    app()->instance(PdfRenderer::class, $renderer);

    [$organization, $intent] = createPaidPackPaymentForBackfill();

    $exit = Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect($exit)->toBe(0);
    expect(Invoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(1);

    $invoice = Invoice::query()->where('payment_intent_id', $intent->id)->firstOrFail();

    expect($invoice->is_backfilled)->toBeTrue();
    expect($invoice->backfill_source)->toBe('org_current_profile');
    expect($invoice->pdf_status)->toBe('generated');
    expect($invoice->pdf_path)->not->toBeNull();
    expect($renderer->calls)->toBeGreaterThan(0);
    Storage::disk('local')->assertExists((string) $invoice->pdf_path);
});

it('backfill is idempotent on rerun', function () {
    Storage::fake('local');

    [$organization, $intent] = createPaidPackPaymentForBackfill();

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect(Invoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(1);
});

it('backfill skips refunded and chargeback payment intents', function () {
    Storage::fake('local');

    [$organization, $intent] = createPaidPackPaymentForBackfill([
        'status' => 'refunded',
        'last_provider_status' => 'refunded',
        'meta' => ['is_refunded' => true],
    ]);

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect(Invoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(0);
});

it('backfill skips missing billing data and does not create credit transactions', function () {
    Storage::fake('local');

    [$organization, $intent] = createPaidPackPaymentForBackfill();

    $organization->update([
        'billing_company_name' => null,
        'billing_address_line1' => null,
        'billing_country_code' => null,
    ]);

    $beforeCredits = CreditLedgerEntry::query()->count();

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect(Invoice::query()->where('payment_intent_id', $intent->id)->count())->toBe(0);
    expect(CreditLedgerEntry::query()->count())->toBe($beforeCredits);
});

it('backfill creates invoice when intent is open but credit purchase is already paid', function () {
    Storage::fake('local');

    [$organization, $intent] = createPaidPackPaymentForBackfill([
        'status' => 'open',
        'last_provider_status' => null,
    ]);

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    $invoice = Invoice::query()->where('payment_intent_id', $intent->id)->first();

    expect($invoice)->not->toBeNull();
    expect($invoice->is_backfilled)->toBeTrue();
});

it('backfill creates invoice from paid webhook events when no local intent exists', function () {
    Storage::fake('local');

    $organization = Organization::create([
        'name' => 'Webhook Gap Org',
        'slug' => 'webhook-gap-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Webhook Gap Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL111111111B01',
        'billing_kvk_number' => '11111111',
    ]);

    $workspace = Workspace::create([
        'name' => 'Webhook Gap Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Webhook Gap Site',
        'site_url' => 'https://webhook-gap.example.com',
        'allowed_domains' => ['webhook-gap.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'backfill-webhook-plan',
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

    Subscription::create([
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
        'provider_customer_id' => 'cst_gap_001',
        'provider_subscription_id' => 'sub_gap_001',
    ]);

    WebhookEvent::create([
        'id' => (string) Str::uuid(),
        'provider' => 'mollie',
        'provider_event_id' => 'tr_gap_001',
        'event_type' => 'payment.updated',
        'payload' => ['raw' => 'id=tr_gap_001', 'parsed' => ['provider_event_id' => 'tr_gap_001']],
        'headers' => [],
        'source_ip' => '127.0.0.1',
        'received_at' => now(),
    ]);

    app()->instance(PaymentProviderRegistry::class, buildBackfillFakeProviderRegistry([
        'tr_gap_001' => [
            'id' => 'tr_gap_001',
            'status' => 'paid',
            'is_paid' => true,
            'is_failed' => false,
            'is_canceled' => false,
            'is_expired' => false,
            'is_refunded' => false,
            'provider_customer_id' => 'cst_gap_001',
            'provider_mandate_id' => 'mdt_gap_001',
            'provider_subscription_id' => 'sub_gap_001',
            'amount' => ['currency' => 'EUR', 'value' => '129.00'],
            'metadata' => [],
        ],
    ]));

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect(PaymentIntent::query()->where('provider_payment_id', 'tr_gap_001')->count())->toBe(1);
    expect(Invoice::query()->count())->toBe(1);

    Artisan::call('billing:backfill-invoices', [
        '--org_id' => $organization->id,
        '--limit' => 50,
    ]);

    expect(PaymentIntent::query()->where('provider_payment_id', 'tr_gap_001')->count())->toBe(1);
    expect(Invoice::query()->count())->toBe(1);
});

function createPaidPackPaymentForBackfill(array $intentOverrides = []): array
{
    $organization = Organization::create([
        'name' => 'Backfill Org',
        'slug' => 'backfill-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Backfill Org BV',
        'billing_address_line1' => 'Keizersgracht 1',
        'billing_postal_code' => '1015CC',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
        'billing_vat_number' => 'NL999999999B01',
        'billing_kvk_number' => '12345678',
    ]);

    $workspace = Workspace::create([
        'name' => 'Backfill Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Backfill Site',
        'site_url' => 'https://backfill.example.com',
        'allowed_domains' => ['backfill.example.com'],
        'is_active' => true,
    ]);

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'backfill-pack',
        'name' => 'Backfill pack',
        'credits_amount' => 120,
        'price_cents' => 1500,
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
        'credits_amount' => 120,
        'price_cents' => 1500,
        'currency' => 'EUR',
        'paid_at' => now()->subDays(10),
        'meta' => ['pack_key' => 'backfill-pack'],
    ]);

    $intent = PaymentIntent::create(array_merge([
        'id' => (string) Str::uuid(),
        'billable_type' => CreditPackPurchase::class,
        'billable_id' => $purchase->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => 1500,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_backfill_' . Str::random(8),
        'paid_at' => now()->subDays(10),
        'last_provider_status' => 'paid',
        'meta' => [],
    ], $intentOverrides));

    return [$organization, $intent];
}

/**
 * @param array<string,array<string,mixed>> $statusesByPaymentId
 */
function buildBackfillFakeProviderRegistry(array $statusesByPaymentId): PaymentProviderRegistry
{
    $fakeProvider = new class($statusesByPaymentId) implements PaymentProvider
    {
        /**
         * @param array<string,array<string,mixed>> $statusesByPaymentId
         */
        public function __construct(private array $statusesByPaymentId)
        {
        }

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
            return 'mdt_test_001';
        }

        public function createRecurringSubscription(Subscription $subscription): array
        {
            return ['provider_subscription_id' => 'sub_test_001', 'status' => 'active'];
        }

        public function fetchPayment(string $providerPaymentId): array
        {
            return $this->statusesByPaymentId[$providerPaymentId] ?? [
                'id' => $providerPaymentId,
                'status' => 'open',
                'is_paid' => false,
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

    return new PaymentProviderRegistry([$fakeProvider]);
}
