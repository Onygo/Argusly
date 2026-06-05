<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_configuration_does_not_reference_legacy_app_admin_routes(): void
    {
        $routes = collect(config('navigation.app'))
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->flatMap(fn (array $item) => collect([$item])->merge($item['children'] ?? []))
            ->pluck('route')
            ->filter()
            ->values();

        $this->assertFalse(
            $routes->contains(fn (string $route) => str_starts_with($route, 'app.admin.')),
            'Navigation still contains legacy app.admin.* route names.',
        );
    }

    public function test_tenant_settings_navigation_does_not_expose_platform_admin_routes(): void
    {
        [$user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk()
            ->assertSee('Domain Events')
            ->assertDontSee('Admin Control Center')
            ->assertDontSee('Queue Monitor')
            ->assertDontSee('Source Syncs')
            ->assertDontSee('app.admin.', false);
    }

    public function test_platform_settings_navigation_exposes_existing_admin_routes(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Admin Control Center')
            ->assertSee('Platform Operations')
            ->assertSee('Queue Monitor')
            ->assertSee('Developer Tools')
            ->assertSee(route('admin.platform.queues'), false)
            ->assertDontSee('app.admin.', false);
    }

    public function test_account_admin_cannot_access_platform_operations_or_ai_admin_pages(): void
    {
        [$user] = $this->tenantUser('admin');

        $this->actingAs($user)
            ->get(route('admin.platform.queues'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.llm-requests'))
            ->assertForbidden();
    }

    public function test_foundation_policies_keep_global_platform_resources_platform_only(): void
    {
        [$tenantAdmin, $account, $brand] = $this->tenantUser('owner');
        $platformAdmin = $this->platformAdmin();
        $role = Role::query()->where('name', 'admin')->firstOrFail();
        $permission = Permission::query()->where('name', 'manage_account')->firstOrFail();
        $module = Module::query()->where('key', 'core')->firstOrFail();
        $subscription = Subscription::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertTrue($tenantAdmin->can('update', $account));
        $this->assertTrue($tenantAdmin->can('view', $account));
        $this->assertTrue($tenantAdmin->can('update', $brand));
        $this->assertFalse($tenantAdmin->can('delete', $brand));
        $this->assertFalse($tenantAdmin->can('update', $role));
        $this->assertFalse($tenantAdmin->can('update', $permission));
        $this->assertFalse($tenantAdmin->can('update', $module));
        $this->assertTrue($tenantAdmin->can('view', $subscription));
        $this->assertFalse($tenantAdmin->can('update', $subscription));

        $this->assertTrue($platformAdmin->can('update', $account));
        $this->assertTrue($platformAdmin->can('delete', $brand));
        $this->assertTrue($platformAdmin->can('update', $role));
        $this->assertTrue($platformAdmin->can('update', $permission));
        $this->assertTrue($platformAdmin->can('update', $module));
        $this->assertTrue($platformAdmin->can('update', $subscription));
    }

    public function test_feature_entitlement_override_grants_access_without_activating_module_gate(): void
    {
        [$user, $account] = $this->tenantUser('owner', 'starter_monthly');

        $this->assertFalse(app(\App\Services\FeatureGate::class)->hasAccess($account, 'agentic_social'));

        $account->entitlements()->create([
            'feature' => 'agentic_social',
            'enabled' => true,
            'starts_at' => now()->subMinute(),
        ]);

        $this->assertTrue(app(\App\Services\FeatureGate::class)->hasAccess($account, 'agentic_social'));

        $this->actingAs($user)
            ->get(route('app.automations'))
            ->assertForbidden();
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $plan = 'growth_monthly'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => "Tenant {$roleName}", 'slug' => "tenant-{$roleName}-".uniqid()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => "Brand {$roleName}", 'slug' => "brand-{$roleName}-".uniqid()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, $plan);

        return [$user, $account, $brand];
    }

    private function platformAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
