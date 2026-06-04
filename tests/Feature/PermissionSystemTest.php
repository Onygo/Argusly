<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_roles_grant_configured_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'editor')->firstOrFail();

        $user->roles()->attach($role);

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->userCan($user, 'edit_content'));
        $this->assertFalse($permissions->userCan($user, 'manage_billing'));
    }

    public function test_owner_role_grants_all_known_permissions_without_name_checks(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->roles()->attach($role);

        $this->assertTrue(Gate::forUser($user)->allows('manage_billing'));
        $this->assertTrue(Gate::forUser($user)->allows('run_agents'));
    }

    public function test_global_all_permissions_role_bypasses_tenant_module_requirements(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();

        $user->roles()->attach($role);

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->userHasGlobalAllPermissionsRole($user));
        $this->assertTrue($permissions->userCan($user, 'view_dashboard', [
            'account_id' => 123,
            'brand_id' => 456,
        ]));
    }

    public function test_roles_can_be_scoped_to_accounts_and_brands(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'viewer')->firstOrFail();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand', 'slug' => 'brand']);
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other', 'slug' => 'other']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $user->roleAssignments()->create([
            'role_id' => $role->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->userCan($user, 'view_dashboard', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]));

        $this->assertFalse($permissions->userCan($user, 'view_dashboard', [
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
        ]));
    }

    public function test_permission_requires_membership_for_requested_account_context(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $outsiderAccount = Account::query()->create(['name' => 'Outsider', 'slug' => 'outsider']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(SubscriptionService::class)->activatePlan($outsiderAccount, 'starter_monthly');

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->userCan($user, 'view_dashboard', ['account_id' => $account->id]));
        $this->assertFalse($permissions->userCan($user, 'view_dashboard', ['account_id' => $outsiderAccount->id]));
    }

    public function test_permission_requires_active_subscription_module_for_account_context(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $permissions = app(PermissionService::class);

        $this->assertTrue($permissions->userCan($user, 'view_content', ['account_id' => $account->id]));
        $this->assertFalse($permissions->userCan($user, 'manage_social', ['account_id' => $account->id]));
    }

    public function test_permission_middleware_blocks_missing_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Route::middleware(['web', 'permission:manage_billing'])->get('/_permission-test', fn () => 'ok');

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'viewer')->firstOrFail();

        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get('/_permission-test')
            ->assertForbidden();
    }

    public function test_user_policy_uses_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $target = User::factory()->create();
        $role = Role::query()->where('name', 'admin')->firstOrFail();

        $admin->roles()->attach($role);

        $this->assertTrue($admin->can('update', $target));
    }
}
