<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Ga4Property;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\Role;
use App\Models\SearchConsoleSite;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Integrations\Google\GoogleTokenService;
use App\Services\SourceRegistryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class GoogleTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_if_possible_updates_encrypted_tokens_and_restores_source_connection(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => 'old-refresh-token',
        ]);
        $sourceConnection = $this->googleSourceConnection($connection, $account, $brand, 'Google Analytics 4');
        $sourceConnection->update(['status' => 'error']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-google-access-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            ]),
        ]);

        $refreshed = app(GoogleTokenService::class)->refreshIfPossible($connection);
        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame('active', $refreshed->status);
        $this->assertSame('new-google-access-token', $refreshed->access_token);
        $this->assertNotSame('new-google-access-token', $raw->access_token);
        $this->assertSame('old-refresh-token', $refreshed->refresh_token);
        $this->assertNotNull($refreshed->token_expires_at);
        $this->assertSame('configured', $sourceConnection->fresh()->status);
    }

    public function test_missing_refresh_token_marks_google_connection_expired_and_records_signal(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);
        $sourceConnection = $this->googleSourceConnection($connection, $account, $brand, 'Google Analytics 4');
        $ga4 = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'display_name' => 'GA4 Main Property',
            'status' => 'connected',
        ]);
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'site_url' => 'https://example.test/',
            'status' => 'connected',
        ]);

        $expired = app(GoogleTokenService::class)->refreshIfPossible($connection);

        $this->assertSame('expired', $expired->status);
        $this->assertSame('Google refresh token is unavailable.', $expired->metadata['token_error_message']);
        $this->assertSame('error', $sourceConnection->fresh()->status);
        $this->assertSame('error', $ga4->fresh()->status);
        $this->assertSame('error', $site->fresh()->status);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Reconnect Google integration',
            'dedupe_key' => "google-reconnect:{$connection->id}",
        ]);
    }

    public function test_failed_refresh_marks_connection_expired(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => 'refresh-token',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $expired = app(GoogleTokenService::class)->refreshIfPossible($connection);

        $this->assertSame('expired', $expired->status);
        $this->assertSame('Google token refresh failed.', $expired->metadata['token_error_message']);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "google-reconnect:{$connection->id}")->count());
    }

    public function test_mark_revoked_clears_tokens_pauses_sources_and_records_signal(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand);
        $sourceConnection = $this->googleSourceConnection($connection, $account, $brand, 'Google Search Console');

        $revoked = app(GoogleTokenService::class)->markRevoked($connection);

        $this->assertSame('revoked', $revoked->status);
        $this->assertNull($revoked->access_token);
        $this->assertNull($revoked->refresh_token);
        $this->assertSame('paused', $sourceConnection->fresh()->status);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "google-reconnect:{$connection->id}")->count());
    }

    public function test_token_health_command_marks_due_google_tokens(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        $this->artisan('google:check-token-health')
            ->expectsOutput('Checked 1 Google connection(s); processed 1 token health update(s).')
            ->assertSuccessful();

        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "google-reconnect:{$connection->id}")->count());
    }

    public function test_invalid_google_token_blocks_ga4_and_search_console_source_sync(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$connection] = $this->googleConnection($user, $account, $brand, [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);
        $sourceConnection = $this->googleSourceConnection($connection, $account, $brand, 'Google Search Console');
        $source = $sourceConnection->source;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reconnect Google integration before syncing GA4 or Search Console.');

        app(SourceRegistryService::class)->createPlannedSync($source);
    }

    private function configureGoogle(): void
    {
        config([
            'integrations.providers.google.oauth.client_id' => 'google-client-id',
            'integrations.providers.google.oauth.client_secret' => 'google-client-secret',
            'integrations.providers.google.oauth.redirect_uri' => 'https://app.test/settings/integrations/google/callback',
            'integrations.providers.google.oauth.enabled' => true,
        ]);

        $this->seed(IntegrationCatalogSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: IntegrationConnection}
     */
    private function googleConnection(User $user, Account $account, Brand $brand, array $overrides = []): array
    {
        $integration = Integration::query()->where('key', 'google')->firstOrFail();

        return [
            IntegrationConnection::query()->create([
                'integration_id' => $integration->id,
                'owner_user_id' => $user->id,
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'name' => 'Google for Alpha Brand',
                'status' => $overrides['status'] ?? 'active',
                'provider_account_name' => 'Google for Alpha Brand',
                'scopes' => [
                    'https://www.googleapis.com/auth/analytics.readonly',
                    'https://www.googleapis.com/auth/webmasters.readonly',
                ],
                'access_token' => array_key_exists('access_token', $overrides) ? $overrides['access_token'] : 'access-token',
                'refresh_token' => array_key_exists('refresh_token', $overrides) ? $overrides['refresh_token'] : 'refresh-token',
                'token_expires_at' => $overrides['token_expires_at'] ?? now()->addHour(),
                'metadata' => ['provider' => 'google'],
            ]),
        ];
    }

    private function googleSourceConnection(IntegrationConnection $connection, Account $account, Brand $brand, string $name): SourceConnection
    {
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'type' => 'search',
            'provider' => 'google',
            'status' => 'active',
        ]);

        return SourceConnection::query()->create([
            'source_id' => $source->id,
            'integration_connection_id' => $connection->id,
            'status' => 'configured',
        ]);
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
