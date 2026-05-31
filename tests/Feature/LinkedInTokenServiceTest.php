<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Integrations\LinkedIn\LinkedInTokenService;
use App\Services\SocialPublishing\SocialPublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class LinkedInTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_if_possible_updates_encrypted_tokens_and_expiry(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection, $profile] = $this->linkedinConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_expires_at' => now()->addDay(),
            'refresh_token' => 'old-refresh-token',
        ]);
        $profile->update(['status' => 'expired']);

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7200,
                'scope' => 'openid profile email w_member_social',
            ]),
        ]);

        $refreshed = app(LinkedInTokenService::class)->refreshIfPossible($connection);
        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame('active', $refreshed->status);
        $this->assertSame('new-access-token', $refreshed->access_token);
        $this->assertNotSame('new-access-token', $raw->access_token);
        $this->assertSame('new-refresh-token', $refreshed->refresh_token);
        $this->assertNotNull($refreshed->token_expires_at);
        $this->assertNotNull($refreshed->refresh_expires_at);
        $this->assertSame('connected', $profile->fresh()->status);
    }

    public function test_missing_refresh_token_marks_connection_and_profile_expired_and_records_signal(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection, $profile] = $this->linkedinConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        $expired = app(LinkedInTokenService::class)->refreshIfPossible($connection);

        $this->assertSame('expired', $expired->status);
        $this->assertSame('expired', $profile->fresh()->status);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Reconnect LinkedIn profile',
            'dedupe_key' => "linkedin-reconnect:{$connection->id}",
        ]);
    }

    public function test_failed_refresh_marks_connection_expired(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->linkedinConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_expires_at' => now()->addDay(),
            'refresh_token' => 'refresh-token',
        ]);

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $expired = app(LinkedInTokenService::class)->refreshIfPossible($connection);

        $this->assertSame('expired', $expired->status);
        $this->assertSame('LinkedIn token refresh failed.', $expired->metadata['token_error_message']);
    }

    public function test_expired_linkedin_token_blocks_social_publishing(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [, $profile] = $this->linkedinConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        $post = SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'approved',
            'post_text' => 'Token health should block this.',
            'language' => 'en',
            'created_by' => $user->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reconnect LinkedIn profile before publishing.');

        app(SocialPublishingService::class)->queue($post, $user);
    }

    public function test_token_health_command_marks_due_tokens(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->linkedinConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        $this->artisan('linkedin:check-token-health')
            ->expectsOutput('Checked 1 LinkedIn connection(s); processed 1 token health update(s).')
            ->assertSuccessful();

        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "linkedin-reconnect:{$connection->id}")->count());
    }

    private function configureLinkedIn(): void
    {
        config([
            'integrations.providers.linkedin.oauth.client_id' => 'client-id',
            'integrations.providers.linkedin.oauth.client_secret' => 'client-secret',
            'integrations.providers.linkedin.oauth.redirect_uri' => 'https://app.test/settings/integrations/linkedin/callback',
            'integrations.providers.linkedin.oauth.enabled' => true,
        ]);

        $this->seed(IntegrationCatalogSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: IntegrationConnection, 1: SocialProfile}
     */
    private function linkedinConnection(User $user, Account $account, Brand $brand, array $overrides = []): array
    {
        $integration = Integration::query()->where('key', 'linkedin')->firstOrFail();
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Maria LinkedIn',
            'status' => $overrides['status'] ?? 'active',
            'provider_account_id' => 'linkedin-person-123',
            'provider_account_name' => 'Maria LinkedIn',
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            'access_token' => $overrides['access_token'] ?? 'access-token',
            'refresh_token' => $overrides['refresh_token'] ?? 'refresh-token',
            'token_expires_at' => $overrides['token_expires_at'] ?? now()->addHour(),
            'refresh_expires_at' => $overrides['refresh_expires_at'] ?? null,
            'metadata' => ['provider' => 'linkedin'],
        ]);

        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'linkedin-person-123',
            'display_name' => 'Maria LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);

        return [$connection, $profile];
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
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

        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$user, $account, $brand];
    }
}
