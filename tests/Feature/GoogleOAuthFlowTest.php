<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_generates_authorization_url_and_stores_state(): void
    {
        $this->configureGoogle();
        [$user] = $this->tenantWithRole('owner');

        $response = $this->actingAs($user)->get(route('settings.integrations.google.connect'));

        $response->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://app.test/settings/integrations/google/callback', $query['redirect_uri']);
        $this->assertSame(
            'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            $query['scope'],
        );
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertNotEmpty($query['state']);
        $this->assertArrayHasKey($query['state'], session('oauth.google.states'));
    }

    public function test_callback_validates_state_stores_tokens_and_creates_google_sources(): void
    {
        $this->configureGoogle();
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $connect = $this->actingAs($user)->get(route('settings.integrations.google.connect'));
        parse_str(parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
                'token_type' => 'Bearer',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.google.callback', [
                'state' => $query['state'],
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('google_status', 'Google connected.');

        $connection = IntegrationConnection::query()->whereHas('integration', fn ($query) => $query->where('key', 'google'))->firstOrFail();
        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();

        $this->assertSame($account->id, $connection->account_id);
        $this->assertSame($brand->id, $connection->brand_id);
        $this->assertSame('google-access-token', $connection->access_token);
        $this->assertNotSame('google-access-token', $raw->access_token);
        $this->assertSame('google-refresh-token', $connection->refresh_token);
        $this->assertNotSame('google-refresh-token', $raw->refresh_token);
        $this->assertSame([
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/webmasters.readonly',
        ], $connection->scopes);
        $this->assertNotNull($connection->token_expires_at);
        $this->assertTrue($connection->metadata['offline_access_requested']);
        $this->assertTrue($connection->metadata['refresh_token_returned']);

        $this->assertDatabaseHas('sources', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'google',
            'name' => 'Google Analytics 4',
            'type' => 'search',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('sources', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'google',
            'name' => 'Google Search Console',
            'type' => 'search',
            'status' => 'active',
        ]);
        $this->assertSame(2, SourceConnection::query()->where('integration_connection_id', $connection->id)->count());
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
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'google.oauth_connected',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        Http::assertSentCount(1);
    }

    public function test_reconnect_preserves_existing_refresh_token_when_google_omits_one(): void
    {
        $this->configureGoogle();
        [$user] = $this->tenantWithRole('owner');

        $firstConnect = $this->actingAs($user)->get(route('settings.integrations.google.connect'));
        parse_str(parse_url($firstConnect->headers->get('Location'), PHP_URL_QUERY), $firstQuery);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push([
                    'access_token' => 'first-google-access-token',
                    'refresh_token' => 'original-google-refresh-token',
                    'expires_in' => 3600,
                    'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
                ])
                ->push([
                    'access_token' => 'second-google-access-token',
                    'expires_in' => 3600,
                    'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
                ]),
        ]);

        $this->actingAs($user)->get(route('settings.integrations.google.callback', [
            'state' => $firstQuery['state'],
            'code' => 'first-code',
        ]));

        $secondConnect = $this->actingAs($user)->get(route('settings.integrations.google.connect'));
        parse_str(parse_url($secondConnect->headers->get('Location'), PHP_URL_QUERY), $secondQuery);

        $this->actingAs($user)->get(route('settings.integrations.google.callback', [
            'state' => $secondQuery['state'],
            'code' => 'second-code',
        ]));

        $connection = IntegrationConnection::query()->firstOrFail();

        $this->assertSame('second-google-access-token', $connection->fresh()->access_token);
        $this->assertSame('original-google-refresh-token', $connection->fresh()->refresh_token);
        $this->assertSame(1, IntegrationConnection::query()->count());
        $this->assertSame(2, Source::query()->count());
    }

    public function test_callback_rejects_invalid_state_without_storing_tokens(): void
    {
        $this->configureGoogle();
        [$user] = $this->tenantWithRole('owner');

        Http::fake();

        $this->actingAs($user)
            ->get(route('settings.integrations.google.callback', [
                'state' => 'not-the-session-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('google_error');

        $this->assertSame(0, IntegrationConnection::query()->count());
        Http::assertNothingSent();
    }

    public function test_callback_shows_clean_error_for_denied_consent(): void
    {
        $this->configureGoogle();
        [$user] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->get(route('settings.integrations.google.callback', [
                'error' => 'access_denied',
                'error_description' => 'The user denied consent.',
            ]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('google_error', 'Google consent was denied. No connection was created.');

        $this->assertSame(0, IntegrationConnection::query()->count());
    }

    public function test_disconnect_revokes_google_connection_and_pauses_source_connections(): void
    {
        $this->configureGoogle();
        [$user] = $this->tenantWithRole('owner');

        $connect = $this->actingAs($user)->get(route('settings.integrations.google.connect'));
        parse_str(parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->actingAs($user)->get(route('settings.integrations.google.callback', [
            'state' => $query['state'],
            'code' => 'authorization-code',
        ]));

        $connection = IntegrationConnection::query()->firstOrFail();

        $this->from(route('settings.integrations.google-analytics'))
            ->actingAs($user)
            ->post(route('settings.integrations.google.disconnect', $connection))
            ->assertRedirect(route('settings.integrations.google-analytics'))
            ->assertSessionHas('google_status', 'Google disconnected.');

        $this->assertSame('revoked', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->access_token);
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertSame(2, SourceConnection::query()->where('status', 'paused')->count());
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'IntegrationDisconnected',
        ]);
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
