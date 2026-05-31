<?php

namespace Tests\Feature;

use App\Data\Integrations\LinkedIn\LinkedInAccount;
use App\Data\Integrations\LinkedIn\LinkedInToken;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Integrations\IntegrationPermissionService;
use App\Services\Integrations\LinkedIn\LinkedInConnectionService;
use App\Services\Integrations\LinkedIn\LinkedInProvider;
use Database\Seeders\IntegrationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class IntegrationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_catalog_seeder_creates_supported_oauth_providers(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $this->assertSame(7, Integration::query()->count());
        $this->assertDatabaseHas('integrations', ['key' => 'linkedin', 'auth_type' => 'oauth2']);
        $this->assertSame(
            ['openid', 'profile', 'email', 'w_member_social'],
            Integration::query()->where('key', 'linkedin')->firstOrFail()->default_scopes,
        );
        $this->assertDatabaseHas('integrations', ['key' => 'google', 'auth_type' => 'oauth2']);
        $this->assertDatabaseHas('integrations', ['key' => 'laravel', 'auth_type' => 'api_key']);
        $this->assertDatabaseHas('integrations', ['key' => 'youtube', 'auth_type' => 'oauth2']);
    }

    public function test_linkedin_provider_exposes_current_and_future_scope_architecture(): void
    {
        $provider = app(LinkedInProvider::class);

        $this->assertSame('linkedin', $provider->key());
        $this->assertSame(['openid', 'profile', 'email', 'w_member_social'], $provider->scopes());
        $this->assertSame(['r_member_social', 'r_organization_social', 'w_organization_social'], $provider->futureScopes());
        $this->assertTrue($provider->supportsPersonalProfiles());
        $this->assertFalse($provider->supportsOrganizationPages());
        $this->assertFalse($provider->oauthEnabled());
    }

    public function test_linkedin_connection_service_prepares_personal_profile_connections_without_real_oauth_calls(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand A', 'slug' => 'brand-a']);
        $owner->accounts()->attach($account, ['status' => 'active']);
        $owner->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $connection = app(LinkedInConnectionService::class)->createPersonalProfileConnection(
            owner: $owner,
            account: new LinkedInAccount(
                id: 'linkedin-person-123',
                name: 'Maria LinkedIn',
                email: 'maria@example.test',
                profileUrl: 'https://www.linkedin.com/in/maria',
            ),
            token: new LinkedInToken(
                accessToken: 'linkedin-access-token',
                refreshToken: 'linkedin-refresh-token',
                expiresAt: now()->addHour(),
                scopes: ['openid', 'profile', 'email', 'w_member_social'],
                payload: ['token_type' => 'Bearer'],
            ),
            tenantAccount: $account,
            brand: $brand,
        );

        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame('linkedin-access-token', $connection->fresh()->access_token);
        $this->assertNotSame('linkedin-access-token', $raw->access_token);
        $this->assertSame($account->id, $connection->account_id);
        $this->assertSame($brand->id, $connection->brand_id);
        $this->assertSame('personal_profile', $connection->metadata['linkedin_account_type']);
        $this->assertTrue($connection->metadata['supports_organization_pages_later']);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'LinkedInProfileConnectionPrepared',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'linkedin.profile_connection.prepared',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
        $this->assertDatabaseHas('social_profiles', [
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $owner->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'linkedin-person-123',
            'display_name' => 'Maria LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);
    }

    public function test_user_can_create_owned_oauth_connection_with_encrypted_tokens(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Maria LinkedIn',
            scopes: ['w_member_social'],
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            tokenPayload: ['token_type' => 'Bearer'],
            providerAccountId: 'linkedin-123',
        );

        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame('access-token', $connection->fresh()->access_token);
        $this->assertNotSame('access-token', $raw->access_token);
        $this->assertSame('refresh-token', $connection->fresh()->refresh_token);
        $this->assertNotSame('refresh-token', $raw->refresh_token);
        $this->assertTrue(app(IntegrationPermissionService::class)->canManage($owner, $connection));
    }

    public function test_connection_can_be_shared_with_account_members(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $owner->accounts()->attach($account, ['status' => 'active']);
        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'google',
            name: 'Google Search Console',
            account: $account,
        );

        $member->accounts()->attach($account, ['status' => 'active']);

        app(IntegrationPermissionService::class)->shareWithAccount($connection, $account, $owner);

        $this->assertTrue(app(IntegrationPermissionService::class)->canUse($member, $connection, $account));
    }

    public function test_connection_shared_with_brand_cannot_be_used_by_other_brand(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $brandX = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand X', 'slug' => 'brand-x']);
        $brandY = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand Y', 'slug' => 'brand-y']);

        $owner->accounts()->attach($account, ['status' => 'active']);
        $owner->brands()->attach($brandX, ['account_id' => $account->id, 'status' => 'active']);
        $member->accounts()->attach($account, ['status' => 'active']);
        $member->brands()->attach($brandX, ['account_id' => $account->id, 'status' => 'active']);
        $member->brands()->attach($brandY, ['account_id' => $account->id, 'status' => 'active']);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'LinkedIn Company Page',
            account: $account,
            brand: $brandX,
        );

        app(IntegrationPermissionService::class)->shareWithBrand($connection, $brandX, $owner);

        $this->assertTrue(app(IntegrationPermissionService::class)->canUse($member, $connection, $account, $brandX));
        $this->assertFalse(app(IntegrationPermissionService::class)->canUse($member, $connection, $account, $brandY));
    }

    public function test_connection_cannot_be_shared_with_brand_outside_connection_account(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $accountA = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $accountB = Account::query()->create(['name' => 'Account B', 'slug' => 'account-b']);
        $brandB = Brand::query()->create(['account_id' => $accountB->id, 'name' => 'Brand B', 'slug' => 'brand-b']);
        $owner->accounts()->attach($accountA, ['status' => 'active']);
        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'meta',
            name: 'Meta Ads',
            account: $accountA,
        );

        $this->expectException(InvalidArgumentException::class);

        app(IntegrationPermissionService::class)->shareWithBrand($connection, $brandB, $owner);
    }

    public function test_revoking_connection_removes_usable_tokens_and_access(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $owner->accounts()->attach($account, ['status' => 'active']);
        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'x',
            name: 'X Account',
            account: $account,
            accessToken: 'token',
            refreshToken: 'refresh',
        );

        $member->accounts()->attach($account, ['status' => 'active']);
        app(IntegrationPermissionService::class)->shareWithAccount($connection, $account, $owner);

        app(IntegrationConnectionService::class)->revoke($connection);

        $revoked = IntegrationConnection::query()->findOrFail($connection->id);

        $this->assertSame('revoked', $revoked->status);
        $this->assertNull($revoked->access_token);
        $this->assertNull($revoked->refresh_token);
        $this->assertFalse(app(IntegrationPermissionService::class)->canUse($member, $revoked, $account));
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'IntegrationDisconnected',
            'account_id' => $account->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'integration.disconnected',
            'account_id' => $account->id,
        ]);
    }

    public function test_account_scoped_connection_cannot_be_shared_with_another_account(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $accountA = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);
        $accountB = Account::query()->create(['name' => 'Account B', 'slug' => 'account-b']);

        $owner->accounts()->attach($accountA, ['status' => 'active']);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'google',
            name: 'Google Search Console',
            account: $accountA,
        );

        $this->expectException(InvalidArgumentException::class);

        app(IntegrationPermissionService::class)->shareWithAccount($connection, $accountB, $owner);
    }

    public function test_direct_user_share_rejects_users_outside_connection_scope(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);

        $owner->accounts()->attach($account, ['status' => 'active']);

        $connection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'LinkedIn',
            account: $account,
        );

        $this->expectException(InvalidArgumentException::class);

        app(IntegrationPermissionService::class)->shareWithUser($connection, $outsider, $owner);
    }

    public function test_owner_must_belong_to_connection_account(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        $owner = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account A', 'slug' => 'account-a']);

        $this->expectException(InvalidArgumentException::class);

        app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'x',
            name: 'X',
            account: $account,
        );
    }
}
