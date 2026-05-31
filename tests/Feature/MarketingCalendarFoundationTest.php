<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\MarketingTask;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\MarketingCalendarService;
use App\Services\MarketingTaskService;
use App\Services\PublishingService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketingCalendarFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_social_posts_appear_on_calendar(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->context();
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
        ]);
        $post = $this->socialPost($account, $brand, $profile, $user);

        app(SocialPublishingService::class)->schedule($post, $user, '2026-06-15 10:00:00');

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => $post->getMorphClass(),
            'related_id' => $post->id,
            'type' => 'social_post',
            'status' => 'scheduled',
        ]);
    }

    public function test_content_assets_with_scheduled_publishing_appear_on_calendar(): void
    {
        Queue::fake();
        [$owner, $user, $account, $brand] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        $asset = $this->asset($account, $brand);

        $action = app(PublishingService::class)->request($asset, $user, [
            'action' => 'schedule',
            'scheduled_at' => '2026-06-16 12:00:00',
        ]);

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => $action->getMorphClass(),
            'related_id' => $action->id,
            'type' => 'content_asset',
            'status' => 'scheduled',
        ]);
    }

    public function test_campaign_items_appear_on_calendar(): void
    {
        [$owner, , $account, $brand] = $this->context();

        $campaign = app(CampaignService::class)->create($account, $brand, [
            'name' => 'Summer launch',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-30',
        ]);

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'related_type' => $campaign->getMorphClass(),
            'related_id' => $campaign->id,
            'type' => 'campaign_task',
            'status' => 'planned',
        ]);
    }

    public function test_approval_items_appear_on_calendar(): void
    {
        [$owner, $user, $account, $brand] = $this->context();
        $asset = $this->asset($account, $brand);

        $approval = Approval::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'requested_by' => $user->id,
            'status' => 'pending',
            'requested_at' => '2026-06-18 09:00:00',
        ]);

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => $approval->getMorphClass(),
            'related_id' => $approval->id,
            'type' => 'approval',
            'status' => 'in_progress',
        ]);
    }

    public function test_calendar_is_tenant_and_brand_safe(): void
    {
        [, , $account, $brand] = $this->context();
        [, , $otherAccount, $otherBrand] = $this->context('other');

        app(CampaignService::class)->create($account, $brand, [
            'name' => 'Visible campaign',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => '2026-06-20',
        ]);
        app(CampaignService::class)->create($otherAccount, $otherBrand, [
            'name' => 'Hidden campaign',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => '2026-06-20',
        ]);

        $items = app(MarketingCalendarService::class)->paginatedForTenant($account, $brand, [
            'starts' => '2026-06-01',
            'ends' => '2026-06-30',
        ]);

        $this->assertSame(['Visible campaign'], collect($items->items())->pluck('title')->all());
    }

    public function test_calendar_ui_shows_monthly_weekly_and_list_views(): void
    {
        [$owner, $user, $account, $brand] = $this->context(withRole: true);
        app(CampaignService::class)->create($account, $brand, [
            'name' => 'Calendar campaign',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('app.calendar', ['mode' => 'month']))
            ->assertOk()
            ->assertSee('Monthly view')
            ->assertSee('Calendar campaign');

        $this->actingAs($user)
            ->get(route('app.calendar', ['mode' => 'week']))
            ->assertOk()
            ->assertSee('Weekly view');

        $this->actingAs($user)
            ->get(route('app.calendar', ['mode' => 'list']))
            ->assertOk()
            ->assertSee('List view')
            ->assertSee('Calendar campaign');
    }

    public function test_calendar_filters_by_brand_campaign_type_and_assignee(): void
    {
        [$owner, $user, $account, $brand] = $this->context(withRole: true);
        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Calendar Brand',
            'slug' => 'other-calendar-brand',
            'enabled_content_languages' => ['en'],
            'default_content_language' => 'en',
        ]);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $campaign = app(CampaignService::class)->create($account, $brand, [
            'name' => 'Filtered campaign',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => '2026-06-20',
        ]);
        app(CampaignService::class)->create($account, $otherBrand, [
            'name' => 'Other brand campaign',
            'status' => 'planned',
            'campaign_type' => 'social',
            'start_date' => '2026-06-20',
        ]);
        app(MarketingTaskService::class)->create($account, $brand, $user, [
            'scope' => 'brand',
            'campaign_id' => $campaign->id,
            'title' => 'Assigned calendar task',
            'status' => 'todo',
            'priority' => 'high',
            'assigned_to' => $user->id,
            'due_at' => '2026-06-21 11:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('app.calendar', [
                'mode' => 'list',
                'brand_id' => $brand->id,
                'campaign_id' => $campaign->id,
                'type' => 'marketing_task',
                'assigned_to' => $user->id,
                'starts' => '2026-06-01',
                'ends' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('Assigned calendar task')
            ->assertDontSee('Other brand campaign');
    }

    public function test_calendar_detail_opens_and_calendar_can_create_task(): void
    {
        [$owner, $user, $account, $brand] = $this->context(withRole: true);
        $task = app(MarketingTaskService::class)->create($account, $brand, $user, [
            'scope' => 'brand',
            'title' => 'Open this calendar task',
            'status' => 'todo',
            'priority' => 'medium',
            'assigned_to' => $user->id,
            'due_at' => '2026-06-22 13:00:00',
        ]);
        $item = \App\Models\MarketingCalendarItem::query()
            ->where('related_type', $task->getMorphClass())
            ->where('related_id', $task->id)
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('app.calendar.show', $item))
            ->assertOk()
            ->assertSee('Open this calendar task')
            ->assertSee('Calendar detail');

        $this->actingAs($user)
            ->post(route('app.calendar.tasks.store'), [
                'scope' => 'brand',
                'title' => 'Created from calendar',
                'status' => 'todo',
                'priority' => 'urgent',
                'assigned_to' => $user->id,
                'due_at' => '2026-06-23 10:00:00',
            ])
            ->assertRedirect();

        $created = MarketingTask::query()->where('title', 'Created from calendar')->firstOrFail();
        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => $created->getMorphClass(),
            'related_id' => $created->id,
            'type' => 'marketing_task',
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Account, 3: Brand, 4: SocialProfile|null}
     */
    private function context(string $slug = 'onygo', bool $withRole = false): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        if ($withRole) {
            $this->seed(RolesAndPermissionsSeeder::class);
            $this->seed(SubscriptionCatalogSeeder::class);
        }

        $owner = User::factory()->create(['name' => 'Ricardo']);
        $user = User::factory()->create(['name' => 'Editor']);
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

        if ($withRole) {
            $role = Role::query()->where('name', 'owner')->firstOrFail();
            $user->roles()->attach($role, ['account_id' => $account->id]);
            app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');
        }

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Ricardo LinkedIn',
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

    private function socialPost(Account $account, Brand $brand, SocialProfile $profile, User $user): SocialPost
    {
        return SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => $profile->provider,
            'status' => 'draft',
            'post_text' => 'Calendar social copy',
            'language' => 'en',
            'locale' => 'en_US',
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
            'title' => 'Scheduled asset',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'body' => 'Asset body',
        ]);
    }
}
