<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\IntelligenceSignal;
use App\Models\Module;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class Ga4FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_analytics_settings_are_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $property = Property::factory()->forBrand($brand)->create(['name' => 'Main Website', 'url' => 'https://main.example']);
        $connection = $this->ga4Connection($user, $account, $brand);

        Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'property_id' => $property->id,
            'display_name' => 'GA4 Main Property',
            'website_url' => 'https://main.example',
            'status' => 'connected',
            'metadata' => ['property_id' => 'properties/123'],
            'last_synced_at' => now()->subHour(),
        ]);
        Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'display_name' => 'Hidden GA4 Property',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Google Analytics 4')
            ->assertSee('Manage GA4');

        $this->actingAs($user)
            ->get(route('settings.integrations.google-analytics'))
            ->assertOk()
            ->assertSee('GA4 Main Property')
            ->assertSee('Main Website')
            ->assertSee('properties/123')
            ->assertDontSee('Hidden GA4 Property');
    }

    public function test_analytics_dashboard_and_content_panel_show_ga4_snapshots(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'GA4 visible article']);
        $ga4Property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'GA4 Content Property',
            'website_url' => 'https://content.example',
            'status' => 'connected',
        ]);
        Ga4MetricSnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'ga4_property_id' => $ga4Property->id,
            'content_asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'sessions' => 120,
            'users' => 90,
            'pageviews' => 240,
            'engagement_rate' => 61.5,
            'conversions' => 4,
            'metadata' => ['source' => 'fixture'],
        ]);

        $this->actingAs($user)
            ->get(route('app.analytics'))
            ->assertOk()
            ->assertSee('Analytics foundation')
            ->assertSee('GA4 visible article')
            ->assertSee('120')
            ->assertSee('240')
            ->assertSee('61.50%');

        $this->actingAs($user)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertSee('GA4 analytics')
            ->assertSee('GA4 Content Property')
            ->assertSee('120')
            ->assertSee('61.50%');
    }

    public function test_ga4_models_reject_cross_tenant_references(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [, $otherAccount, $otherBrand] = $this->tenantWithRole('owner', 'scale_monthly', 'other-ga4-account');
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create();
        $ga4Property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'Tenant GA4 Property',
            'status' => 'connected',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GA4 metric snapshot content asset must belong to the same account and brand.');

        Ga4MetricSnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'ga4_property_id' => $ga4Property->id,
            'content_asset_id' => $otherAsset->id,
            'date' => now()->toDateString(),
        ]);
    }

    public function test_analytics_dashboard_requires_content_module_and_permission(): void
    {
        [$billing] = $this->tenantWithRole('billing');
        [$ownerNoContent] = $this->tenantWithRole('owner', 'core_only', 'ga4-core-only');

        $this->actingAs($billing)
            ->get(route('app.analytics'))
            ->assertForbidden();

        $this->actingAs($ownerNoContent)
            ->get(route('app.analytics'))
            ->assertForbidden();
    }

    public function test_google_analytics_settings_discover_accessible_ga4_properties(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-discovery');
        $connection = $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accounts' => Http::response([
                'accounts' => [
                    ['name' => 'accounts/100', 'displayName' => 'Marketing Account'],
                ],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties*' => Http::response([
                'properties' => [
                    [
                        'name' => 'properties/200',
                        'parent' => 'accounts/100',
                        'displayName' => 'Main Website GA4',
                        'websiteUrl' => 'https://main.example',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.google-analytics'))
            ->assertOk()
            ->assertSee('Discover GA4 properties')
            ->assertSee('Marketing Account')
            ->assertSee('Main Website GA4')
            ->assertSee('properties/200')
            ->assertSee('https://main.example');

        Http::assertSentCount(2);
    }

    public function test_selected_ga4_properties_are_stored_for_current_brand_with_mapping(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-store');
        $connection = $this->googleConnection($user, $account, $brand);
        $brandProperty = Property::factory()->forBrand($brand)->create(['name' => 'Main Website', 'url' => 'https://main.example']);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accounts' => Http::response([
                'accounts' => [
                    ['name' => 'accounts/100', 'displayName' => 'Marketing Account'],
                ],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties*' => Http::response([
                'properties' => [
                    [
                        'name' => 'properties/200',
                        'parent' => 'accounts/100',
                        'displayName' => 'Main Website GA4',
                        'websiteUrl' => 'https://main.example',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('settings.integrations.google-analytics.properties.store'), [
                'integration_connection_id' => $connection->id,
                'selected' => ['properties/200'],
                'property_map' => [
                    'properties/200' => $brandProperty->id,
                ],
            ])
            ->assertRedirect(route('settings.integrations.google-analytics'))
            ->assertSessionHas('google_status', '1 GA4 property selected.');

        $this->assertDatabaseHas('ga4_properties', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'property_id' => $brandProperty->id,
            'display_name' => 'Main Website GA4',
            'website_url' => 'https://main.example',
            'status' => 'connected',
        ]);

        $ga4Property = Ga4Property::query()->where('display_name', 'Main Website GA4')->firstOrFail();

        $this->assertSame('properties/200', $ga4Property->metadata['property_id']);
        $this->assertSame('200', $ga4Property->metadata['numeric_property_id']);
        $this->assertSame('accounts/100', $ga4Property->metadata['parent']);
    }

    public function test_google_analytics_discovery_handles_no_properties_state(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-empty');
        $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accounts' => Http::response([
                'accounts' => [
                    ['name' => 'accounts/100', 'displayName' => 'Empty Account'],
                ],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties*' => Http::response([
                'properties' => [],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.google-analytics'))
            ->assertOk()
            ->assertSee('No GA4 properties are available for this Google connection.');
    }

    public function test_google_analytics_discovery_handles_no_admin_access_state(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-no-access');
        $this->googleConnection($user, $account, $brand);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accounts' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.google-analytics'))
            ->assertOk()
            ->assertSee('Google Analytics Admin account discovery failed. Please try again.');
    }

    public function test_selected_ga4_property_mapping_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-tenant-safe');
        [, , $otherBrand] = $this->tenantWithRole('owner', slug: 'ga4-tenant-safe-other');
        $connection = $this->googleConnection($user, $account, $brand);
        $otherBrandProperty = Property::factory()->forBrand($otherBrand)->create(['name' => 'Other Website']);

        Http::fake();

        $this->actingAs($user)
            ->post(route('settings.integrations.google-analytics.properties.store'), [
                'integration_connection_id' => $connection->id,
                'selected' => ['properties/200'],
                'property_map' => [
                    'properties/200' => $otherBrandProperty->id,
                ],
            ])
            ->assertSessionHasErrors('property_map.properties/200');

        $this->assertSame(0, Ga4Property::query()->count());
        Http::assertNothingSent();
    }

    public function test_ga4_data_sync_stores_daily_snapshots_and_matches_content_urls(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-data-sync');
        $connection = $this->googleConnection($user, $account, $brand);
        $content = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Matched GA4 article',
            'canonical_url' => 'https://example.test/blog/matched-ga4-article/',
            'source_url' => null,
        ]);
        $property = $this->ga4Property($connection, $account, $brand);

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/200:runReport' => Http::response($this->ga4DataResponse([
                [
                    'date' => now()->format('Ymd'),
                    'pagePath' => '/blog/matched-ga4-article',
                    'country' => 'Netherlands',
                    'deviceCategory' => 'desktop',
                    'sessions' => '42',
                    'activeUsers' => '30',
                    'totalUsers' => '35',
                    'screenPageViews' => '84',
                    'engagementRate' => '0.625',
                    'conversions' => '3',
                ],
            ])),
        ]);

        $this->artisan('ga4:sync', [
            '--account' => $account->id,
            '--brand' => $brand->id,
            '--days' => 1,
        ])
            ->expectsOutput('Synced 1 GA4 property.')
            ->assertSuccessful();

        $snapshot = Ga4MetricSnapshot::query()->firstOrFail();

        $this->assertSame($content->id, $snapshot->content_asset_id);
        $this->assertSame('/blog/matched-ga4-article', $snapshot->page_path);
        $this->assertSame(42, $snapshot->sessions);
        $this->assertSame(30, $snapshot->users);
        $this->assertSame(84, $snapshot->pageviews);
        $this->assertSame('62.50', (string) $snapshot->engagement_rate);
        $this->assertSame(3, $snapshot->conversions);
        $this->assertSame('Netherlands', $snapshot->metadata['country']);
        $this->assertSame('desktop', $snapshot->metadata['device_category']);
        $this->assertNotNull($property->fresh()->last_synced_at);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'GA4SyncCompleted',
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => (new Ga4Property())->getMorphClass(),
            'subject_id' => $property->id,
        ]);
    }

    public function test_ga4_data_sync_is_idempotent_by_property_date_page_and_content_asset(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-idempotent');
        $connection = $this->googleConnection($user, $account, $brand);
        ContentAsset::factory()->forBrand($brand)->create([
            'canonical_url' => 'https://example.test/blog/idempotent',
        ]);
        $this->ga4Property($connection, $account, $brand);

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/200:runReport' => Http::sequence()
                ->push($this->ga4DataResponse([[
                    'date' => now()->format('Ymd'),
                    'pagePath' => '/blog/idempotent',
                    'sessions' => '10',
                    'activeUsers' => '7',
                    'screenPageViews' => '20',
                    'engagementRate' => '0.5',
                    'conversions' => '1',
                ]]))
                ->push($this->ga4DataResponse([[
                    'date' => now()->format('Ymd'),
                    'pagePath' => '/blog/idempotent',
                    'sessions' => '15',
                    'activeUsers' => '9',
                    'screenPageViews' => '25',
                    'engagementRate' => '0.6',
                    'conversions' => '2',
                ]])),
        ]);

        $this->artisan('ga4:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 1])->assertSuccessful();
        $this->artisan('ga4:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 1])->assertSuccessful();

        $this->assertSame(1, Ga4MetricSnapshot::query()->count());
        $this->assertSame(15, Ga4MetricSnapshot::query()->firstOrFail()->sessions);
    }

    public function test_ga4_data_sync_creates_signal_when_traffic_drops_strongly(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-drop');
        $connection = $this->googleConnection($user, $account, $brand);
        $property = $this->ga4Property($connection, $account, $brand);

        foreach ([now()->subDays(3), now()->subDays(2)] as $date) {
            Ga4MetricSnapshot::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'ga4_property_id' => $property->id,
                'page_path' => '/blog/drop',
                'date' => $date->toDateString(),
                'sessions' => 100,
            ]);
        }

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/200:runReport' => Http::response($this->ga4DataResponse([
                [
                    'date' => now()->subDay()->format('Ymd'),
                    'pagePath' => '/blog/drop',
                    'sessions' => '20',
                    'activeUsers' => '18',
                    'screenPageViews' => '30',
                    'engagementRate' => '0.4',
                    'conversions' => '0',
                ],
                [
                    'date' => now()->format('Ymd'),
                    'pagePath' => '/blog/drop',
                    'sessions' => '20',
                    'activeUsers' => '16',
                    'screenPageViews' => '28',
                    'engagementRate' => '0.38',
                    'conversions' => '0',
                ],
            ])),
        ]);

        $this->artisan('ga4:sync', ['--account' => $account->id, '--brand' => $brand->id, '--days' => 2])->assertSuccessful();

        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'GA4 traffic dropped strongly',
            'dedupe_key' => "ga4-traffic-drop:{$property->id}",
        ]);

        $signal = IntelligenceSignal::query()->where('dedupe_key', "ga4-traffic-drop:{$property->id}")->firstOrFail();

        $this->assertLessThanOrEqual(40, $signal->payload['current_sessions']);
        $this->assertGreaterThanOrEqual(100, $signal->payload['previous_sessions']);
    }

    public function test_content_detail_and_dashboard_show_synced_ga4_metrics(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', slug: 'ga4-ui');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'GA4 UI article']);
        $property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'GA4 UI Property',
            'website_url' => 'https://ui.example',
            'status' => 'connected',
        ]);
        Ga4MetricSnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'ga4_property_id' => $property->id,
            'content_asset_id' => $asset->id,
            'page_path' => '/ga4-ui-article',
            'date' => now()->toDateString(),
            'sessions' => 321,
            'users' => 210,
            'pageviews' => 654,
            'engagement_rate' => 70.5,
            'conversions' => 9,
        ]);

        $this->actingAs($user)
            ->get(route('app.content.show', $asset))
            ->assertOk()
            ->assertSee('Latest synced Google Analytics performance')
            ->assertSee('/ga4-ui-article')
            ->assertSee('321')
            ->assertSee('654');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GA4 performance')
            ->assertSee('321')
            ->assertSee('654');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, ?string $plan = 'scale_monthly', string $slug = 'ga4-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'GA4 Brand', 'slug' => "{$slug}-brand"]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($plan) {
            app(SubscriptionService::class)->activatePlan($account, $plan === 'core_only' ? 'starter_monthly' : $plan);

            if ($plan === 'core_only') {
                $contentModuleId = Module::query()->where('key', 'content')->value('id');
                $account->subscriptionModules()->where('module_id', $contentModuleId)->update(['status' => 'canceled']);
            }
        }

        return [$user, $account, $brand];
    }

    private function ga4Connection(User $user, Account $account, Brand $brand): IntegrationConnection
    {
        $this->seed(IntegrationCatalogSeeder::class);
        $integration = Integration::query()->where('key', 'google_analytics')->firstOrFail();

        return IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'GA4 Placeholder Connection',
            'status' => 'active',
            'provider_account_id' => 'ga4-placeholder',
            'provider_account_name' => 'GA4 Placeholder',
            'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
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
            'name' => 'Google for GA4',
            'status' => 'active',
            'provider_account_name' => 'Google for GA4',
            'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'metadata' => ['provider' => 'google'],
        ]);
    }

    private function ga4Property(IntegrationConnection $connection, Account $account, Brand $brand): Ga4Property
    {
        return Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'display_name' => 'Main Website GA4',
            'website_url' => 'https://example.test',
            'status' => 'connected',
            'metadata' => [
                'property_id' => 'properties/200',
                'numeric_property_id' => '200',
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, mixed>
     */
    private function ga4DataResponse(array $rows): array
    {
        return [
            'dimensionHeaders' => [
                ['name' => 'date'],
                ['name' => 'pagePath'],
                ['name' => 'country'],
                ['name' => 'deviceCategory'],
            ],
            'metricHeaders' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'totalUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'engagementRate'],
                ['name' => 'conversions'],
            ],
            'rows' => collect($rows)->map(fn (array $row) => [
                'dimensionValues' => [
                    ['value' => $row['date']],
                    ['value' => $row['pagePath']],
                    ['value' => $row['country'] ?? ''],
                    ['value' => $row['deviceCategory'] ?? ''],
                ],
                'metricValues' => [
                    ['value' => $row['sessions'] ?? '0'],
                    ['value' => $row['activeUsers'] ?? ''],
                    ['value' => $row['totalUsers'] ?? ''],
                    ['value' => $row['screenPageViews'] ?? '0'],
                    ['value' => $row['engagementRate'] ?? '0'],
                    ['value' => $row['conversions'] ?? '0'],
                ],
            ])->all(),
        ];
    }
}
