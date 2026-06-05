<?php

namespace Tests\Feature;

use App\Mail\PilotSignupFollowUp;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorManifest;
use App\Models\ConnectorVersion;
use App\Models\ContentAsset;
use App\Models\CreditBalance;
use App\Models\CreditCostCatalog;
use App\Models\DomainEvent;
use App\Models\GraphNode;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmRequest;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LlmProviderSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        [$adminAccount, $adminBrand] = $this->tenant('Admin Tenant', 'Admin Brand');

        $this->actingAs($admin)
            ->withSession([
                'tenant.current_account_id' => $adminAccount->id,
                'tenant.current_brand_id' => $adminBrand->id,
            ])
            ->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('impersonator_user_id'));
        $this->assertSame($admin->name, session('impersonator_user_name'));
        $this->assertNull(session('tenant.current_account_id'));
        $this->assertNull(session('tenant.current_brand_id'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.user.impersonated']);

        session([
            'tenant.current_account_id' => $adminAccount->id,
            'tenant.current_brand_id' => $adminBrand->id,
        ]);

        $this->post(route('impersonation.stop'))
            ->assertRedirect(route('admin.users'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_user_id'));
        $this->assertNull(session('impersonated_user_id'));
        $this->assertNull(session('tenant.current_account_id'));
        $this->assertNull(session('tenant.current_brand_id'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.user.impersonation_stopped']);
    }

    public function test_active_impersonation_cannot_start_nested_platform_impersonation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $impersonator = $this->platformAdmin();
        $current = $this->platformAdmin();
        $target = User::factory()->create(['name' => 'Nested Target']);

        $this->actingAs($current)
            ->withSession([
                'impersonator_user_id' => $impersonator->id,
                'impersonated_user_id' => $current->id,
                'impersonation_scope' => 'platform',
            ])
            ->post(route('admin.users.impersonate', $target))
            ->assertForbidden();

        $this->assertAuthenticatedAs($current);
    }

    public function test_admin_impersonation_actions_are_hidden_while_impersonating(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $impersonator = $this->platformAdmin();
        $current = $this->platformAdmin();
        $target = User::factory()->create(['name' => 'Hidden Target']);

        $this->actingAs($current)
            ->withSession([
                'impersonator_user_id' => $impersonator->id,
                'impersonated_user_id' => $current->id,
                'impersonation_scope' => 'platform',
            ])
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Impersonation active')
            ->assertDontSee(route('admin.users.impersonate', $target), false);
    }

    public function test_admin_user_surfaces_include_impersonation_actions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account] = $this->tenant();
        $admin = $this->platformAdmin();
        $target = User::factory()->create(['name' => 'Customer User']);

        Membership::query()->create([
            'account_id' => $account->id,
            'user_id' => $target->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Customer User')
            ->assertSee(route('admin.users.impersonate', $target), false);

        $this->actingAs($admin)
            ->get(route('admin.accounts.show', $account))
            ->assertOk()
            ->assertSee('Customer User')
            ->assertSee(route('admin.users.impersonate', $target), false);
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

    public function test_admin_can_manage_credit_cost_catalog_and_overrides(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $brand] = $this->tenant('Credit Account', 'Credit Brand');
        $catalog = CreditCostCatalog::query()->where('code', 'blog_generation')->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.credit-costs'))
            ->assertOk()
            ->assertSee('Credit Cost Catalog')
            ->assertSee('blog_generation')
            ->assertSee('Create Override');

        $this->actingAs($this->platformAdmin())
            ->put(route('admin.credit-costs.update', $catalog), [
                'name' => 'Blog Generation',
                'description' => 'Updated catalog description',
                'category' => 'content',
                'default_cost' => 101,
                'minimum_cost' => null,
                'maximum_cost' => null,
                'cost_type' => 'fixed',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.credit-costs.overrides.store'), [
                'credit_cost_catalog_id' => $catalog->id,
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'override_cost' => 55,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('credit_cost_catalog', ['id' => $catalog->id, 'default_cost' => 101]);
        $this->assertDatabaseHas('credit_cost_overrides', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'override_cost' => 55,
        ]);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'CreditOverrideCreated']);
    }

    public function test_admin_can_inspect_llm_requests(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant('LLM Account', 'LLM Brand');
        LlmRequest::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'purpose' => 'content_generation',
            'status' => 'completed',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'credits_charged' => 100,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.llm-requests'))
            ->assertOk()
            ->assertSee('LLM requests')
            ->assertSee('LLM Account')
            ->assertSee('openai')
            ->assertSee('gpt-4.1-mini')
            ->assertSee('content_generation');
    }

    public function test_admin_can_manage_llm_catalog_and_global_defaults_without_exposing_api_keys(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LlmProviderSeeder::class);

        $originalEnv = env('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=sk-admin-secret');
        $_ENV['OPENAI_API_KEY'] = 'sk-admin-secret';
        $_SERVER['OPENAI_API_KEY'] = 'sk-admin-secret';

        try {
            $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
            $model = LlmModel::query()->where('provider_id', $openai->id)->where('model', 'gpt-4.1-mini')->firstOrFail();

            $this->actingAs($this->platformAdmin())
                ->get(route('admin.llm'))
                ->assertOk()
                ->assertSee('LLM settings')
                ->assertSee('Global defaults')
                ->assertSee('OpenAI')
                ->assertDontSee('sk-admin-secret');

            $this->actingAs($this->platformAdmin())
                ->patch(route('admin.llm.update'), [
                    'default_provider_id' => $openai->id,
                    'default_model_id' => $model->id,
                    'fallback_provider_id' => $openai->id,
                    'fallback_model_id' => $model->id,
                    'temperature' => '0.40',
                    'max_tokens' => 3000,
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('llm_settings', [
                'account_id' => null,
                'brand_id' => null,
                'default_provider_id' => $openai->id,
                'default_model_id' => $model->id,
                'fallback_provider_id' => $openai->id,
                'fallback_model_id' => $model->id,
                'max_tokens' => 3000,
            ]);

            $this->actingAs($this->platformAdmin())
                ->patch(route('admin.llm.providers.update', $openai), ['status' => 'inactive'])
                ->assertRedirect();
            $this->assertSame('inactive', $openai->refresh()->status);

            $this->actingAs($this->platformAdmin())
                ->patch(route('admin.llm.models.update', $model), ['status' => 'inactive'])
                ->assertRedirect();
            $this->assertSame('inactive', $model->refresh()->status);

            $this->actingAs($this->platformAdmin())
                ->get(route('admin.llm.providers'))
                ->assertOk()
                ->assertSee('OPENAI_API_KEY')
                ->assertDontSee('sk-admin-secret');

            $this->actingAs($this->platformAdmin())
                ->get(route('admin.llm.models'))
                ->assertOk()
                ->assertSee('LLM models')
                ->assertSee('GPT-4.1 mini');
        } finally {
            if ($originalEnv === null) {
                putenv('OPENAI_API_KEY');
                unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
            } else {
                putenv("OPENAI_API_KEY={$originalEnv}");
                $_ENV['OPENAI_API_KEY'] = $originalEnv;
                $_SERVER['OPENAI_API_KEY'] = $originalEnv;
            }
        }
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
            ->assertSee('Review contact requests')
            ->assertSee(route('admin.overview'), false);

        $this->get(route('admin.pilot-signups'))
            ->assertOk()
            ->assertSee('Pilot Requests')
            ->assertSee('More leads')
            ->assertSee('Send follow-up')
            ->assertSee('Activate pilot')
            ->assertSee('Activate pilot to create account, brand, user access, modules and credits automatically.');
    }

    public function test_platform_admin_can_review_contact_requests(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        DB::table('contact_requests')->insert([
            'name' => 'RobertJoicy',
            'email' => 'zekisuquc419@gmail.com',
            'company' => 'google',
            'topic' => 'other',
            'message' => 'Hola, volia saber el seu preu.',
            'status' => 'unqualified',
            'metadata' => json_encode([
                'source' => 'marketing_contact',
                'ip' => '127.0.0.1',
                'lead_quality' => 'Low',
                'lead_score' => 0,
                'lead_signals' => ['Personal email domain', 'Generic large-company claim'],
                'suggested_reply' => 'Hi, could you share more details?',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.contact-requests'))
            ->assertOk()
            ->assertSee('Contact Requests')
            ->assertSee('RobertJoicy')
            ->assertSee('zekisuquc419@gmail.com')
            ->assertSee('Hola, volia saber el seu preu.')
            ->assertSee('Personal email domain')
            ->assertSee('https://mail.google.com/mail/', false)
            ->assertSee('Mark contacted')
            ->assertSee('Mark unqualified')
            ->assertSee('Mark spam');
    }

    public function test_platform_admin_can_update_contact_request_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $id = DB::table('contact_requests')->insertGetId([
            'name' => 'Jane Contact',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'topic' => 'sales',
            'message' => 'We want pricing.',
            'status' => 'new',
            'metadata' => json_encode(['source' => 'marketing_contact']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.contact-requests.update', $id), ['status' => 'contacted'])
            ->assertRedirect();

        $this->assertDatabaseHas('contact_requests', [
            'id' => $id,
            'status' => 'contacted',
            'handled_by' => $admin->id,
        ]);

        $this->assertNotNull(DB::table('contact_requests')->where('id', $id)->value('handled_at'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.contact_request.updated']);
    }

    public function test_platform_admin_can_mark_contact_request_as_spam(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $id = DB::table('contact_requests')->insertGetId([
            'name' => 'Spam Lead',
            'email' => 'spam@example.com',
            'company' => null,
            'topic' => 'other',
            'message' => 'Generic outreach.',
            'status' => 'new',
            'metadata' => json_encode(['source' => 'marketing_contact']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.contact-requests.update', $id), ['status' => 'spam'])
            ->assertRedirect();

        $this->assertDatabaseHas('contact_requests', [
            'id' => $id,
            'status' => 'spam',
            'handled_by' => $admin->id,
        ]);
    }

    public function test_platform_admin_can_update_pilot_request_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $id = DB::table('pilot_signups')->insertGetId([
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'website' => 'https://example.com',
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
        $this->assertDatabaseHas('accounts', ['name' => 'Example Inc', 'slug' => 'example-inc']);
        $this->assertDatabaseHas('brands', ['name' => 'Example Inc', 'domain' => 'example.com']);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

        $account = Account::query()->where('slug', 'example-inc')->firstOrFail();
        $brand = Brand::query()->where('account_id', $account->id)->where('slug', 'example-inc')->firstOrFail();
        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

        $this->assertDatabaseHas('memberships', ['account_id' => $account->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseHas('brand_memberships', ['account_id' => $account->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseHas('subscription_modules', ['account_id' => $account->id, 'status' => 'active']);
        $this->assertDatabaseHas('credit_balances', ['account_id' => $account->id, 'balance' => 1000]);
        $this->assertNotNull(DB::table('pilot_signups')->where('id', $id)->value('reviewed_at'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.pilot_signup.activated']);

        $this->actingAs($admin)
            ->patch(route('admin.pilot-signups.update', $id), ['status' => 'activated'])
            ->assertRedirect();

        $this->assertDatabaseHas('credit_balances', ['account_id' => $account->id, 'balance' => 1000]);
    }

    public function test_platform_admin_can_send_pilot_follow_up(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();

        $id = DB::table('pilot_signups')->insertGetId([
            'name' => 'Jane Pilot',
            'email' => 'jane@example.com',
            'company' => 'Example Inc',
            'goal' => 'More leads',
            'status' => 'pending',
            'metadata' => json_encode(['source' => 'marketing_signup']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('admin.pilot-signups.follow-up', $id))
            ->assertRedirect();

        Mail::assertSent(PilotSignupFollowUp::class, fn (PilotSignupFollowUp $mail) => $mail->hasTo('jane@example.com'));
        $this->assertDatabaseHas('pilot_signups', [
            'id' => $id,
            'status' => 'contacted',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'admin.pilot_signup.follow_up_sent']);
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

    public function test_global_platform_admin_can_access_dashboard_without_tenant_bootstrap(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->platformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Platform administration')
            ->assertSee('Customer operations')
            ->assertSee('System operations')
            ->assertDontSee('Brand Profile Completeness')
            ->assertDontSee('Current brand')
            ->assertDontSee('Visibility monitoring')
            ->assertDontSee('Open Intelligence')
            ->assertDontSee('Search content');
    }

    public function test_platform_admin_profile_uses_compact_platform_settings_navigation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->platformAdmin())
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Account details')
            ->assertSee('Admin Control Center')
            ->assertSee('Pilot Requests')
            ->assertSee('Contact Requests')
            ->assertDontSee('Knowledge Center')
            ->assertDontSee('Publishing Infrastructure');
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
