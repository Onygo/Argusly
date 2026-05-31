<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\RecommendationEngineService;
use App\Services\SignalManager;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_converts_signals_into_recommendations_and_deduplicates(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'content_lifecycle',
            'type' => 'content_opportunity',
            'category' => 'content',
            'priority' => 'high',
            'dedupe_key' => 'lifecycle:test',
            'title' => 'Refresh recommended: Old article',
            'summary' => 'The article has degraded.',
            'recommended_action' => 'Refresh the article.',
            'impact_score' => 82,
            'confidence_score' => 90,
            'payload' => ['content_asset_id' => 123, 'health_score' => 42],
        ], $brand);

        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'signal_id' => $signal->id,
            'title' => 'Refresh article',
            'status' => 'new',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'signal_id' => $signal->id,
            'title' => 'Run content audit',
        ]);

        app(RecommendationEngineService::class)->generateForSignal($signal->refresh());

        $this->assertSame(2, Recommendation::query()->where('signal_id', $signal->id)->count());

        $this->actingAs($user)
            ->get(route('app.intelligence'))
            ->assertOk()
            ->assertSee('Refresh article')
            ->assertSee('Run content audit');
    }

    public function test_dashboard_shows_tenant_brand_scoped_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        app(SignalManager::class)->record($account, [
            'source' => 'content_audit',
            'type' => 'content_audit_completed',
            'category' => 'visibility',
            'priority' => 'medium',
            'dedupe_key' => 'audit:visible',
            'title' => 'Audit completed',
            'summary' => 'Audit completed.',
            'impact_score' => 61,
            'confidence_score' => 88,
            'payload' => ['content_asset_id' => 1, 'score' => 55],
        ], $brand);

        app(SignalManager::class)->record($account, [
            'source' => 'content_audit',
            'type' => 'content_audit_completed',
            'category' => 'visibility',
            'priority' => 'medium',
            'dedupe_key' => 'audit:hidden',
            'title' => 'Hidden audit completed',
            'summary' => 'Hidden audit completed.',
            'impact_score' => 61,
            'confidence_score' => 88,
            'payload' => ['content_asset_id' => 2, 'score' => 55],
        ], $otherBrand);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recommendations')
            ->assertSee('Create FAQ')
            ->assertDontSee('Hidden audit completed');
    }

    public function test_accept_and_dismiss_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'visibility',
            'type' => 'visibility_change',
            'category' => 'visibility',
            'priority' => 'high',
            'dedupe_key' => 'visibility:test',
            'title' => 'Visibility changed',
            'summary' => 'Visibility moved.',
            'impact_score' => 70,
            'confidence_score' => 82,
        ], $brand);
        $recommendation = Recommendation::query()->where('signal_id', $signal->id)->firstOrFail();

        $hiddenRecommendation = Recommendation::query()->create([
            'account_id' => $otherAccount->id,
            'title' => 'Hidden recommendation',
            'summary' => 'Hidden.',
            'recommended_action' => 'Do not expose.',
            'impact_score' => 10,
            'confidence_score' => 10,
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->post(route('app.recommendations.accept', $recommendation))
            ->assertRedirect();

        $this->assertSame('accepted', $recommendation->refresh()->status);

        $this->actingAs($user)
            ->post(route('app.recommendations.dismiss', $recommendation))
            ->assertRedirect();

        $this->assertSame('dismissed', $recommendation->refresh()->status);

        $this->actingAs($user)
            ->post(route('app.recommendations.accept', $hiddenRecommendation))
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
