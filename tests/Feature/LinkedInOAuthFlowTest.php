<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_generates_authorization_url_and_stores_state(): void
    {
        $this->configureLinkedIn();
        [$user] = $this->tenantWithRole('owner');

        $response = $this->actingAs($user)->get(route('settings.integrations.linkedin.connect'));

        $response->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://app.test/settings/integrations/linkedin/callback', $query['redirect_uri']);
        $this->assertSame('openid profile email w_member_social', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertArrayHasKey($query['state'], session('oauth.linkedin.states'));
    }

    public function test_callback_validates_state_stores_tokens_and_creates_personal_profile(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $connect = $this->actingAs($user)->get(route('settings.integrations.linkedin.connect'));
        parse_str(parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'linkedin-access-token',
                'refresh_token' => 'linkedin-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 86_400,
                'scope' => 'openid profile email w_member_social',
                'token_type' => 'Bearer',
            ]),
            'https://api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-person-123',
                'name' => 'Maria LinkedIn',
                'email' => 'maria@example.test',
                'profile' => 'https://www.linkedin.com/in/maria',
                'picture' => 'https://media.example/maria.jpg',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin.callback', [
                'state' => $query['state'],
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations.linkedin'))
            ->assertSessionHas('linkedin_status', 'LinkedIn profile connected.');

        $connection = IntegrationConnection::query()->where('provider_account_id', 'linkedin-person-123')->firstOrFail();
        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame($account->id, $connection->account_id);
        $this->assertSame($brand->id, $connection->brand_id);
        $this->assertSame('linkedin-access-token', $connection->access_token);
        $this->assertNotSame('linkedin-access-token', $raw->access_token);
        $this->assertSame('linkedin-refresh-token', $connection->refresh_token);
        $this->assertSame(['openid', 'profile', 'email', 'w_member_social'], $connection->scopes);
        $this->assertNotNull($connection->token_expires_at);
        $this->assertNotNull($connection->refresh_expires_at);
        $this->assertSame('linkedin-person-123', $connection->metadata['provider_member_id']);
        $this->assertSame('Maria LinkedIn', $connection->provider_account_name);
        $this->assertSame('https://www.linkedin.com/in/maria', $connection->metadata['profile_url']);
        $this->assertSame('https://media.example/maria.jpg', $connection->metadata['avatar_url']);
        $this->assertSame('linkedin-person-123', $connection->metadata['raw_profile']['sub']);

        $profile = SocialProfile::query()->where('integration_connection_id', $connection->id)->firstOrFail();

        $this->assertSame('linkedin', $profile->provider);
        $this->assertSame('linkedin-person-123', $profile->provider_profile_id);
        $this->assertSame('Maria LinkedIn', $profile->display_name);
        $this->assertSame('https://www.linkedin.com/in/maria', $profile->profile_url);
        $this->assertSame('https://media.example/maria.jpg', $profile->avatar_url);
        $this->assertSame('person', $profile->type);
        $this->assertSame('connected', $profile->status);
        $this->assertSame('linkedin-person-123', $profile->metadata['provider_member_id']);
        $this->assertSame('linkedin-person-123', $profile->metadata['raw_profile']['sub']);
        $this->assertDatabaseHas('social_profile_permissions', [
            'social_profile_id' => $profile->id,
            'user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'can_view' => true,
            'can_prepare' => true,
            'can_schedule' => true,
            'can_publish' => true,
            'can_manage' => true,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'IntegrationConnected',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'integration.connected',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        Http::assertSentCount(2);
    }

    public function test_profile_fetch_allows_missing_optional_avatar_profile_url_and_email(): void
    {
        $this->configureLinkedIn();
        [$user] = $this->tenantWithRole('owner');

        $connect = $this->actingAs($user)->get(route('settings.integrations.linkedin.connect'));
        parse_str(parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'linkedin-access-token',
                'expires_in' => 3600,
                'scope' => 'openid profile email w_member_social',
            ]),
            'https://api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-person-no-optionals',
                'given_name' => 'No',
                'family_name' => 'Optionals',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin.callback', [
                'state' => $query['state'],
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations.linkedin'))
            ->assertSessionHas('linkedin_status', 'LinkedIn profile connected.');

        $profile = SocialProfile::query()->where('provider_profile_id', 'linkedin-person-no-optionals')->firstOrFail();

        $this->assertSame('No Optionals', $profile->display_name);
        $this->assertNull($profile->profile_url);
        $this->assertNull($profile->avatar_url);
        $this->assertNull($profile->metadata['email']);
    }

    public function test_profile_fetch_failure_after_token_exchange_marks_retryable_connection_error(): void
    {
        $this->configureLinkedIn();
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $connect = $this->actingAs($user)->get(route('settings.integrations.linkedin.connect'));
        parse_str(parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'linkedin-access-token',
                'refresh_token' => 'linkedin-refresh-token',
                'expires_in' => 3600,
                'scope' => 'openid profile email w_member_social',
            ]),
            'https://api.linkedin.com/v2/userinfo' => Http::response(['message' => 'temporary failure'], 500),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin.callback', [
                'state' => $query['state'],
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations.linkedin'))
            ->assertSessionHas('linkedin_error', 'LinkedIn profile lookup failed. Please try connecting again.');

        $connection = IntegrationConnection::query()->firstOrFail();
        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame('error', $connection->status);
        $this->assertSame($account->id, $connection->account_id);
        $this->assertSame($brand->id, $connection->brand_id);
        $this->assertNull($connection->provider_account_id);
        $this->assertSame('linkedin-access-token', $connection->access_token);
        $this->assertNotSame('linkedin-access-token', $raw->access_token);
        $this->assertTrue($connection->metadata['profile_fetch_failed']);
        $this->assertSame('LinkedIn profile lookup failed. Please try connecting again.', $connection->metadata['error_message']);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'linkedin.profile_fetch_failed',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertSame(0, SocialProfile::query()->count());
    }

    public function test_callback_rejects_invalid_state_without_storing_tokens(): void
    {
        $this->configureLinkedIn();
        [$user] = $this->tenantWithRole('owner');

        Http::fake();

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin.callback', [
                'state' => 'not-the-session-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations.linkedin'))
            ->assertSessionHas('linkedin_error');

        $this->assertSame(0, IntegrationConnection::query()->count());
        Http::assertNothingSent();
    }

    public function test_callback_shows_clean_error_for_denied_consent(): void
    {
        $this->configureLinkedIn();
        [$user] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin.callback', [
                'error' => 'access_denied',
                'error_description' => 'The member denied consent.',
            ]))
            ->assertRedirect(route('settings.integrations.linkedin'))
            ->assertSessionHas('linkedin_error', 'LinkedIn consent was denied. No connection was created.');

        $this->assertSame(0, IntegrationConnection::query()->count());
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
