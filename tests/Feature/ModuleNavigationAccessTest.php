<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleNavigationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_only_shows_active_modules_with_user_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Growth Account', 'slug' => 'growth-account']);
        $role = Role::query()->where('name', 'editor')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Intelligence')
            ->assertSee('Visibility')
            ->assertSee('Research')
            ->assertSee('Competitive Intelligence')
            ->assertSee('Content')
            ->assertSee('Campaigns')
            ->assertSee('Reporting')
            ->assertDontSee('Automations')
            ->assertDontSee('Settings');
    }

    public function test_navigation_hides_inactive_module_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Starter Account', 'slug' => 'starter-account']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Visibility')
            ->assertSee('Content')
            ->assertDontSee('Competitors')
            ->assertDontSee('Campaigns')
            ->assertDontSee('Automations');
    }

    public function test_direct_url_access_is_blocked_when_module_is_inactive(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Starter Account', 'slug' => 'starter-account']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $this->actingAs($user)
            ->get(route('app.campaigns'))
            ->assertForbidden();
    }

    public function test_direct_url_access_is_blocked_when_user_lacks_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Growth Account', 'slug' => 'growth-account']);
        $role = Role::query()->where('name', 'viewer')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        $this->actingAs($user)
            ->get(route('app.settings'))
            ->assertForbidden();
    }

    public function test_automations_route_allows_either_agentic_module(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Starter Account', 'slug' => 'starter-account']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(SubscriptionService::class)->activateModule($account, 'agentic_social');

        $this->actingAs($user)
            ->get(route('app.automations'))
            ->assertOk()
            ->assertSee('Automations');
    }
}
