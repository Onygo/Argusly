<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingInvoice;
use App\Models\CreditBalance;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialOperationsService;
use App\Services\MollieBillingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_open_billing_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $plan] = $this->commercialAccount();
        app(SubscriptionService::class)->activatePlan($account, $plan);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertSee('Billing')
            ->assertSee('Mollie Checkout')
            ->assertSee($account->name)
            ->assertSee('Billing Report');
    }

    public function test_non_admin_cannot_access_billing_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.billing'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_update_plan_catalog(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $plan = Plan::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->put(route('admin.plans.update', $plan), [
                'name' => 'Commercial Foundation',
                'description' => 'Production-ready billing baseline',
                'amount' => 4900,
                'billing_interval' => 'monthly',
                'currency' => 'EUR',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Commercial Foundation',
            'amount' => 4900,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    public function test_mollie_checkout_creates_provider_subscription_without_live_api_key(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        config(['services.mollie.key' => '']);

        [$account, $plan] = $this->commercialAccount();
        $result = app(MollieBillingService::class)->createCheckout($account, $plan, $this->platformAdmin());

        $subscription = $result['subscription'];

        $this->assertSame('mollie', $subscription->provider);
        $this->assertStringStartsWith('test_', $subscription->provider_subscription_id);
        $this->assertSame('https://www.mollie.com/checkout/test-mode', $result['checkout_url']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'event_type' => 'MollieCheckoutCreated',
        ]);
    }

    public function test_commercial_service_creates_mollie_invoice(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $plan] = $this->commercialAccount();
        $subscription = app(SubscriptionService::class)->activatePlan($account, $plan);

        $invoice = app(CommercialOperationsService::class)->createInvoice($account, $subscription);

        $this->assertInstanceOf(BillingInvoice::class, $invoice);
        $this->assertSame('mollie', $invoice->provider);
        $this->assertSame('open', $invoice->status);
        $this->assertSame($plan->amount, $invoice->subtotal_amount);
        $this->assertSame((int) round($plan->amount * 0.21), $invoice->tax_amount);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'event_type' => 'BillingInvoiceCreated',
        ]);
    }

    public function test_overage_handling_records_credit_charge_and_event(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account] = $this->commercialAccount();
        CreditBalance::query()->create(['account_id' => $account->id, 'balance' => 500]);

        $transaction = app(CommercialOperationsService::class)->recordOverage($account, 'brands', 7, 5);

        $this->assertInstanceOf(CreditTransaction::class, $transaction);
        $this->assertSame(-20, $transaction->amount);
        $this->assertTrue((bool) $transaction->metadata['overage']);
        $this->assertDatabaseHas('credit_balances', ['account_id' => $account->id, 'balance' => 480]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'event_type' => 'CommercialOverageRecorded',
        ]);
    }

    public function test_mollie_webhook_marks_matching_subscription_and_invoice_paid(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $plan] = $this->commercialAccount();
        $subscription = app(SubscriptionService::class)->activatePlan($account, $plan);
        $subscription->forceFill([
            'provider' => 'mollie',
            'provider_subscription_id' => 'tr_test_payment',
            'status' => 'past_due',
        ])->save();

        BillingInvoice::query()->create([
            'account_id' => $account->id,
            'subscription_id' => $subscription->id,
            'provider' => 'mollie',
            'provider_payment_id' => 'tr_test_payment',
            'status' => 'open',
            'currency' => 'EUR',
            'subtotal_amount' => 1000,
            'tax_amount' => 210,
            'total_amount' => 1210,
            'line_items' => [['description' => 'Plan', 'amount' => 1000]],
        ]);

        $this->post(route('billing.mollie.webhook'), ['id' => 'tr_test_payment'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('billing_invoices', [
            'account_id' => $account->id,
            'provider_payment_id' => 'tr_test_payment',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{Account, Plan}
     */
    private function commercialAccount(): array
    {
        $account = Account::query()->create(['name' => 'Commercial Tenant', 'slug' => 'commercial-tenant']);
        $plan = Plan::query()->where('is_active', true)->orderBy('amount')->firstOrFail();

        return [$account, $plan];
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
