<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditBalance;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\SocialProfiles\SocialProfileService;
use App\Services\SocialPublishing\SocialPublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class SocialPublishingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_permission_allows_draft_without_publish_access(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
        ]);

        $post = app(SocialPublishingService::class)->prepare($account, $brand, $user, [
            'social_profile_id' => $profile->id,
            'post_text' => 'Prepared copy only.',
            'language' => 'en',
        ]);

        $this->assertSame('draft', $post->status);
        $this->assertSame($brand->id, $post->brand_id);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostPrepared',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->expectException(InvalidArgumentException::class);

        app(SocialPublishingService::class)->queue($post, $user);
    }

    public function test_publish_is_blocked_without_publish_permission(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => false,
        ]);
        $post = $this->makePost($account, $brand, $profile, $user);

        $this->expectException(InvalidArgumentException::class);

        app(SocialPublishingService::class)->queue($post, $user);
    }

    public function test_schedule_is_blocked_without_schedule_permission(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => false,
            'publish' => true,
        ]);
        $post = $this->makePost($account, $brand, $profile, $user);

        $this->expectException(InvalidArgumentException::class);

        app(SocialPublishingService::class)->schedule($post, $user, now()->addDay());
    }

    public function test_publish_uses_fake_provider_deducts_credits_events_and_signal(): void
    {
        Queue::fake();
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        app(CreditService::class)->grant($account, 50, $owner, 'Test grant');
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->makePost($account, $brand, $profile, $user);

        $queued = app(SocialPublishingService::class)->queue($post, $user);

        $this->assertSame('queued', $queued->status);
        $this->assertSame(45, CreditBalance::query()->where('account_id', $account->id)->value('balance'));

        Http::fake([
            'https://api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:foundation-post'], 201),
        ]);

        $published = app(SocialPublishingService::class)->process($queued);

        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->external_id);
        $this->assertNotNull($published->external_url);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostPublished',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'source' => 'domain_event',
            'type' => 'publishing_completed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_tenant_isolation_blocks_unshared_account(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        $otherAccount = Account::query()->create(['name' => 'Sana Medical', 'slug' => 'sana-medical']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Sana', 'slug' => 'sana', 'enabled_content_languages' => ['en']]);
        $otherUser = User::factory()->create();
        $otherUser->accounts()->attach($otherAccount, ['status' => 'active']);
        $otherUser->brands()->attach($otherBrand, ['account_id' => $otherAccount->id, 'status' => 'active']);
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);

        $this->assertCount(0, app(SocialPublishingService::class)->paginatedForTenant($otherAccount, $otherBrand)->items());
        $this->expectException(InvalidArgumentException::class);

        app(SocialPublishingService::class)->prepare($otherAccount, $otherBrand, $otherUser, [
            'social_profile_id' => $profile->id,
            'post_text' => 'Blocked tenant copy.',
            'language' => 'en',
        ]);
    }

    public function test_language_must_be_enabled_for_brand(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser();
        $brand->update(['enabled_content_languages' => ['en'], 'default_content_language' => 'en']);
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(SocialPublishingService::class)->prepare($account, $brand, $user, [
            'social_profile_id' => $profile->id,
            'post_text' => 'Niet toegestaan.',
            'language' => 'nl',
        ]);
    }

    public function test_social_posts_index_and_detail_show_publishing_history(): void
    {
        [$owner, $user, $account, $brand, $profile] = $this->socialProfileWithUser(withRole: true);
        app(SocialProfileService::class)->shareWithAccount($profile, $account, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->makePost($account, $brand, $profile, $user, ['post_text' => 'History item']);

        $this->actingAs($user)
            ->get(route('app.social-posts.index'))
            ->assertOk()
            ->assertSee('Social posts')
            ->assertSee('History item');

        $this->actingAs($user)
            ->get(route('app.social-posts.show', $post))
            ->assertOk()
            ->assertSee('Social post detail')
            ->assertSee('History item');
    }

    /**
     * @return array{0: User, 1: User, 2: Account, 3: Brand, 4: SocialProfile}
     */
    private function socialProfileWithUser(bool $withRole = false): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        if ($withRole) {
            $this->seed(RolesAndPermissionsSeeder::class);
            $this->seed(SubscriptionCatalogSeeder::class);
        }

        $owner = User::factory()->create(['name' => 'Ricardo']);
        $user = User::factory()->create(['name' => 'Publisher']);
        $account = Account::query()->create(['name' => 'Onygo', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Argusly',
            'slug' => fake()->unique()->slug(),
            'market' => 'Netherlands',
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
            scopes: ['openid', 'profile', 'email', 'w_member_social'],
            accessToken: 'linkedin-token',
            providerAccountId: 'linkedin-ricardo',
        );

        $profile = app(SocialProfileService::class)->createFromIntegrationConnection(
            connection: $connection,
            owner: $owner,
            provider: 'linkedin',
            displayName: 'Ricardo LinkedIn',
            type: 'person',
            providerProfileId: 'linkedin-ricardo',
        );

        return [$owner, $user, $account, $brand, $profile];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePost(Account $account, Brand $brand, SocialProfile $profile, User $user, array $overrides = []): SocialPost
    {
        return SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $overrides['content_asset_id'] ?? null,
            'social_profile_id' => $profile->id,
            'provider' => $profile->provider,
            'status' => $overrides['status'] ?? 'draft',
            'post_text' => $overrides['post_text'] ?? 'Draft social copy.',
            'metadata' => $overrides['metadata'] ?? null,
            'language' => $overrides['language'] ?? 'en',
            'locale' => 'en_US',
            'market' => $brand->market,
            'created_by' => $user->id,
        ]);
    }
}
