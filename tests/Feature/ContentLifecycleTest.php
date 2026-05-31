<?php

namespace Tests\Feature;

use App\Jobs\CalculateContentLifecycleScoreJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\ContentAudit;
use App\Models\ContentLifecycleScore;
use App\Models\IntelligenceSignal;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentLifecycleService;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_action_dispatches_calculation_job(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Lifecycle target']);

        $this->actingAs($editor)
            ->post(route('app.content.lifecycle', $asset))
            ->assertRedirect(route('app.content.show', $asset));

        Queue::assertPushed(
            CalculateContentLifecycleScoreJob::class,
            fn (CalculateContentLifecycleScoreJob $job) => $job->contentAssetId === $asset->id,
        );
    }

    public function test_lifecycle_job_stores_deterministic_scores(): void
    {
        Queue::fake();

        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Fresh guide',
            'body' => str_repeat('useful content ', 180),
            'published_at' => now()->subDays(20),
            'last_refreshed_at' => now()->subDays(20),
        ]);
        ContentAudit::factory()->forContentAsset($asset)->create([
            'status' => 'completed',
            'score' => 90,
            'audited_at' => now()->subDay(),
        ]);

        app(ContentLifecycleService::class)->requestForContentAsset($asset, $editor);
        (new CalculateContentLifecycleScoreJob($asset->id))->handle(app(ContentLifecycleService::class));

        $score = ContentLifecycleScore::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('healthy', $score->status);
        $this->assertSame(91, $score->health_score);
        $this->assertSame(95, $score->freshness_score);
        $this->assertSame(85, $score->performance_score);
        $this->assertSame(90, $score->visibility_score);
        $this->assertSame(9, $score->refresh_priority);
        $this->assertSame(20, $score->signals['days_since_refresh']);
    }

    public function test_poor_lifecycle_score_creates_intelligence_signal(): void
    {
        Queue::fake();

        [, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Stale short page',
            'body' => 'Short stale content.',
            'published_at' => now()->subDays(500),
            'last_refreshed_at' => now()->subDays(500),
            'metadata' => null,
            'seo_metadata' => null,
        ]);
        ContentAudit::factory()->forContentAsset($asset)->create([
            'status' => 'completed',
            'score' => 20,
            'audited_at' => now()->subDay(),
        ]);

        app(ContentLifecycleService::class)->calculateForContentAsset($asset);

        $score = ContentLifecycleScore::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('critical', $score->status);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'source' => 'content_lifecycle',
            'type' => 'content_opportunity',
            'title' => 'Refresh recommended: Stale short page',
        ]);

        $signal = IntelligenceSignal::query()->where('source', 'content_lifecycle')->firstOrFail();
        $this->assertSame($score->id, $signal->payload['content_lifecycle_score_id']);
        $this->assertSame('critical', $signal->payload['status']);
    }

    public function test_lifecycle_panel_and_badge_are_brand_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visibleAsset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible lifecycle asset']);
        $hiddenAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden lifecycle asset']);

        ContentLifecycleScore::factory()->forContentAsset($visibleAsset)->create([
            'status' => 'watch',
            'health_score' => 70,
            'reason' => 'Visible lifecycle reason',
        ]);
        ContentLifecycleScore::factory()->forContentAsset($hiddenAsset)->create([
            'status' => 'critical',
            'health_score' => 10,
            'reason' => 'Hidden lifecycle reason',
        ]);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertOk()
            ->assertSee('Visible lifecycle asset')
            ->assertSee('Watch · 70')
            ->assertDontSee('Hidden lifecycle asset');

        $this->actingAs($user)
            ->get(route('app.content.show', $visibleAsset))
            ->assertOk()
            ->assertSee('Visible lifecycle reason')
            ->assertDontSee('Hidden lifecycle reason');

        $this->actingAs($user)
            ->post(route('app.content.lifecycle', $hiddenAsset))
            ->assertForbidden();
    }

    public function test_content_module_is_required_for_lifecycle_action(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor', activatePlan: false);
        $asset = ContentAsset::factory()->forBrand($brand)->create();

        $this->actingAs($editor)
            ->post(route('app.content.lifecycle', $asset))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
            app(CreditService::class)->grant($account, 1000, $user, 'Test credits');
        }

        return [$user, $account, $brand];
    }
}
