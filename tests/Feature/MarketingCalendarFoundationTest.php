<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\MarketingCalendarService;
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

    public function test_calendar_ui_shows_monthly_and_weekly_placeholder(): void
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
            ->assertSee('Monthly calendar placeholder')
            ->assertSee('Calendar campaign');

        $this->actingAs($user)
            ->get(route('app.calendar', ['mode' => 'week']))
            ->assertOk()
            ->assertSee('Weekly calendar placeholder');
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
