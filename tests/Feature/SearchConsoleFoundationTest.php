<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\Module;
use App\Models\Role;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SearchConsoleSite;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SearchConsoleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_console_settings_are_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-search-brand']);
        $connection = $this->searchConsoleConnection($user, $account, $brand);

        SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'site_url' => 'https://main.example/',
            'status' => 'connected',
            'metadata' => [
                'permission_level' => 'siteOwner',
                'site_type' => 'url-prefix',
                'verification_state' => 'verified',
            ],
            'last_synced_at' => now()->subHour(),
        ]);
        SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'site_url' => 'https://hidden.example/',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Google Search Console')
            ->assertSee('Manage Search Console');

        $this->actingAs($user)
            ->get(route('settings.integrations.search-console'))
            ->assertOk()
            ->assertSee('https://main.example/')
            ->assertSee('siteOwner')
            ->assertSee('verified')
            ->assertDontSee('https://hidden.example/');
    }

    public function test_search_performance_dashboard_and_content_panel_show_query_snapshots(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Search visible article']);
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://content.example/',
            'status' => 'connected',
        ]);
        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'query' => 'argusly lifecycle scoring',
            'page' => 'https://content.example/search-visible-article',
            'country' => 'US',
            'device' => 'desktop',
            'clicks' => 18,
            'impressions' => 240,
            'ctr' => 0.075,
            'position' => 4.2,
            'metadata' => ['source' => 'fixture'],
        ]);

        $this->actingAs($user)
            ->get(route('app.search-performance'))
            ->assertOk()
            ->assertSee('Search performance foundation')
            ->assertSee('Search visible article')
            ->assertSee('argusly lifecycle scoring')
            ->assertSee('18')
            ->assertSee('240')
            ->assertSee('7.50%')
            ->assertSee('4.20');

        $this->actingAs($user)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertSee('SEO performance')
            ->assertSee('argusly lifecycle scoring')
            ->assertSee('18')
            ->assertSee('7.50%')
            ->assertSee('4.20');
    }

    public function test_search_console_models_reject_cross_tenant_references(): void
    {
        [, $account, $brand] = $this->tenantWithRole('owner');
        [, , $otherBrand] = $this->tenantWithRole('owner', 'scale_monthly', 'other-search-account');
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create();
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://tenant.example/',
            'status' => 'connected',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search Console query snapshot content asset must belong to the same account and brand.');

        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $otherAsset->id,
            'date' => now()->toDateString(),
        ]);
    }

    public function test_search_performance_dashboard_requires_visibility_module_and_permission(): void
    {
        [$billing] = $this->tenantWithRole('billing');
        [$ownerNoVisibility] = $this->tenantWithRole('owner', 'no_visibility', 'search-no-visibility');

        $this->actingAs($billing)
            ->get(route('app.search-performance'))
            ->assertForbidden();

        $this->actingAs($ownerNoVisibility)
            ->get(route('app.search-performance'))
            ->assertForbidden();
    }

    public function test_search_console_settings_discover_verified_sites(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-discovery');
        $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://main.example/', 'permissionLevel' => 'siteOwner'],
                    ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteFullUser'],
                    ['siteUrl' => 'https://unverified.example/', 'permissionLevel' => 'siteUnverifiedUser'],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.search-console'))
            ->assertOk()
            ->assertSee('Discover Search Console sites')
            ->assertSee('https://main.example/')
            ->assertSee('siteOwner')
            ->assertSee('sc-domain:example.com')
            ->assertSee('siteFullUser')
            ->assertDontSee('https://unverified.example/');

        Http::assertSentCount(1);
    }

    public function test_selected_search_console_sites_are_stored_for_current_brand(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-store');
        $connection = $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://main.example/', 'permissionLevel' => 'siteOwner'],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('settings.integrations.search-console.sites.store'), [
                'integration_connection_id' => $connection->id,
                'selected' => ['https://main.example/'],
            ])
            ->assertRedirect(route('settings.integrations.search-console'))
            ->assertSessionHas('google_status', '1 Search Console site selected.');

        $this->assertDatabaseHas('search_console_sites', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'site_url' => 'https://main.example/',
            'status' => 'connected',
        ]);

        $site = SearchConsoleSite::query()->where('site_url', 'https://main.example/')->firstOrFail();

        $this->assertSame('siteOwner', $site->metadata['permission_level']);
        $this->assertSame('url-prefix', $site->metadata['site_type']);
        $this->assertSame('verified', $site->metadata['verification_state']);
    }

    public function test_search_console_discovery_handles_no_sites_state(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-empty');
        $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.search-console'))
            ->assertOk()
            ->assertSee('No verified Search Console sites are available for this Google connection.');
    }

    public function test_search_console_discovery_handles_no_permission_state(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-no-permission');
        $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.search-console'))
            ->assertOk()
            ->assertSee('Search Console site discovery failed. Please try again.');
    }

    public function test_selected_search_console_site_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-tenant-safe');
        [, $otherAccount, $otherBrand] = $this->tenantWithRole('owner', slug: 'search-tenant-safe-other');
        $this->googleConnection($user, $account, $brand);
        $otherConnection = $this->googleConnection($user, $otherAccount, $otherBrand);

        Http::fake();

        $this->actingAs($user)
            ->post(route('settings.integrations.search-console.sites.store'), [
                'integration_connection_id' => $otherConnection->id,
                'selected' => ['https://main.example/'],
            ])
            ->assertSessionHasErrors('integration_connection_id');

        $this->assertSame(0, SearchConsoleSite::query()->count());
        Http::assertNothingSent();
    }

    public function test_search_console_sync_stores_query_snapshots_and_matches_content_urls(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-sync');
        $connection = $this->googleConnection($user, $account, $brand);
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Matched search article',
            'canonical_url' => 'https://main.example/blog/matched-search-article/',
        ]);
        $site = $this->connectedSite($connection, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response($this->searchAnalyticsResponse([
                [
                    'date' => now()->toDateString(),
                    'query' => 'matched search article',
                    'page' => 'https://main.example/blog/matched-search-article',
                    'country' => 'usa',
                    'device' => 'DESKTOP',
                    'clicks' => 18,
                    'impressions' => 240,
                    'ctr' => 0.075,
                    'position' => 4.2,
                ],
            ])),
        ]);

        $this->artisan('search-console:sync', [
            '--account' => $account->id,
            '--brand' => $brand->id,
            '--days' => 1,
        ])
            ->expectsOutput('Synced 1 Search Console site.')
            ->assertSuccessful();

        $snapshot = SearchConsoleQuerySnapshot::query()->firstOrFail();

        $this->assertSame($asset->id, $snapshot->content_asset_id);
        $this->assertSame('matched search article', $snapshot->query);
        $this->assertSame('https://main.example/blog/matched-search-article', $snapshot->page);
        $this->assertSame('USA', $snapshot->country);
        $this->assertSame('desktop', $snapshot->device);
        $this->assertSame(18, $snapshot->clicks);
        $this->assertSame(240, $snapshot->impressions);
        $this->assertSame('0.0750', (string) $snapshot->ctr);
        $this->assertSame('4.20', (string) $snapshot->position);
        $this->assertNotNull($site->fresh()->last_synced_at);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'SearchConsoleSyncCompleted',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => (new SearchConsoleSite())->getMorphClass(),
            'subject_id' => $site->id,
        ]);
    }

    public function test_search_console_sync_is_idempotent_by_dimensions(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-idempotent');
        $connection = $this->googleConnection($user, $account, $brand);
        $this->connectedSite($connection, $account, $brand);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push($this->searchAnalyticsResponse([[
                    'date' => now()->toDateString(),
                    'query' => 'idempotent',
                    'page' => 'https://main.example/idempotent',
                    'country' => 'usa',
                    'device' => 'MOBILE',
                    'clicks' => 5,
                    'impressions' => 100,
                    'ctr' => 0.05,
                    'position' => 8.1,
                ]]))
                ->push($this->searchAnalyticsResponse([[
                    'date' => now()->toDateString(),
                    'query' => 'idempotent',
                    'page' => 'https://main.example/idempotent',
                    'country' => 'usa',
                    'device' => 'MOBILE',
                    'clicks' => 7,
                    'impressions' => 110,
                    'ctr' => 0.0636,
                    'position' => 7.4,
                ]])),
        ]);

        $this->artisan('search-console:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 1])->assertSuccessful();
        $this->artisan('search-console:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 1])->assertSuccessful();

        $this->assertSame(1, SearchConsoleQuerySnapshot::query()->count());
        $this->assertSame(7, SearchConsoleQuerySnapshot::query()->firstOrFail()->clicks);
    }

    public function test_search_console_sync_creates_performance_signals(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-signals');
        $connection = $this->googleConnection($user, $account, $brand);
        $site = $this->connectedSite($connection, $account, $brand);

        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'search_console_site_id' => $site->id,
            'date' => now()->subDay()->toDateString(),
            'query' => 'declining',
            'page' => 'https://main.example/declining',
            'country' => 'USA',
            'device' => 'desktop',
            'clicks' => 100,
            'impressions' => 1000,
            'ctr' => 0.1,
            'position' => 3.0,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response($this->searchAnalyticsResponse([
                [
                    'date' => now()->toDateString(),
                    'query' => 'declining',
                    'page' => 'https://main.example/declining',
                    'country' => 'usa',
                    'device' => 'DESKTOP',
                    'clicks' => 20,
                    'impressions' => 500,
                    'ctr' => 0.005,
                    'position' => 12.0,
                ],
            ])),
        ]);

        $this->artisan('search-console:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 1])->assertSuccessful();

        foreach ([
            'Search impressions dropped strongly',
            'Search clicks dropped strongly',
            'High impressions with low CTR',
            'Search ranking declined',
        ] as $title) {
            $this->assertDatabaseHas('intelligence_signals', [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'title' => $title,
            ]);
        }

        $this->assertSame(4, IntelligenceSignal::query()->where('source', 'search_console_sync')->count());
    }

    public function test_dashboard_and_content_detail_show_synced_search_console_metrics(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'search-ui');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Search UI article']);
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://main.example/',
            'status' => 'connected',
        ]);
        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'query' => 'search ui article',
            'page' => 'https://main.example/search-ui-article',
            'country' => 'US',
            'device' => 'desktop',
            'clicks' => 44,
            'impressions' => 880,
            'ctr' => 0.05,
            'position' => 6.2,
        ]);

        $this->actingAs($user)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertSee('Latest synced Search Console performance')
            ->assertSee('search ui article')
            ->assertSee('44')
            ->assertSee('5.00%');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Search performance')
            ->assertSee('44')
            ->assertSee('880');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, ?string $plan = 'scale_monthly', string $slug = 'search-console-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Search Brand', 'slug' => "{$slug}-brand"]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($plan) {
            app(SubscriptionService::class)->activatePlan($account, $plan === 'no_visibility' ? 'scale_monthly' : $plan);

            if ($plan === 'no_visibility') {
                $visibilityModuleId = Module::query()->where('key', 'visibility')->value('id');
                $account->subscriptionModules()->where('module_id', $visibilityModuleId)->update(['status' => 'canceled']);
            }
        }

        return [$user, $account, $brand];
    }

    private function searchConsoleConnection(User $user, Account $account, Brand $brand): IntegrationConnection
    {
        $this->seed(IntegrationCatalogSeeder::class);
        $integration = Integration::query()->where('key', 'google_search_console')->firstOrFail();

        return IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Search Console Placeholder Connection',
            'status' => 'active',
            'provider_account_id' => 'search-console-placeholder',
            'provider_account_name' => 'Search Console Placeholder',
            'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly'],
        ]);
    }

    private function googleConnection(User $user, Account $account, Brand $brand): IntegrationConnection
    {
        $this->seed(IntegrationCatalogSeeder::class);
        $integration = Integration::query()->where('key', 'google')->firstOrFail();

        return IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Google for Search Console',
            'status' => 'active',
            'provider_account_name' => 'Google for Search Console',
            'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly'],
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'metadata' => ['provider' => 'google'],
        ]);
    }

    private function connectedSite(IntegrationConnection $connection, Account $account, Brand $brand): SearchConsoleSite
    {
        return SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'site_url' => 'https://main.example/',
            'status' => 'connected',
            'metadata' => [
                'permission_level' => 'siteOwner',
                'site_type' => 'url-prefix',
                'verification_state' => 'verified',
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function searchAnalyticsResponse(array $rows): array
    {
        return [
            'rows' => collect($rows)->map(fn (array $row) => [
                'keys' => [
                    $row['date'],
                    $row['query'],
                    $row['page'],
                    $row['country'],
                    $row['device'],
                ],
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr' => $row['ctr'],
                'position' => $row['position'],
            ])->all(),
        ];
    }
}
