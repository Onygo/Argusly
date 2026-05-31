<?php

namespace Tests\Feature;

use App\Jobs\CalculateContentLifecycleScoreJob;
use App\Models\Account;
use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\ContentAudit;
use App\Models\ContentLifecycleScore;
use App\Models\ContentTranslation;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\Role;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SearchConsoleSite;
use App\Models\SocialPost;
use App\Models\SocialProfile;
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

    public function test_lifecycle_scoring_uses_ga4_search_social_translation_and_answer_signals(): void
    {
        Queue::fake();

        [, $account, $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'nl', 'de']]);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Analytics decaying guide',
            'body' => str_repeat('clear practical advice ', 80),
            'language' => 'en',
            'published_at' => now()->subDays(240),
            'last_refreshed_at' => now()->subDays(240),
        ]);
        ContentAudit::factory()->forContentAsset($asset)->create([
            'status' => 'completed',
            'score' => 62,
            'audited_at' => now()->subDays(10),
        ]);

        $property = $this->ga4Property($account, $brand);
        $site = $this->searchConsoleSite($account, $brand);
        $this->ga4Snapshot($property, $asset, now()->subDays(45), 1000);
        $this->ga4Snapshot($property, $asset, now()->subDays(5), 300);
        $this->searchSnapshot($site, $asset, now()->subDays(45), 200, 5000, 0.04, 6.0);
        $this->searchSnapshot($site, $asset, now()->subDays(5), 80, 5000, 0.01, 14.0);

        app(ContentLifecycleService::class)->calculateForContentAsset($asset);

        $score = ContentLifecycleScore::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('needs_refresh', $score->status);
        $this->assertSame(300, $score->signals['ga4']['current_sessions']);
        $this->assertSame(1000, $score->signals['ga4']['previous_sessions']);
        $this->assertSame('declining', $score->signals['ga4']['trend']);
        $this->assertSame(80, $score->signals['search_console']['current_clicks']);
        $this->assertSame(200, $score->signals['search_console']['previous_clicks']);
        $this->assertSame(['nl', 'de'], $score->signals['translation_coverage']['missing_languages']);

        $this->assertContains('refresh content', $score->signals['recommendations']);
        $this->assertContains('improve title/meta for CTR', $score->signals['recommendations']);
        $this->assertContains('create social distribution', $score->signals['recommendations']);
        $this->assertContains('translate to missing languages', $score->signals['recommendations']);
        $this->assertContains('add answer blocks', $score->signals['recommendations']);
        $this->assertContains('run audit', $score->signals['recommendations']);
    }

    public function test_lifecycle_recommendations_clear_when_distribution_translation_and_answers_exist(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantWithRole('editor');
        $brand->update(['enabled_content_languages' => ['en', 'nl']]);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Distributed evergreen guide',
            'body' => str_repeat('helpful evergreen advice ', 120),
            'language' => 'en',
            'published_at' => now()->subDays(40),
            'last_refreshed_at' => now()->subDays(40),
        ]);
        ContentAudit::factory()->forContentAsset($asset)->create([
            'status' => 'completed',
            'score' => 88,
            'audited_at' => now()->subDays(3),
        ]);
        $connection = $this->linkedinConnection($user, $account, $brand);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'linkedin-page-1',
            'display_name' => 'Alpha LinkedIn',
            'type' => 'page',
            'status' => 'connected',
        ]);
        SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'published',
            'post_text' => 'New guide is live.',
            'language' => 'en',
            'published_at' => now()->subDay(),
        ]);
        ContentTranslation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_content_asset_id' => $asset->id,
            'source_language' => 'en',
            'target_language' => 'nl',
            'status' => 'completed',
        ]);
        AnswerBlock::factory()->forContentAsset($asset)->create([
            'status' => 'published',
            'language' => 'en',
        ]);

        app(ContentLifecycleService::class)->calculateForContentAsset($asset);

        $score = ContentLifecycleScore::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('healthy', $score->status);
        $this->assertTrue($score->signals['social_distribution']['published_or_scheduled']);
        $this->assertSame([], $score->signals['translation_coverage']['missing_languages']);
        $this->assertSame(1, $score->signals['answer_blocks_count']);
        $this->assertNotContains('create social distribution', $score->signals['recommendations']);
        $this->assertNotContains('translate to missing languages', $score->signals['recommendations']);
        $this->assertNotContains('add answer blocks', $score->signals['recommendations']);
    }

    public function test_lifecycle_analytics_are_tenant_safe(): void
    {
        Queue::fake();

        [, $account, $brand] = $this->tenantWithRole('editor');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Tenant safe asset',
            'body' => str_repeat('stable content ', 120),
            'published_at' => now()->subDays(20),
            'last_refreshed_at' => now()->subDays(20),
        ]);
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create();
        ContentAudit::factory()->forContentAsset($asset)->create([
            'status' => 'completed',
            'score' => 90,
            'audited_at' => now()->subDay(),
        ]);
        $property = $this->ga4Property($account, $otherBrand);
        $site = $this->searchConsoleSite($account, $otherBrand);
        $this->ga4Snapshot($property, $otherAsset, now()->subDays(45), 1000);
        $this->ga4Snapshot($property, $otherAsset, now()->subDays(5), 0);
        $this->searchSnapshot($site, $otherAsset, now()->subDays(45), 400, 8000, 0.05, 4.0);
        $this->searchSnapshot($site, $otherAsset, now()->subDays(5), 0, 8000, 0.0, 30.0);

        app(ContentLifecycleService::class)->calculateForContentAsset($asset);

        $score = ContentLifecycleScore::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('healthy', $score->status);
        $this->assertFalse($score->signals['ga4']['has_data']);
        $this->assertFalse($score->signals['search_console']['has_data']);
        $this->assertNotContains('improve title/meta for CTR', $score->signals['recommendations']);
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
            'signals' => ['recommendations' => ['refresh content']],
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
            ->assertSee('Lifecycle recommendations')
            ->assertSee('Refresh Content')
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

    private function ga4Property(Account $account, Brand $brand): Ga4Property
    {
        return Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'Lifecycle GA4',
            'website_url' => 'https://example.test',
            'status' => 'connected',
            'metadata' => ['property_id' => 'properties/'.fake()->numberBetween(1000, 9999)],
        ]);
    }

    private function searchConsoleSite(Account $account, Brand $brand): SearchConsoleSite
    {
        return SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://example.test/',
            'status' => 'connected',
            'metadata' => ['permission_level' => 'siteOwner'],
        ]);
    }

    private function linkedinConnection(User $user, Account $account, Brand $brand): IntegrationConnection
    {
        $integration = Integration::query()->firstOrCreate(
            ['key' => 'linkedin'],
            [
                'name' => 'LinkedIn',
                'auth_type' => 'oauth',
                'default_scopes' => [],
                'supports_refresh_tokens' => true,
                'is_active' => true,
                'is_system' => true,
            ],
        );

        return IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn page',
            'status' => 'active',
            'provider_account_name' => 'Alpha LinkedIn',
            'scopes' => [],
            'access_token' => 'linkedin-token',
            'metadata' => ['provider' => 'linkedin'],
        ]);
    }

    private function ga4Snapshot(Ga4Property $property, ContentAsset $asset, mixed $date, int $sessions): void
    {
        Ga4MetricSnapshot::query()->create([
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'ga4_property_id' => $property->id,
            'content_asset_id' => $asset->id,
            'page_path' => '/'.$asset->slug,
            'date' => $date,
            'sessions' => $sessions,
            'users' => $sessions,
            'pageviews' => $sessions,
            'engagement_rate' => 0.45,
            'conversions' => 0,
        ]);
    }

    private function searchSnapshot(SearchConsoleSite $site, ContentAsset $asset, mixed $date, int $clicks, int $impressions, float $ctr, float $position): void
    {
        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset->id,
            'date' => $date,
            'query' => 'content lifecycle',
            'page' => $asset->canonical_url ?? 'https://example.test/'.$asset->slug,
            'country' => 'usa',
            'device' => 'desktop',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
        ]);
    }
}
