<?php

namespace Tests\Feature;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\BrandMembership;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_logger_creates_tenant_scoped_activity_log(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand', 'slug' => 'brand']);

        ActivityLog::query()->delete();

        $log = app(ActivityLogger::class)->log(
            event: 'custom.event',
            description: 'Custom event happened.',
            account: $account,
            brand: $brand,
            user: $user,
            subject: $brand,
            properties: ['key' => 'value'],
        );

        $this->assertNotNull($log);
        $this->assertNotNull($log->uuid);
        $this->assertDatabaseHas('activity_logs', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'event' => 'custom.event',
            'description' => 'Custom event happened.',
        ]);
    }

    public function test_model_and_service_events_are_logged(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);

        Membership::query()->create(['user_id' => $user->id, 'account_id' => $account->id, 'status' => 'active']);
        BrandMembership::query()->create(['user_id' => $user->id, 'brand_id' => $brand->id, 'account_id' => $account->id, 'status' => 'active']);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(IntegrationConnectionService::class)->createOAuthConnection(
            owner: $user,
            integration: 'google',
            name: 'Google',
            account: $account,
        );

        $this->assertDatabaseHas('activity_logs', ['event' => 'account.created', 'account_id' => $account->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'brand.created', 'brand_id' => $brand->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.invited', 'account_id' => $account->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'membership.changed', 'brand_id' => $brand->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'module.activated', 'account_id' => $account->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'integration.connected', 'account_id' => $account->id, 'user_id' => $user->id]);
    }

    public function test_context_switch_and_auth_events_are_logged(): void
    {
        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Account', 'slug' => 'account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand', 'slug' => 'brand']);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        $this->actingAs($user);

        app(CurrentAccountContract::class)->switch($account, $user);
        app(CurrentBrandContract::class)->switch($brand, $user);
        Event::dispatch(new Login('web', $user, false));
        Event::dispatch(new Logout('web', $user));

        $this->assertDatabaseHas('activity_logs', ['event' => 'context.switched', 'account_id' => $account->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'context.switched', 'brand_id' => $brand->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_dashboard_recent_activity_is_tenant_and_brand_scoped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        ActivityLog::query()->delete();
        $module = Module::query()->where('key', 'core')->firstOrFail();

        app(ActivityLogger::class)->log('visible.account', 'Visible account activity.', $account, null, $user, $module);
        app(ActivityLogger::class)->log('visible.brand', 'Visible brand activity.', $account, $brand, $user, $brand);
        app(ActivityLogger::class)->log('hidden.brand', 'Hidden brand activity.', $account, $otherBrand, $user, $otherBrand);
        app(ActivityLogger::class)->log('hidden.account', 'Hidden account activity.', $otherAccount, null, $user, $otherAccount);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Visible account activity.')
            ->assertSee('Visible brand activity.')
            ->assertDontSee('Hidden brand activity.')
            ->assertDontSee('Hidden account activity.');
    }
}
