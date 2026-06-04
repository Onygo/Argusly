<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\ModuleAccessService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_catalog_seeder_creates_modules_and_monthly_yearly_plans(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $this->assertSame(count(config('subscriptions.modules')), Module::query()->count());
        $this->assertDatabaseHas('modules', ['key' => 'connectors']);
        $this->assertDatabaseHas('modules', ['key' => 'agentic_social']);
        $this->assertDatabaseHas('plans', ['key' => 'starter_monthly', 'billing_interval' => 'monthly']);
        $this->assertDatabaseHas('plans', ['key' => 'starter_yearly', 'billing_interval' => 'yearly']);
        $this->assertDatabaseHas('plans', ['key' => 'enterprise_monthly', 'billing_interval' => 'monthly']);
    }

    public function test_account_can_activate_plan_and_inherit_modules(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);

        $subscription = app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        $this->assertTrue($subscription->isActive());
        $this->assertSame('monthly', $subscription->billing_interval);
        $this->assertSame('EUR', $subscription->currency);
        $this->assertTrue(app(ModuleAccessService::class)->accountHasModule($account, 'competitive_intelligence'));
        $this->assertFalse(app(ModuleAccessService::class)->accountHasModule($account, 'lead_intelligence'));
    }

    public function test_yearly_plan_sets_yearly_subscription_period(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);

        $subscription = app(SubscriptionService::class)->activatePlan($account, 'scale_yearly');

        $this->assertSame('yearly', $subscription->billing_interval);
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertGreaterThan(now()->addMonths(11), $subscription->current_period_ends_at);
    }

    public function test_account_can_activate_additional_module_on_active_subscription(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(SubscriptionService::class)->activateModule($account, 'agentic_content');

        $this->assertTrue(app(ModuleAccessService::class)->accountHasModule($account, 'agentic_content'));
    }

    public function test_replacing_plan_cancels_previous_subscription(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);
        $service = app(SubscriptionService::class);

        $first = $service->activatePlan($account, 'starter_monthly');
        $second = $service->activatePlan($account, 'growth_monthly');

        $this->assertDatabaseHas('subscriptions', ['id' => $first->id, 'status' => 'canceled']);
        $this->assertDatabaseHas('subscriptions', ['id' => $second->id, 'status' => 'active']);
        $this->assertSame(1, Subscription::query()->active()->where('account_id', $account->id)->count());
    }

    public function test_cancel_subscription_disables_account_modules(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);
        $service = app(SubscriptionService::class);

        $service->activatePlan($account, 'growth_monthly');
        $service->cancel($account);

        $this->assertFalse(app(ModuleAccessService::class)->accountHasModule($account, 'visibility'));
    }

    public function test_provider_columns_are_available_for_future_mollie_integration(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Argusly', 'slug' => 'argusly']);
        $plan = Plan::query()->where('key', 'starter_monthly')->firstOrFail();

        $subscription = $account->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 9900,
            'provider' => 'mollie',
            'provider_customer_id' => 'cst_future',
            'provider_subscription_id' => 'sub_future',
        ]);

        $this->assertSame('mollie', $subscription->provider);
        $this->assertSame('sub_future', $subscription->provider_subscription_id);
    }
}
