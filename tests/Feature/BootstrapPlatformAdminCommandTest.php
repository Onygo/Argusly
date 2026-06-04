<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootstrapPlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_admin_command_repairs_first_platform_admin_access(): void
    {
        $this->artisan('argusly:bootstrap-admin hello@argusly.com --password=secret')
            ->assertSuccessful();

        $user = User::query()->where('email', 'hello@argusly.com')->firstOrFail();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $account = Account::query()->where('slug', 'argusly')->firstOrFail();
        $brand = Brand::query()->where('account_id', $account->id)->where('slug', 'argusly')->firstOrFail();
        $core = Module::query()->where('key', 'core')->firstOrFail();

        $this->assertTrue((bool) $role->all_permissions);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'account_id' => null,
            'brand_id' => null,
        ]);
        $this->assertDatabaseHas('memberships', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('brand_memberships', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'status' => 'active',
        ]);

        $subscription = DB::table('subscriptions')
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertDatabaseHas('subscription_modules', [
            'subscription_id' => $subscription->id,
            'account_id' => $account->id,
            'module_id' => $core->id,
            'status' => 'active',
        ]);
    }
}
