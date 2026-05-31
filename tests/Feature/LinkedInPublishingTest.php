<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditBalance;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\SocialMediaAsset;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\CreditService;
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

class LinkedInPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_personal_profile_publish_sends_ugc_payload_and_marks_published(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->socialPost($account, $brand, $profile, $publisher, [
            'metadata' => ['url' => 'https://example.test/article'],
        ]);

        Http::fake([
            'https://api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:12345'], 201),
        ]);

        $queued = app(SocialPublishingService::class)->queue($post, $publisher);
        $published = app(SocialPublishingService::class)->process($queued);

        $this->assertSame('published', $published->status);
        $this->assertSame('urn:li:share:12345', $published->external_id);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:12345', $published->external_url);
        $this->assertSame(45, CreditBalance::query()->where('account_id', $account->id)->value('balance'));
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostPublished',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'social_post.published',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.linkedin.com/v2/ugcPosts'
                && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
                && $payload['author'] === 'urn:li:person:linkedin-person-123'
                && $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'] === 'LinkedIn post copy.'
                && $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] === 'ARTICLE'
                && $payload['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['originalUrl'] === 'https://example.test/article';
        });
    }

    public function test_missing_publish_permission_blocks_before_linkedin_call(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => false,
        ]);

        Http::fake();

        try {
            app(SocialPublishingService::class)->queue($this->socialPost($account, $brand, $profile, $publisher), $publisher);
            $this->fail('Publishing without can_publish should have failed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        Http::assertNothingSent();
    }

    public function test_organization_profile_cannot_publish_without_organization_scope(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context();
        $profile->update([
            'type' => 'organization',
            'provider_profile_id' => '987654',
            'metadata' => [
                'organization_urn' => 'urn:li:organization:987654',
                'roles' => ['ADMINISTRATOR'],
                'capabilities' => ['publish' => true],
            ],
        ]);
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);

        Http::fake();

        try {
            app(SocialPublishingService::class)->queue($this->socialPost($account, $brand, $profile, $publisher), $publisher);
            $this->fail('Organization publishing without w_organization_social should have failed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('LinkedIn organization publishing requires approved w_organization_social scope and page publishing role.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_expired_token_blocks_publish_and_records_reconnect_signal(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context(connectionOverrides: [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);

        try {
            app(SocialPublishingService::class)->queue($this->socialPost($account, $brand, $profile, $publisher), $publisher);
            $this->fail('Publishing with an expired LinkedIn token should have failed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Reconnect LinkedIn profile before publishing.', $exception->getMessage());
        }

        $this->assertDatabaseHas('intelligence_signals', [
            'title' => 'Reconnect LinkedIn profile',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_linkedin_api_error_marks_post_failed_and_creates_signal(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->socialPost($account, $brand, $profile, $publisher);

        Http::fake([
            'https://api.linkedin.com/v2/ugcPosts' => Http::response(['message' => 'Not enough permissions'], 403),
        ]);

        $queued = app(SocialPublishingService::class)->queue($post, $publisher);
        $failed = app(SocialPublishingService::class)->process($queued);

        $this->assertSame('failed', $failed->status);
        $this->assertSame('Not enough permissions', $failed->error_message);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostFailed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'title' => 'LinkedIn post failed to publish',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_linkedin_media_asset_fails_cleanly_until_upload_is_implemented(): void
    {
        Queue::fake();
        [$owner, $publisher, $account, $brand, $profile] = $this->context();
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        app(SocialProfileService::class)->shareWithBrand($profile, $brand, $owner, [
            'view' => true,
            'prepare' => true,
            'schedule' => true,
            'publish' => true,
        ]);
        $post = $this->socialPost($account, $brand, $profile, $publisher);
        $asset = SocialMediaAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_post_id' => $post->id,
            'provider' => 'linkedin',
            'type' => 'image',
            'status' => 'draft',
            'file_path' => 'social/linkedin/example.png',
            'mime_type' => 'image/png',
            'size_bytes' => 12345,
        ]);
        $post->update(['media' => [['social_media_asset_id' => $asset->id]]]);

        Http::fake();

        $queued = app(SocialPublishingService::class)->queue($post->fresh(), $publisher);
        $failed = app(SocialPublishingService::class)->process($queued);

        $this->assertSame('failed', $failed->status);
        $this->assertSame('media upload not implemented yet', $failed->error_message);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SocialPostFailed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_cross_tenant_profile_is_not_published(): void
    {
        [$owner, $publisher, $account, $brand] = $this->tenant('visible');
        [, , $otherAccount, $otherBrand, $otherProfile] = $this->context(slug: 'hidden');
        app(CreditService::class)->grant($account, 50, $owner, 'Publish test');
        $publisher->accounts()->attach($otherAccount, ['status' => 'active']);
        $publisher->brands()->attach($otherBrand, ['account_id' => $otherAccount->id, 'status' => 'active']);
        $otherProfile->update(['owner_user_id' => $publisher->id]);

        $post = $this->socialPost($account, $brand, $otherProfile, $publisher, ['status' => 'queued']);

        Http::fake();

        $failed = app(SocialPublishingService::class)->process($post);

        $this->assertSame('failed', $failed->status);
        $this->assertSame('LinkedIn profile does not belong to the social post account.', $failed->error_message);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $connectionOverrides
     * @return array{0: User, 1: User, 2: Account, 3: Brand, 4: SocialProfile}
     */
    private function context(string $slug = 'alpha', array $connectionOverrides = []): array
    {
        [$owner, $publisher, $account, $brand] = $this->tenant($slug);
        $integration = Integration::query()->where('key', 'linkedin')->firstOrFail();
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $owner->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Maria LinkedIn',
            'status' => $connectionOverrides['status'] ?? 'active',
            'provider_account_id' => 'linkedin-person-123',
            'provider_account_name' => 'Maria LinkedIn',
            'scopes' => $connectionOverrides['scopes'] ?? ['openid', 'profile', 'email', 'w_member_social'],
            'access_token' => $connectionOverrides['access_token'] ?? 'linkedin-access-token',
            'refresh_token' => $connectionOverrides['refresh_token'] ?? 'linkedin-refresh-token',
            'token_expires_at' => $connectionOverrides['token_expires_at'] ?? now()->addHour(),
            'refresh_expires_at' => $connectionOverrides['refresh_expires_at'] ?? now()->addDay(),
            'metadata' => ['provider' => 'linkedin', 'api_calls_enabled' => true],
        ]);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $owner->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'linkedin-person-123',
            'display_name' => 'Maria LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);

        return [$owner, $publisher, $account, $brand, $profile];
    }

    /**
     * @return array{0: User, 1: User, 2: Account, 3: Brand}
     */
    private function tenant(string $slug): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $owner = User::factory()->create(['name' => "Owner {$slug}"]);
        $publisher = User::factory()->create(['name' => "Publisher {$slug}"]);
        $account = Account::query()->create(['name' => "Account {$slug}", 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => "Brand {$slug}",
            'slug' => fake()->unique()->slug(),
            'enabled_content_languages' => ['en'],
            'default_content_language' => 'en',
        ]);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        foreach ([$owner, $publisher] as $user) {
            $user->accounts()->attach($account, ['status' => 'active']);
            $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
            $user->roles()->attach($role, ['account_id' => $account->id]);
        }

        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$owner, $publisher, $account, $brand];
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
            'provider' => 'linkedin',
            'status' => $overrides['status'] ?? 'draft',
            'post_text' => $overrides['post_text'] ?? 'LinkedIn post copy.',
            'metadata' => $overrides['metadata'] ?? null,
            'language' => 'en',
            'created_by' => $user->id,
        ]);
    }
}
