<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\CreditBalance;
use App\Models\IntelligenceSignal;
use App\Models\Recommendation;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPublishingIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_social_post_projects_signal_reconnect_recommendation_and_activity_idempotently(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->context();
        $post = $this->socialPost($account, $brand, $profile, $user);

        app(SocialPublishingService::class)->fail($post, 'LinkedIn token expired.');
        app(SocialPublishingService::class)->fail($post->fresh(), 'LinkedIn token expired.');

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostFailed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'domain.social.post.failed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "social-post-failed:{$post->id}",
            'type' => 'publishing_failed',
            'category' => 'social',
            'title' => 'LinkedIn post failed to publish',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Reconnect LinkedIn profile',
        ]);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "social-post-failed:{$post->id}")->count());
        $this->assertSame(1, Recommendation::query()->where('account_id', $account->id)->where('title', 'Reconnect LinkedIn profile')->count());
    }

    public function test_published_and_scheduled_social_posts_project_domain_event_signals(): void
    {
        Queue::fake();
        [$owner, $user, $account, $brand, $profile] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->socialPost($account, $brand, $profile, $user);

        app(SocialPublishingService::class)->schedule($post, $user, now()->addDay());
        app(SocialPublishingService::class)->queue($post->fresh(), $user);
        Http::fake([
            'https://api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:intelligence-post'], 201),
        ]);
        app(SocialPublishingService::class)->process($post->fresh());

        $this->assertSame(45, CreditBalance::query()->where('account_id', $account->id)->value('balance'));
        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "social-post-scheduled:{$post->id}",
            'title' => 'Social post scheduled',
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "social-post-published:{$post->id}",
            'type' => 'publishing_completed',
            'category' => 'social',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Monitor social engagement',
        ]);
    }

    public function test_overdue_social_post_projects_signal(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->context();
        $post = $this->socialPost($account, $brand, $profile, $user, [
            'status' => 'scheduled',
            'scheduled_at' => now()->subDay(),
        ]);

        app(SocialPublishingService::class)->markOverdue($post);

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostOverdue',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "social-post-overdue:{$post->id}",
            'title' => 'Scheduled social post is overdue',
        ]);
    }

    public function test_content_asset_without_social_distribution_recommends_linkedin_post(): void
    {
        [$owner, , $account, $brand] = $this->context();
        $asset = $this->asset($account, $brand);

        $this->assertTrue(app(SocialPublishingService::class)->flagContentAssetWithoutSocialDistribution($asset, $owner));
        $this->assertTrue(app(SocialPublishingService::class)->flagContentAssetWithoutSocialDistribution($asset, $owner));

        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "content-asset-missing-social:{$asset->id}",
            'title' => 'Content asset has no social distribution',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create LinkedIn post',
        ]);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "content-asset-missing-social:{$asset->id}")->count());
    }

    public function test_campaign_with_content_but_no_scheduled_social_posts_recommends_distribution(): void
    {
        [$owner, , $account, $brand] = $this->context();
        $asset = $this->asset($account, $brand);
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Launch campaign',
            'slug' => 'launch-campaign',
            'status' => 'planned',
        ]);
        $campaign->contentAssets()->attach($asset);

        $this->assertTrue(app(SocialPublishingService::class)->flagCampaignWithoutScheduledSocialPosts($campaign, $owner));

        $this->assertDatabaseHas('intelligence_signals', [
            'dedupe_key' => "campaign-missing-social-schedule:{$campaign->id}",
            'title' => 'Campaign has content but no scheduled social posts',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Schedule social distribution',
        ]);
    }

    public function test_social_intelligence_projection_is_tenant_safe(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->context();
        [, , $otherAccount, $otherBrand] = $this->context('other');
        $post = $this->socialPost($account, $brand, $profile, $user);

        app(SocialPublishingService::class)->fail($post, 'Failure in visible tenant.');

        $this->assertDatabaseMissing('intelligence_signals', [
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'dedupe_key' => "social-post-failed:{$post->id}",
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Account, 3: Brand, 4: SocialProfile}
     */
    private function context(string $slug = 'onygo'): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create(['name' => 'Ricardo']);
        $user = User::factory()->create(['name' => 'Publisher']);
        $account = Account::query()->create(['name' => $slug, 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => "{$slug} Brand",
            'slug' => fake()->unique()->slug(),
            'enabled_content_languages' => ['en', 'nl'],
            'default_content_language' => 'en',
        ]);

        foreach ([$owner, $user] as $member) {
            $member->accounts()->attach($account, ['status' => 'active']);
            $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        }

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Ricardo LinkedIn',
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'token',
            providerAccountId: "linkedin-{$slug}",
        );

        $profile = app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $owner,
            provider: 'linkedin',
            displayName: 'Ricardo LinkedIn',
            type: 'person',
            providerProfileId: "linkedin-{$slug}",
        );

        return [$owner, $user, $account, $brand, $profile];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function socialPost(Account $account, Brand $brand, SocialProfile $profile, User $user, array $overrides = []): SocialPost
    {
        return SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => $profile->provider,
            'status' => $overrides['status'] ?? 'draft',
            'post_text' => $overrides['post_text'] ?? 'Social copy',
            'metadata' => $overrides['metadata'] ?? null,
            'language' => 'en',
            'locale' => 'en_US',
            'scheduled_at' => $overrides['scheduled_at'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    private function asset(Account $account, Brand $brand): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'approved',
            'title' => 'Distribution article',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'body' => 'Useful article body.',
        ]);
    }
}
