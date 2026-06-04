<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentTask;
use App\Models\Audience;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\MarketingObjective;
use App\Models\Newsletter;
use App\Models\NewsletterSend;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CreditService;
use App\Services\RecommendationActionService;
use App\Services\RecommendationEngineService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MarketingOsRecommendationConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_os_rules_generate_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $asset = $this->asset($account, $brand);
        $post = $this->socialPost($account, $brand, $user);
        $objective = MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Increase visibility',
            'type' => 'visibility',
            'status' => 'active',
        ]);

        $recommendations = app(RecommendationEngineService::class)->generateMarketingOsRecommendations($account, $brand);

        $this->assertContains('Create campaign task plan', $recommendations->pluck('title')->all());
        $this->assertContains('Create campaign briefing', $recommendations->pluck('title')->all());
        $this->assertContains('Attach content to campaign', $recommendations->pluck('title')->all());
        $this->assertContains('Attach social post to campaign', $recommendations->pluck('title')->all());
        $this->assertContains('Create actions for this objective', $recommendations->pluck('title')->all());

        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create campaign task plan',
            'action_type' => 'create_campaign_task_plan',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create actions for this objective',
            'action_type' => 'create_objective_actions',
        ]);
    }

    public function test_newsletter_rules_generate_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $campaign->contentAssets()->attach([
            $this->publishedAsset($account, $brand, 'Published asset one')->id,
            $this->publishedAsset($account, $brand, 'Published asset two')->id,
        ]);
        $draft = $this->newsletter($account, $brand, 'Draft digest', 'draft');
        $approved = $this->newsletter($account, $brand, 'Approved digest', 'approved');
        $audience = $this->audience($account, $brand);

        $recommendations = app(RecommendationEngineService::class)->generateMarketingOsRecommendations($account, $brand);

        $this->assertContains('Create a newsletter digest', $recommendations->pluck('title')->all());
        $this->assertContains('Submit newsletter for approval', $recommendations->pluck('title')->all());
        $this->assertContains('Schedule newsletter', $recommendations->pluck('title')->all());
        $this->assertContains('Create newsletter for this audience', $recommendations->pluck('title')->all());

        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create a newsletter digest',
            'action_type' => 'create_newsletter_digest',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Submit newsletter for approval',
            'action_type' => 'submit_newsletter_for_approval',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Schedule newsletter',
            'action_type' => 'schedule_newsletter',
        ]);
        $this->assertDatabaseHas('recommendations', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create newsletter for this audience',
            'action_type' => 'create_audience_newsletter',
        ]);

        NewsletterSend::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'newsletter_id' => $approved->id,
            'audience_id' => $audience->id,
            'status' => 'sent',
            'total_recipients' => 1,
            'sent_count' => 1,
            'completed_at' => now(),
        ]);

        $secondPass = app(RecommendationEngineService::class)->generateMarketingOsRecommendations($account, $brand);
        $this->assertFalse($secondPass->contains(fn (Recommendation $recommendation) => $recommendation->title === 'Create newsletter for this audience' && ($recommendation->action_payload['audience_id'] ?? null) === $audience->id));

        $this->assertNotNull($user);
        $this->assertSame('Draft digest', $draft->title);
    }

    public function test_accepting_marketing_os_recommendations_creates_tasks_briefings_agent_tasks_and_events(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $this->asset($account, $brand);
        $this->socialPost($account, $brand, $user);
        $objective = MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'name' => 'Create demand',
            'type' => 'leads',
            'status' => 'active',
        ]);

        app(RecommendationEngineService::class)->generateMarketingOsRecommendations($account, $brand);

        $taskPlan = Recommendation::query()->where('title', 'Create campaign task plan')->firstOrFail();
        $briefingRecommendation = Recommendation::query()->where('title', 'Create campaign briefing')->firstOrFail();
        $objectiveRecommendation = Recommendation::query()->where('title', 'Create actions for this objective')->firstOrFail();
        $contentRecommendation = Recommendation::query()->where('title', 'Attach content to campaign')->firstOrFail();
        $socialRecommendation = Recommendation::query()->where('title', 'Attach social post to campaign')->firstOrFail();

        foreach ([$taskPlan, $briefingRecommendation, $objectiveRecommendation, $contentRecommendation, $socialRecommendation] as $recommendation) {
            app(RecommendationActionService::class)->accept($recommendation, $user);
        }

        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'related_type' => Recommendation::class,
            'related_id' => $taskPlan->id,
            'title' => 'Build campaign task plan',
        ]);
        $this->assertDatabaseHas('briefings', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => "{$campaign->name} briefing",
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'marketing_objective_id' => $objective->id,
            'related_id' => $objectiveRecommendation->id,
            'title' => 'Create objective action plan',
        ]);
        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_id' => $contentRecommendation->id,
            'title' => 'Attach content asset to campaign',
        ]);
        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_id' => $socialRecommendation->id,
            'title' => 'Attach social post to campaign',
        ]);
        $this->assertSame(5, AgentTask::query()->where('account_id', $account->id)->count());
        $this->assertDatabaseHas('domain_events', ['event_type' => 'MarketingTaskCreatedFromRecommendation']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'BriefingDraftCreatedFromRecommendation']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'AgentTaskPlanned']);
    }

    public function test_accepting_newsletter_recommendations_creates_drafts_tasks_and_events(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $firstAsset = $this->publishedAsset($account, $brand, 'Digest asset one');
        $secondAsset = $this->publishedAsset($account, $brand, 'Digest asset two');
        $campaign->contentAssets()->attach([$firstAsset->id, $secondAsset->id]);
        $draft = $this->newsletter($account, $brand, 'Newsletter needs approval', 'draft');
        $approved = $this->newsletter($account, $brand, 'Newsletter needs schedule', 'approved');
        $audience = $this->audience($account, $brand);

        app(RecommendationEngineService::class)->generateMarketingOsRecommendations($account, $brand);

        $digest = Recommendation::query()->where('title', 'Create a newsletter digest')->firstOrFail();
        $approval = Recommendation::query()->where('title', 'Submit newsletter for approval')->where('action_payload->newsletter_id', $draft->id)->firstOrFail();
        $schedule = Recommendation::query()->where('title', 'Schedule newsletter')->where('action_payload->newsletter_id', $approved->id)->firstOrFail();
        $audienceRecommendation = Recommendation::query()->where('title', 'Create newsletter for this audience')->where('action_payload->audience_id', $audience->id)->firstOrFail();

        foreach ([$digest, $approval, $schedule, $audienceRecommendation] as $recommendation) {
            app(RecommendationActionService::class)->accept($recommendation, $user);
        }

        $this->assertDatabaseHas('newsletters', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => "{$campaign->name} newsletter digest",
            'status' => 'draft',
        ]);
        $createdDigest = Newsletter::query()->where('campaign_id', $campaign->id)->where('title', "{$campaign->name} newsletter digest")->firstOrFail();
        $this->assertSame(2, $createdDigest->sections()->where('type', 'content_asset')->count());
        $this->assertDatabaseHas('newsletters', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => "{$audience->name} newsletter",
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_id' => $approval->id,
            'title' => 'Submit newsletter for approval',
        ]);
        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_id' => $schedule->id,
            'title' => 'Schedule newsletter',
        ]);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'NewsletterDraftCreatedFromRecommendation']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'MarketingTaskCreatedFromRecommendation']);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'AgentTaskPlanned']);
    }

    public function test_marketing_os_recommendation_actions_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'other-marketing-os');
        $otherCampaign = $this->campaign($otherAccount, $otherBrand);

        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create campaign task plan',
            'summary' => 'Unsafe campaign.',
            'recommended_action' => 'Create task plan.',
            'action_type' => 'create_campaign_task_plan',
            'action_payload' => ['campaign_id' => $otherCampaign->id],
            'status' => 'new',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(RecommendationActionService::class)->accept($recommendation, $user);
    }

    public function test_newsletter_recommendation_actions_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'other-newsletter-recommendations');
        $otherAudience = $this->audience($otherAccount, $otherBrand);

        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create newsletter for this audience',
            'summary' => 'Unsafe audience.',
            'recommended_action' => 'Create newsletter.',
            'action_type' => 'create_audience_newsletter',
            'action_payload' => ['audience_id' => $otherAudience->id],
            'status' => 'new',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(RecommendationActionService::class)->accept($recommendation, $user);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $slug = 'marketing-os-recommendations'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en', 'nl'],
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');
        app(CreditService::class)->grant($account, 5000, $user, 'Test LLM credits');

        return [$user, $account, $brand];
    }

    private function campaign(Account $account, Brand $brand): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Recommendation Campaign',
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
            'objective' => 'Grow pipeline',
            'metadata' => ['campaign_type' => 'content'],
        ]);
    }

    private function asset(Account $account, Brand $brand): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'approved',
            'title' => 'Approved campaign asset',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
        ]);
    }

    private function publishedAsset(Account $account, Brand $brand, string $title): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'published',
            'title' => $title,
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'excerpt' => "{$title} excerpt",
            'published_at' => now()->subDay(),
        ]);
    }

    private function newsletter(Account $account, Brand $brand, string $title, string $status): Newsletter
    {
        return Newsletter::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => $title,
            'subject' => $title,
            'language' => 'en',
            'status' => $status,
        ]);
    }

    private function audience(Account $account, Brand $brand): Audience
    {
        return Audience::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Recommendation audience',
            'status' => 'active',
        ]);
    }

    private function socialPost(Account $account, Brand $brand, User $user): SocialPost
    {
        $integration = Integration::query()->firstOrCreate(
            ['key' => 'linkedin'],
            ['name' => 'LinkedIn', 'auth_type' => 'oauth', 'default_scopes' => [], 'is_active' => true, 'is_system' => true],
        );
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn',
            'status' => 'connected',
            'provider_account_id' => fake()->unique()->uuid(),
            'access_token' => 'token',
        ]);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => fake()->unique()->uuid(),
            'display_name' => 'LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);

        return SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'scheduled',
            'post_text' => 'Scheduled social post without campaign',
            'language' => 'en',
            'locale' => 'en_US',
            'scheduled_at' => now()->addDay(),
            'created_by' => $user->id,
        ]);
    }
}
