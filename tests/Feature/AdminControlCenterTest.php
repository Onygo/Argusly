<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorManifest;
use App\Models\ConnectorVersion;
use App\Models\ContentAsset;
use App\Models\CreditBalance;
use App\Models\DomainEvent;
use App\Models\GraphNode;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\SubscriptionModule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminControlCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin_area(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.overview'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_access_admin_area(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('Admin Control Center')
            ->assertSee(route('admin.overview'), false);
    }

    public function test_account_admin_cannot_access_global_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $account = Account::query()->create(['name' => 'Tenant', 'slug' => 'tenant']);
        $user = User::factory()->create();
        $role = Role::query()->where('name', 'admin')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertForbidden();
    }

    public function test_admin_can_create_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.accounts.store'), [
                'name' => 'Acme Corp',
                'slug' => 'acme-corp',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounts', ['slug' => 'acme-corp', 'status' => 'active']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.account.created']);
    }

    public function test_admin_can_create_brand(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $account = Account::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.brands.store'), [
                'account_id' => $account->id,
                'name' => 'Acme Brand',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('brands', ['account_id' => $account->id, 'slug' => 'acme-brand']);
    }

    public function test_admin_can_assign_user_to_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $account = Account::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $target = User::factory()->create();
        $role = Role::query()->where('name', 'viewer')->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.memberships.accounts.store'), [
                'user_id' => $target->id,
                'account_id' => $account->id,
                'role_id' => $role->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('memberships', ['user_id' => $target->id, 'account_id' => $account->id, 'status' => 'active']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $target->id, 'account_id' => $account->id, 'role_id' => $role->id]);
    }

    public function test_platform_admin_can_impersonate_and_stop_impersonating(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->platformAdmin();
        $target = User::factory()->create(['name' => 'Customer User']);

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('impersonator_user_id'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.user.impersonated']);

        $this->post(route('impersonation.stop'))
            ->assertRedirect(route('admin.users'));

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.user.impersonation_stopped']);
    }

    public function test_admin_can_enable_module(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $account = Account::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $module = Module::query()->create(['key' => 'core', 'name' => 'Core']);

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.modules.enable'), [
                'account_id' => $account->id,
                'module_id' => $module->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscription_modules', ['account_id' => $account->id, 'module_id' => $module->id, 'status' => 'active']);
    }

    public function test_admin_can_assign_credits(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $account = Account::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.credits.adjust'), [
                'account_id' => $account->id,
                'amount' => 250,
                'reason' => 'Pilot grant',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('credit_balances', ['account_id' => $account->id, 'balance' => 250]);
        $this->assertDatabaseHas('domain_events', ['account_id' => $account->id, 'event_type' => 'CreditBalanceAdjusted']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.credits.adjusted']);
    }

    public function test_admin_can_inspect_connector(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant();
        $manifest = ConnectorManifest::query()->create(['key' => 'wordpress', 'type' => 'wordpress', 'name' => 'WordPress']);
        $version = ConnectorVersion::query()->create(['connector_manifest_id' => $manifest->id, 'version' => '1.0.0']);
        ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'connector_manifest_id' => $manifest->id,
            'connector_version_id' => $version->id,
            'name' => 'WordPress Site',
            'status' => 'active',
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.connectors'))
            ->assertOk()
            ->assertSee('WordPress Site');
    }

    public function test_admin_can_inspect_failed_publishing_action(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant();
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Failed Article']);
        $channel = PublishingChannel::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'wordpress',
            'name' => 'Website',
            'status' => 'active',
        ]);
        PublishingAction::factory()->forContentAsset($asset)->forPublishingChannel($channel)->create([
            'status' => 'failed',
            'error_message' => 'Remote endpoint rejected payload.',
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.publishing-actions'))
            ->assertOk()
            ->assertSee('Remote endpoint rejected payload.');
    }

    public function test_admin_can_inspect_graph_nodes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant();
        GraphNode::query()->updateOrCreate(
            ['account_id' => $account->id, 'source_type' => Brand::class, 'source_id' => $brand->id],
            ['brand_id' => $brand->id, 'node_type' => 'brand', 'label' => 'Graph Brand'],
        );

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.developer-tools.show', ['graph-nodes']))
            ->assertOk()
            ->assertSee('Graph Brand');
    }

    public function test_platform_admin_can_review_pilot_requests(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        DB::table('pilot_signups')->insert([
            'name' => 'Ricardo Hagens',
            'email' => 'ricardo@example.com',
            'company' => 'Onygo',
            'website' => 'https://onygo.nl',
            'role' => 'Founder',
            'goal' => 'More leads',
            'status' => 'pending',
            'metadata' => json_encode(['source' => 'marketing_signup', 'ip' => '127.0.0.1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('Pilot Requests')
            ->assertSee('Onygo')
            ->assertSee('Open queue')
            ->assertSee(route('admin.overview'), false);

        $this->get(route('admin.pilot-signups'))
            ->assertOk()
            ->assertSee('Pilot Requests')
            ->assertSee('More leads')
            ->assertSee('Send follow-up')
            ->assertSee('Activate pilot')
            ->assertSee('Grant pilot credits');
    }

    public function test_platform_admin_can_update_pilot_request_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $id = DB::table('pilot_signups')->insertGetId([
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'status' => 'pending',
            'metadata' => json_encode(['source' => 'marketing_signup']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.pilot-signups.update', $id), ['status' => 'activated'])
            ->assertRedirect();

        $this->assertDatabaseHas('pilot_signups', [
            'id' => $id,
            'status' => 'activated',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertNotNull(DB::table('pilot_signups')->where('id', $id)->value('reviewed_at'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.pilot_signup.updated']);
    }

    public function test_account_detail_does_not_leak_other_tenant_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant('Acme', 'Acme Brand');
        [$otherAccount, $otherBrand] = $this->tenant('Other', 'Other Brand');
        CreditBalance::query()->create(['account_id' => $account->id, 'balance' => 10]);
        CreditBalance::query()->create(['account_id' => $otherAccount->id, 'balance' => 999]);
        DomainEvent::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'event_type' => 'BrandCreated',
            'subject_type' => Brand::class,
            'subject_id' => $otherBrand->id,
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.accounts.show', $account))
            ->assertOk()
            ->assertSee($brand->name)
            ->assertDontSee($otherBrand->name)
            ->assertDontSee('999');
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    /**
     * @return array{Account, Brand}
     */
    private function tenant(string $accountName = 'Tenant', string $brandName = 'Brand'): array
    {
        $account = Account::query()->create(['name' => $accountName, 'slug' => str($accountName)->slug()->toString().uniqid()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => $brandName, 'slug' => str($brandName)->slug()->toString().uniqid()]);

        return [$account, $brand];
    }
}
