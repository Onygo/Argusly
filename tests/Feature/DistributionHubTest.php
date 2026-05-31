<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\ContentTranslation;
use App\Models\IntelligenceSignal;
use App\Models\PublishingChannel;
use App\Models\PublishingAction;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\RecommendationEngineService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_hub_shows_distribution_state_and_is_tenant_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);

        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Visible distribution asset',
            'status' => 'approved',
            'language' => 'en',
            'locale' => 'en_US',
        ]);
        ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden distribution asset']);

        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Spring launch',
            'slug' => 'spring-launch',
            'status' => 'active',
        ]);
        $campaign->contentAssets()->attach($asset);

        PublishingAction::factory()->forContentAsset($asset)->create([
            'action' => 'publish',
            'status' => 'completed',
        ]);

        $profile = $this->linkedInProfile($user, $account, $brand);
        SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'campaign_id' => $campaign->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'scheduled',
            'post_text' => 'Launch post',
            'language' => 'en',
            'locale' => 'en_US',
            'scheduled_at' => now()->addDay(),
            'created_by' => $user->id,
        ]);

        $signal = IntelligenceSignal::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'test',
            'type' => 'content_opportunity',
            'category' => 'content',
            'priority' => 'medium',
            'title' => 'Distribution signal',
            'summary' => 'Needs more distribution.',
            'impact_score' => 70,
            'confidence_score' => 88,
            'status' => 'new',
            'payload' => ['content_asset_id' => $asset->id],
        ]);
        Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'signal_id' => $signal->id,
            'title' => 'Create LinkedIn post',
            'summary' => 'Repurpose this content.',
            'recommended_action' => 'Create a LinkedIn post from the article.',
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->get(route('app.distribution'))
            ->assertOk()
            ->assertSee('Content Distribution Hub')
            ->assertSee('Visible distribution asset')
            ->assertSee('Completed')
            ->assertSee('Scheduled')
            ->assertSee('Spring launch')
            ->assertSee('Create LinkedIn post')
            ->assertSee('EN · en_US')
            ->assertDontSee('Hidden distribution asset');
    }

    public function test_distribution_recommendation_rules_are_idempotent_and_brand_aware(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $brand->update([
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl', 'de'],
        ]);

        $approved = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Approved but waiting',
            'status' => 'approved',
            'language' => 'en',
        ]);
        $publishedNoLinkedIn = ContentAsset::factory()->forBrand($brand)->published()->create([
            'title' => 'Published needs social',
            'language' => 'en',
        ]);
        $publishedMissingLanguages = ContentAsset::factory()->forBrand($brand)->published()->create([
            'title' => 'Published needs translation',
            'language' => 'en',
        ]);
        $translated = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Dutch translation',
            'status' => 'draft',
            'language' => 'nl',
        ]);
        ContentTranslation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_content_asset_id' => $publishedMissingLanguages->id,
            'translated_content_asset_id' => $translated->id,
            'source_language' => 'en',
            'source_locale' => 'en_US',
            'target_language' => 'nl',
            'target_locale' => 'nl_NL',
            'status' => 'draft',
            'provider' => 'test',
        ]);

        $campaignAsset = ContentAsset::factory()->forBrand($brand)->published()->create(['title' => 'Campaign asset']);
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Active launch',
            'slug' => 'active-launch',
            'status' => 'active',
        ]);
        $campaign->contentAssets()->attach($campaignAsset);

        $channel = PublishingChannel::factory()->forBrand($brand)->create([
            'provider' => 'wordpress',
            'name' => 'Disconnected WordPress',
            'status' => 'active',
        ]);
        $connectorAsset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Connector blocked asset',
            'status' => 'approved',
            'channel_id' => $channel->id,
        ]);

        $profile = $this->linkedInProfile($user, $account, $brand);
        $profile->integrationConnection->update([
            'status' => 'expired',
            'token_expires_at' => now()->subMinute(),
        ]);
        $profile->update(['status' => 'expired']);
        SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $publishedMissingLanguages->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'draft',
            'post_text' => 'Expired token post',
            'language' => 'en',
            'locale' => 'en_US',
            'created_by' => $user->id,
        ]);

        app(RecommendationEngineService::class)->generateDistributionRecommendations($account, $brand);
        $firstCounts = [
            'signals' => IntelligenceSignal::query()->where('account_id', $account->id)->count(),
            'recommendations' => Recommendation::query()->where('account_id', $account->id)->count(),
        ];

        app(RecommendationEngineService::class)->generateDistributionRecommendations($account, $brand);

        $this->assertSame($firstCounts['signals'], IntelligenceSignal::query()->where('account_id', $account->id)->count());
        $this->assertSame($firstCounts['recommendations'], Recommendation::query()->where('account_id', $account->id)->count());

        foreach ([
            'Publish this content to a connected website',
            'Create a LinkedIn post from this article',
            'Translate this content to missing languages',
            'Schedule campaign social distribution',
            'Reconnect connector',
            'Reconnect LinkedIn profile',
        ] as $title) {
            $this->assertDatabaseHas('recommendations', [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'title' => $title,
                'status' => 'new',
            ]);
        }

        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'dedupe_key' => "distribution:approved-not-published:{$approved->id}",
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'dedupe_key' => "distribution:connector-disconnected:{$channel->id}:{$connectorAsset->id}",
        ]);
    }

    public function test_distribution_hub_generates_and_shows_distribution_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Hub recommendation source',
            'status' => 'approved',
        ]);
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Hub launch campaign',
            'slug' => 'hub-launch-campaign',
            'status' => 'active',
        ]);
        $campaign->contentAssets()->attach($asset);

        $this->actingAs($user)
            ->get(route('app.distribution'))
            ->assertOk()
            ->assertSee('Hub recommendation source')
            ->assertSee('Publish this content to a connected website')
            ->assertSee('Schedule campaign social distribution');
    }

    public function test_distribution_mark_reviewed_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Review me']);
        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-account']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Forbidden review']);

        $this->actingAs($user)
            ->post(route('app.distribution.reviewed', $asset))
            ->assertRedirect(route('app.distribution'));

        $this->assertNotEmpty($asset->refresh()->metadata['distribution_reviewed_at'] ?? null);
        $this->assertSame($user->id, $asset->metadata['distribution_reviewed_by']);

        $this->actingAs($user)
            ->post(route('app.distribution.reviewed', $otherAsset))
            ->assertNotFound();
    }

    public function test_distribution_hub_requires_content_module_and_view_permission(): void
    {
        [$billing] = $this->tenantWithRole('billing');
        [$ownerNoContent] = $this->tenantWithRole('owner', 'core_only', 'core-only-account');

        $this->actingAs($billing)
            ->get(route('app.distribution'))
            ->assertForbidden();

        $this->actingAs($ownerNoContent)
            ->get(route('app.distribution'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, ?string $plan = 'scale_monthly', string $slug = 'distribution-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Distribution Brand', 'slug' => "{$slug}-brand"]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($plan) {
            app(SubscriptionService::class)->activatePlan($account, $plan === 'core_only' ? 'starter_monthly' : $plan);

            if ($plan === 'core_only') {
                $contentModuleId = \App\Models\Module::query()->where('key', 'content')->value('id');
                $account->subscriptionModules()->where('module_id', $contentModuleId)->update(['status' => 'canceled']);
            }
        }

        return [$user, $account, $brand];
    }

    private function linkedInProfile(User $user, Account $account, Brand $brand): SocialProfile
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $user,
            integration: 'linkedin',
            name: 'Distribution LinkedIn',
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'linkedin-token',
            providerAccountId: 'linkedin-distribution',
        );

        return app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $user,
            provider: 'linkedin',
            displayName: 'Distribution LinkedIn',
            type: 'person',
            providerProfileId: 'linkedin-distribution',
            account: $account,
            brand: $brand,
        );
    }
}
