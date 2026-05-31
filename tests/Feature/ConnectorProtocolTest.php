<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ConnectorToken;
use App\Models\ConnectorVersion;
use App\Models\Module;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\ConnectorCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_catalog_seeds_manifests_versions_and_capabilities(): void
    {
        $this->seed(ConnectorCatalogSeeder::class);

        $this->assertDatabaseHas('connector_manifests', [
            'key' => 'wordpress',
            'type' => 'wordpress',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('connector_manifests', ['key' => 'laravel', 'type' => 'laravel']);
        $this->assertDatabaseHas('connector_manifests', ['key' => 'shopify', 'type' => 'shopify']);
        $this->assertDatabaseHas('connector_capabilities', ['capability' => 'publish_content']);
        $this->assertDatabaseHas('connector_capabilities', ['capability' => 'preview_url']);
    }

    public function test_settings_connectors_registers_installation_with_version_capabilities_and_logs(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->seed(ConnectorCatalogSeeder::class);
        $version = ConnectorVersion::query()
            ->whereHas('manifest', fn ($query) => $query->where('key', 'wordpress'))
            ->firstOrFail();
        $property = Property::factory()->forBrand($brand)->create(['name' => 'Main Site']);
        $channel = PublishingChannel::factory()->forProperty($property)->create(['name' => 'WordPress Production']);

        $this->actingAs($user)
            ->post(route('settings.connectors.store'), [
                'connector_version_id' => $version->id,
                'name' => 'Production WordPress',
                'scope' => 'brand',
                'property_id' => $property->id,
                'channel_id' => $channel->id,
                'status' => 'active',
                'endpoint_url' => 'https://wp.example.com',
                'enabled_capabilities' => ['publish_content', 'preview_url'],
            ])
            ->assertRedirect(route('settings.connectors'));

        $installation = ConnectorInstallation::query()->firstOrFail();

        $this->assertSame($account->id, $installation->account_id);
        $this->assertSame($brand->id, $installation->brand_id);
        $this->assertSame($version->id, $installation->connector_version_id);
        $this->assertSame(['publish_content', 'preview_url'], $installation->enabled_capabilities);
        $this->assertNull($installation->api_access_token);
        $this->assertDatabaseHas('connector_logs', [
            'connector_installation_id' => $installation->id,
            'event' => 'connector.registered',
            'status' => 'registered',
        ]);

        $this->actingAs($user)
            ->get(route('settings.connectors'))
            ->assertOk()
            ->assertSee('Production WordPress')
            ->assertSee('WordPress Production')
            ->assertSee('Publish Content');
    }

    public function test_settings_can_create_revoke_and_rotate_connector_tokens_once(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->seed(ConnectorCatalogSeeder::class);
        $version = ConnectorVersion::query()->firstOrFail();
        $property = Property::factory()->forBrand($brand)->create();
        $channel = PublishingChannel::factory()->forProperty($property)->create();
        $installation = ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'channel_id' => $channel->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'Token Connector',
            'status' => 'active',
            'enabled_capabilities' => ['health_check'],
        ]);

        $response = $this->actingAs($user)
            ->post(route('settings.connectors.tokens.store'), [
                'connector_installation_id' => $installation->id,
                'name' => 'Production API token',
                'abilities' => ['connector:read', 'health:write'],
            ])
            ->assertRedirect(route('settings.connectors'));

        $plainToken = $response->baseResponse->getSession()->get('connector_plain_token');
        $token = ConnectorToken::query()->firstOrFail();

        $this->assertIsString($plainToken);
        $this->assertStringStartsWith('argusly_ct_', $plainToken);
        $this->assertSame(ConnectorToken::hashToken($plainToken), $token->token_hash);
        $this->assertDatabaseMissing('connector_tokens', ['token_hash' => $plainToken]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'ConnectorTokenCreated',
            'subject_id' => $token->id,
        ]);

        $this->actingAs($user)
            ->delete(route('settings.connectors.tokens.revoke', $token))
            ->assertRedirect(route('settings.connectors'));

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'ConnectorTokenRevoked',
            'subject_id' => $token->id,
        ]);

        $rotateResponse = $this->actingAs($user)
            ->post(route('settings.connectors.tokens.rotate', $token))
            ->assertRedirect(route('settings.connectors'));

        $rotatedPlainToken = $rotateResponse->baseResponse->getSession()->get('connector_plain_token');

        $this->assertIsString($rotatedPlainToken);
        $this->assertNotSame($plainToken, $rotatedPlainToken);
        $this->assertSame(ConnectorToken::hashToken($rotatedPlainToken), $token->refresh()->token_hash);
        $this->assertNull($token->revoked_at);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'ConnectorTokenRotated',
            'subject_id' => $token->id,
        ]);
    }

    public function test_connector_settings_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$otherUser, $otherAccount, $otherBrand] = $this->tenantWithRole('owner');
        $this->seed(ConnectorCatalogSeeder::class);
        $version = ConnectorVersion::query()->firstOrFail();

        ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'Visible Connector',
            'status' => 'active',
            'enabled_capabilities' => ['health_check'],
        ]);
        $hidden = ConnectorInstallation::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $otherUser->id,
            'name' => 'Hidden Connector',
            'status' => 'active',
            'enabled_capabilities' => ['health_check'],
        ]);

        $this->actingAs($user)
            ->get(route('settings.connectors'))
            ->assertOk()
            ->assertSee('Visible Connector')
            ->assertDontSee('Hidden Connector');

        $this->actingAs($user)
            ->patch(route('settings.connectors.update', $hidden), [
                'status' => 'disabled',
            ])
            ->assertNotFound();
    }

    public function test_connector_routes_are_module_gated(): void
    {
        [$user, $account] = $this->tenantWithRole('owner');
        $connectorsModuleId = Module::query()->where('key', 'connectors')->value('id');
        $account->subscriptionModules()->where('module_id', $connectorsModuleId)->update(['status' => 'canceled']);

        $this->actingAs($user)
            ->get(route('settings.connectors'))
            ->assertForbidden();
    }

    public function test_connector_log_keeps_account_scope_for_installation_events(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->seed(ConnectorCatalogSeeder::class);
        $version = ConnectorVersion::query()->firstOrFail();
        $installation = ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'Health Connector',
            'status' => 'active',
            'enabled_capabilities' => ['health_check'],
        ]);

        ConnectorLog::query()->create([
            'connector_installation_id' => $installation->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event' => 'connector.health_checked',
            'status' => 'ok',
            'occurred_at' => now(),
        ]);

        $this->assertSame(1, ConnectorLog::query()->where('account_id', $account->id)->count());
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
