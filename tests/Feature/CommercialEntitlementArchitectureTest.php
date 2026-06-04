<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Plan;
use App\Services\CreditService;
use App\Services\EntitlementService;
use App\Services\FeatureGate;
use App\Services\PlanResolver;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialEntitlementArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_catalog_seeds_plan_features_entitlements_and_limits(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $starter = Plan::query()->where('key', 'starter_monthly')->firstOrFail();
        $growth = Plan::query()->where('key', 'growth_monthly')->firstOrFail();
        $scale = Plan::query()->where('key', 'scale_monthly')->firstOrFail();
        $enterprise = Plan::query()->where('key', 'enterprise_monthly')->firstOrFail();

        $this->assertDatabaseHas('plan_features', ['plan_id' => $starter->id, 'feature' => 'content', 'enabled' => true]);
        $this->assertDatabaseHas('plan_entitlements', ['plan_id' => $growth->id, 'key' => 'limit:competitors']);
        $this->assertDatabaseHas('feature_limits', ['plan_id' => $starter->id, 'limit_key' => 'brands', 'value' => 1]);
        $this->assertDatabaseHas('feature_limits', ['plan_id' => $growth->id, 'limit_key' => 'competitors', 'value' => 20]);
        $this->assertDatabaseHas('feature_limits', ['plan_id' => $scale->id, 'limit_key' => 'credits', 'value' => 100000]);
        $this->assertDatabaseHas('feature_limits', ['plan_id' => $enterprise->id, 'limit_key' => 'brands', 'unlimited' => true]);
    }

    public function test_entitlement_service_resolves_access_limits_and_remaining_usage(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Starter Account', 'slug' => 'starter-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Starter Brand', 'slug' => 'starter-brand']);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(CreditService::class)->grant($account, 5000, null, 'Starter included credits');

        Competitor::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'First competitor',
            'website' => 'https://competitor.example',
        ]);

        $entitlements = app(EntitlementService::class);

        $this->assertTrue($entitlements->hasAccess($account, 'content'));
        $this->assertFalse($entitlements->hasAccess($account, 'competitive_intelligence'));
        $this->assertSame(1, $entitlements->getLimit($account, 'brands'));
        $this->assertSame(0, $entitlements->getRemaining($account, 'brands'));
        $this->assertSame(3, $entitlements->getLimit($account, 'competitors'));
        $this->assertSame(2, $entitlements->getRemaining($account, 'competitors'));
        $this->assertSame(5000, $entitlements->getLimit($account, 'credits'));
        $this->assertSame(5000, $entitlements->getRemaining($account, 'credits'));
    }

    public function test_feature_gate_uses_account_overrides_and_keeps_tenants_separate(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $first = Account::query()->create(['name' => 'First', 'slug' => 'first']);
        $second = Account::query()->create(['name' => 'Second', 'slug' => 'second']);
        app(SubscriptionService::class)->activatePlan($first, 'starter_monthly');
        app(SubscriptionService::class)->activatePlan($second, 'starter_monthly');

        AccountEntitlement::query()->create([
            'account_id' => $first->id,
            'feature' => 'content',
            'enabled' => false,
        ]);
        AccountEntitlement::query()->create([
            'account_id' => $first->id,
            'feature' => 'competitive_intelligence',
            'limit_key' => 'competitors',
            'value' => 9,
        ]);

        $gate = app(FeatureGate::class);

        $this->assertFalse($gate->hasAccess($first, 'content'));
        $this->assertTrue($gate->hasAccess($second, 'content'));
        $this->assertSame(9, $gate->getLimit($first, 'competitors'));
        $this->assertSame(3, $gate->getLimit($second, 'competitors'));
    }

    public function test_plan_resolver_understands_commercial_plan_families_and_enterprise_unlimited_limits(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => 'Enterprise', 'slug' => 'enterprise']);
        app(SubscriptionService::class)->activatePlan($account, 'enterprise_yearly');

        $resolver = app(PlanResolver::class);
        $entitlements = app(EntitlementService::class);

        $this->assertSame('enterprise_yearly', $resolver->planKey($account));
        $this->assertSame('enterprise', $resolver->family($account));
        $this->assertTrue($resolver->isEnterprise($account));
        $this->assertTrue($entitlements->hasAccess($account, 'agentic_social'));
        $this->assertNull($entitlements->getLimit($account, 'brands'));
        $this->assertNull($entitlements->getRemaining($account, 'brands'));
    }
}
