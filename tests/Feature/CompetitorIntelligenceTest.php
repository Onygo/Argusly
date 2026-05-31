<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Role;
use App\Models\User;
use App\Services\CompetitorService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitorIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_and_list_competitors_for_current_brand(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->post(route('app.competitors.store'), [
                'name' => 'Northstar Analytics',
                'website' => 'northstar.example',
                'industry' => 'Analytics',
                'status' => 'active',
            ])
            ->assertRedirect(route('app.competitors'));

        $this->assertDatabaseHas('competitors', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Northstar Analytics',
            'website' => 'https://northstar.example',
            'industry' => 'Analytics',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.competitors'))
            ->assertOk()
            ->assertSee('Competitor dashboard')
            ->assertSee('Northstar Analytics')
            ->assertSee('AI visibility tracking')
            ->assertSee('SERP tracking')
            ->assertSee('Mention tracking')
            ->assertSee('Brand tracking');
    }

    public function test_service_compares_competitors_using_latest_snapshots(): void
    {
        [, $account, $brand] = $this->tenantWithRole('owner');
        $service = app(CompetitorService::class);

        $alpha = $service->add($account, $brand, [
            'name' => 'Alpha Rival',
            'website' => 'https://alpha-rival.example',
        ]);
        $beta = $service->add($account, $brand, [
            'name' => 'Beta Rival',
            'website' => 'https://beta-rival.example',
        ]);

        $service->captureSnapshot($alpha, [
            'captured_at' => now()->subDay(),
            'visibility_score' => 40,
            'mention_score' => 55,
            'share_of_voice' => 30,
        ]);
        $service->captureSnapshot($beta, [
            'visibility_score' => 82,
            'mention_score' => 30,
            'share_of_voice' => 65,
        ]);

        $comparison = $service->compare($account, $brand);

        $this->assertSame('Beta Rival', $comparison['leaders']['visibility']->name);
        $this->assertSame('Alpha Rival', $comparison['leaders']['mentions']->name);
        $this->assertSame('Beta Rival', $comparison['leaders']['share_of_voice']->name);
        $this->assertEquals(61.0, $comparison['averages']['visibility_score']);
        $this->assertSame(42.5, $comparison['averages']['mention_score']);
        $this->assertSame(47.5, $comparison['averages']['share_of_voice']);
    }

    public function test_competitor_dashboard_is_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta Account', 'slug' => 'beta-account']);
        $thirdBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Third Brand', 'slug' => 'third-brand']);

        Competitor::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visible Competitor',
            'website' => 'https://visible.example',
            'status' => 'active',
        ]);
        Competitor::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden Same Account Competitor',
            'website' => 'https://hidden-same.example',
            'status' => 'active',
        ]);
        Competitor::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $thirdBrand->id,
            'name' => 'Hidden Other Account Competitor',
            'website' => 'https://hidden-other.example',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.competitors'))
            ->assertOk()
            ->assertSee('Visible Competitor')
            ->assertDontSee('Hidden Same Account Competitor')
            ->assertDontSee('Hidden Other Account Competitor');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }
}
