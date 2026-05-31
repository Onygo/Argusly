<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderRegistry;
use Database\Seeders\IntegrationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IntegrationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_registry_exposes_supported_runtime_providers(): void
    {
        $providers = app(ProviderRegistry::class)->definitions();

        $this->assertTrue($providers->has('linkedin'));
        $this->assertTrue($providers->has('google'));
        $this->assertTrue($providers->has('wordpress'));
        $this->assertTrue($providers->has('laravel'));
        $this->assertTrue($providers->has('meta'));
        $this->assertTrue($providers->has('x'));
        $this->assertTrue($providers->has('youtube'));
        $this->assertSame('api_key', $providers->get('laravel')['auth_type']);
    }

    public function test_integration_manager_registers_enables_and_disables_providers(): void
    {
        $manager = app(IntegrationManager::class);

        $manager->registerProviders();

        $this->assertSame(7, Integration::query()->count());
        $this->assertTrue($manager->providerEnabled('linkedin'));

        $manager->disableProvider('linkedin');
        $this->assertFalse($manager->providerEnabled('linkedin'));

        $manager->enableProvider('linkedin');
        $this->assertTrue($manager->providerEnabled('linkedin'));
    }

    public function test_connection_manager_creates_runtime_connection_without_oauth_or_api_calls(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        [$owner, $account, $brand] = $this->tenant();

        $connection = app(ConnectionManager::class)->createRuntimeConnection(
            owner: $owner,
            provider: 'laravel',
            name: 'Production Laravel',
            account: $account,
            brand: $brand,
        );

        $this->assertSame('active', $connection->status);
        $this->assertSame('Laravel', $connection->integration->name);
        $this->assertTrue($connection->metadata['runtime']);
        $this->assertFalse($connection->metadata['oauth_implemented']);
        $this->assertFalse($connection->metadata['api_calls_enabled']);
        $this->assertTrue(app(ConnectionManager::class)->canManage($owner, $connection, $account, $brand));
    }

    public function test_disabled_provider_cannot_create_runtime_connection(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        [$owner, $account, $brand] = $this->tenant();
        app(IntegrationManager::class)->disableProvider('google');

        $this->expectException(InvalidArgumentException::class);

        app(ConnectionManager::class)->createRuntimeConnection(
            owner: $owner,
            provider: 'google',
            name: 'Google Search Console',
            account: $account,
            brand: $brand,
        );
    }

    public function test_runtime_access_checks_are_account_and_brand_safe(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);

        [$owner, $account, $brand] = $this->tenant();
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $outsider = User::factory()->create();

        $connection = app(ConnectionManager::class)->createRuntimeConnection(
            owner: $owner,
            provider: 'wordpress',
            name: 'Brand WordPress',
            account: $account,
            brand: $brand,
        );

        $this->assertTrue(app(IntegrationManager::class)->checkAccountAccess($owner, $account));
        $this->assertTrue(app(IntegrationManager::class)->checkBrandAccess($owner, $brand));
        $this->assertFalse(app(IntegrationManager::class)->checkBrandAccess($owner, $otherBrand));
        $this->assertFalse(app(IntegrationManager::class)->checkAccountAccess($outsider, $account));
        $this->assertTrue(app(ConnectionManager::class)->canUse($owner, $connection, $account, $brand));
        $this->assertFalse(app(ConnectionManager::class)->canUse($owner, $connection, $account, $otherBrand));
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);

        $owner->accounts()->attach($account, ['status' => 'active']);
        $owner->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        return [$owner, $account, $brand];
    }
}
