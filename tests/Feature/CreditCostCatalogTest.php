<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditCostCatalog;
use App\Models\CreditCostOverride;
use App\Models\IntelligenceSignal;
use App\Models\User;
use App\Services\CreditCostResolver;
use App\Services\CreditService;
use App\Services\RecommendationEngineService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreditCostCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lookup_and_alias_resolution_use_seeded_costs(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $resolver = app(CreditCostResolver::class);

        $this->assertSame(100, $resolver->resolveCost('blog_generation')['cost']);
        $this->assertSame('blog_generation', $resolver->resolveCost('content_generation')['code']);
        $this->assertSame(50, $resolver->resolveCost('translation')['cost']);
        $this->assertTrue($resolver->supportsOverride('social_repurpose'));
        $this->assertDatabaseHas('credit_cost_catalog', [
            'code' => 'agent_task',
            'default_cost' => 50,
            'cost_type' => 'variable',
        ]);
    }

    public function test_account_and_brand_overrides_resolve_in_order(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $brand] = $this->tenant();
        $catalog = CreditCostCatalog::query()->where('code', 'blog_generation')->firstOrFail();
        CreditCostOverride::query()->create([
            'account_id' => $account->id,
            'credit_cost_catalog_id' => $catalog->id,
            'override_cost' => 80,
            'status' => 'active',
        ]);
        CreditCostOverride::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'credit_cost_catalog_id' => $catalog->id,
            'override_cost' => 60,
            'status' => 'active',
        ]);

        $resolver = app(CreditCostResolver::class);

        $this->assertSame(80, $resolver->resolveCostForAccount($account, 'blog_generation')['cost']);
        $this->assertSame(60, $resolver->resolveCostForBrand($account, $brand, 'blog_generation')['cost']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'CreditCostResolved']);
    }

    public function test_credit_deduction_tracks_catalog_usage_and_domain_events(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $brand] = $this->tenant();
        $user = User::factory()->create();
        app(CreditService::class)->grant($account, 200, $user, 'Test credits');

        $transaction = app(CreditService::class)->consume(
            $account,
            $user,
            'social_repurpose',
            'Generate social post.',
            metadata: ['brand_id' => $brand->id],
        );

        $this->assertSame(-25, $transaction->amount);
        $this->assertSame('social_post_generation', $transaction->type);
        $this->assertSame(175, app(CreditService::class)->balance($account));
        $this->assertDatabaseHas('credit_usage_stats', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'catalog_code' => 'social_post_generation',
            'credits_used' => 25,
            'executions' => 1,
        ]);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'CreditsConsumed', 'subject_id' => $transaction->id]);
    }

    public function test_low_credit_consumption_emits_signal_and_recommendation_ready_event(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account] = $this->tenant();
        $user = User::factory()->create();
        app(CreditService::class)->grant($account, 30, $user, 'Small grant');

        app(CreditService::class)->consume($account, $user, 'content_audit', 'Audit content.');

        $this->assertSame(5, app(CreditService::class)->balance($account));
        $this->assertDatabaseHas('domain_events', ['event_type' => 'LowCreditsDetected']);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'type' => 'credits_low',
        ]);

        $signal = IntelligenceSignal::query()->where('account_id', $account->id)->where('type', 'credits_low')->firstOrFail();
        $recommendations = app(RecommendationEngineService::class)->generateForSignal($signal);

        $this->assertTrue($recommendations->contains(fn ($recommendation) => $recommendation->title === 'Review credit usage and top up'));
    }

    public function test_brand_override_rejects_cross_tenant_brand(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account] = $this->tenant('first');
        [, $otherBrand] = $this->tenant('second');
        $catalog = CreditCostCatalog::query()->where('code', 'translation')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        CreditCostOverride::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'credit_cost_catalog_id' => $catalog->id,
            'override_cost' => 10,
            'status' => 'active',
        ]);
    }

    public function test_insufficient_credits_uses_catalog_cost(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account] = $this->tenant();
        $user = User::factory()->create();
        app(CreditService::class)->grant($account, 24, $user, 'Almost enough');

        $this->expectException(InsufficientCreditsException::class);

        app(CreditService::class)->consume($account, $user, 'content_audit', 'Audit content.');
    }

    /**
     * @return array{0: Account, 1: Brand}
     */
    private function tenant(string $slug = 'credit-costs'): array
    {
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => fake()->unique()->slug()]);

        return [$account, $brand];
    }
}
