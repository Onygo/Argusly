<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Integrations\IntegrationPermissionService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_dashboard_requests_redirect_to_login_route(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_dashboard_uses_current_tenant_context(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Agency', 'slug' => 'alpha-agency']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Main', 'slug' => 'alpha-main', 'domain' => 'alpha.example']);
        $otherAccount = Account::query()->create(['name' => 'Beta Group', 'slug' => 'beta-group']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $owner->accounts()->attach($account, ['status' => 'active']);
        $owner->accounts()->attach($otherAccount, ['status' => 'active']);

        $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();
        $editorRole = Role::query()->where('name', 'editor')->firstOrFail();

        $user->roles()->attach($ownerRole, ['account_id' => $account->id]);
        $user->roles()->attach($editorRole, ['account_id' => $account->id, 'brand_id' => $brand->id]);

        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');
        app(SubscriptionService::class)->activatePlan($otherAccount, 'scale_monthly');

        $visibleConnection = app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'google',
            name: 'Alpha Google',
            account: $account,
        );
        app(IntegrationPermissionService::class)->shareWithAccount($visibleConnection, $account, $owner);

        app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $owner,
            integration: 'linkedin',
            name: 'Beta LinkedIn',
            account: $otherAccount,
        );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Alpha Agency')
            ->assertSee('Alpha Main')
            ->assertSee('Owner')
            ->assertSee('Editor')
            ->assertSee('Visibility')
            ->assertSee('Connected integrations')
            ->assertSee('>1<', false)
            ->assertDontSee('Beta Group')
            ->assertDontSee('Beta LinkedIn');
    }

    public function test_dashboard_shows_empty_states_for_missing_brand_modules_integrations_and_activity(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Empty Account', 'slug' => 'empty-account']);
        $role = Role::query()->where('name', 'viewer')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        $plan = Plan::query()->where('key', 'starter_monthly')->firstOrFail();
        $core = Module::query()->where('key', 'core')->firstOrFail();
        $subscription = $account->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 9900,
        ]);
        $subscription->modules()->create([
            'account_id' => $account->id,
            'module_id' => $core->id,
            'status' => 'active',
        ]);
        ActivityLog::query()->delete();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Empty Account')
            ->assertSee('No brand selected')
            ->assertSee('No integrations connected')
            ->assertSee('No activity yet')
            ->assertSee('Intelligence feed placeholder');
    }
}
