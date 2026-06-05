<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorVersion;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\Module;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\ConnectorCatalogSeeder;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\LlmProviderSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_settings_show_current_account_fields(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $account->update(['settings' => ['timezone' => 'Europe/Amsterdam']]);

        $this->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk()
            ->assertSee('Account settings')
            ->assertSee($account->name)
            ->assertSee($account->slug)
            ->assertSee('Europe/Amsterdam')
            ->assertSee($brand->name);
    }

    public function test_brand_settings_are_scoped_to_current_account(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $brand->update([
            'description' => 'Primary brand profile.',
            'website_url' => 'https://alpha.example',
            'market' => 'Netherlands',
            'language' => 'nl',
        ]);
        Brand::query()->create([
            'account_id' => Account::query()->create(['name' => 'Other', 'slug' => 'other'])->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        $this->actingAs($user)
            ->get(route('settings.brands'))
            ->assertOk()
            ->assertSee('Primary brand profile.')
            ->assertSee('https://alpha.example')
            ->assertSee('Netherlands')
            ->assertSee(route('settings.brands.update', $brand), false)
            ->assertDontSee('Other Brand');
    }

    public function test_workspace_owner_can_update_account_settings(): void
    {
        [$user, $account] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->patch(route('settings.account.update'), [
                'name' => 'Argusly Workspace',
                'default_locale' => 'nl',
                'default_content_language' => 'en',
                'timezone' => 'Europe/Amsterdam',
            ])
            ->assertRedirect(route('settings.account'));

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'name' => 'Argusly Workspace',
            'default_locale' => 'nl',
            'default_content_language' => 'en',
        ]);
        $this->assertSame('Europe/Amsterdam', $account->fresh()->settings['timezone']);
    }

    public function test_workspace_owner_can_update_only_brands_inside_current_account(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $foreignAccount = Account::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignBrand = Brand::query()->create([
            'account_id' => $foreignAccount->id,
            'name' => 'Foreign Brand',
            'slug' => 'foreign-brand',
        ]);

        $this->actingAs($user)
            ->patch(route('settings.brands.update', $brand), [
                'name' => 'Updated Alpha',
                'slug' => 'updated-alpha',
                'domain' => 'updated.example',
                'website_url' => 'https://updated.example',
                'description' => 'Updated brand profile.',
                'market' => 'Benelux',
                'language' => 'nl',
                'default_content_language' => 'en',
                'enabled_content_languages' => 'en, nl',
                'status' => 'active',
            ])
            ->assertRedirect(route('settings.brands'));

        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'account_id' => $account->id,
            'name' => 'Updated Alpha',
            'slug' => 'updated-alpha',
            'domain' => 'updated.example',
            'website_url' => 'https://updated.example',
            'market' => 'Benelux',
        ]);
        $this->assertSame(['en', 'nl'], $brand->fresh()->enabled_content_languages);

        $this->actingAs($user)
            ->patch(route('settings.brands.update', $foreignBrand), [
                'name' => 'Should Not Update',
                'slug' => 'should-not-update',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_team_settings_show_account_and_brand_members_with_roles(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $member = User::factory()->create(['name' => 'Brand Editor']);
        $editor = Role::query()->where('name', 'editor')->firstOrFail();
        $otherAccount = Account::query()->create(['name' => 'Other Company', 'slug' => 'other-company']);
        $otherUser = User::factory()->create(['name' => 'Other Company User']);
        $assignable = User::factory()->create(['name' => 'Assignable Team Member']);

        $member->accounts()->attach($account, ['status' => 'active']);
        $member->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $member->roles()->attach($editor, ['account_id' => $account->id, 'brand_id' => $brand->id]);
        $assignable->accounts()->attach($account, ['status' => 'active']);
        $otherUser->accounts()->attach($otherAccount, ['status' => 'active']);

        $this->actingAs($user)
            ->get(route('settings.team'))
            ->assertOk()
            ->assertSee('Brand Editor')
            ->assertSee('Editor')
            ->assertSee(route('workspace.users.impersonate', $member), false)
            ->assertDontSee('Other Company User')
            ->assertSee('Add workspace member to brand');
    }

    public function test_workspace_owner_can_update_memberships_and_brand_roles(): void
    {
        [$owner, $account, $brand] = $this->tenantWithRole('owner');
        $member = User::factory()->create(['name' => 'Assignable Member']);
        $member->accounts()->attach($account, ['status' => 'active']);

        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $editor = Role::query()->where('name', 'editor')->firstOrFail();
        $membership = $member->memberships()->where('account_id', $account->id)->firstOrFail();

        $this->assertTrue($owner->can('update', $membership));

        $this->actingAs($owner)
            ->patch(route('settings.team.memberships.update', $membership), [
                'status' => 'active',
                'role_id' => $admin->id,
            ])
            ->assertRedirect(route('settings.team'));

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $member->id,
            'role_id' => $admin->id,
            'account_id' => $account->id,
            'brand_id' => null,
        ]);

        $this->actingAs($owner)
            ->post(route('settings.team.brand-memberships.store'), [
                'user_id' => $member->id,
                'role_id' => $editor->id,
            ])
            ->assertRedirect(route('settings.team'));

        $brandMembership = $member->brandMemberships()->where('brand_id', $brand->id)->firstOrFail();
        $this->assertDatabaseHas('brand_memberships', [
            'id' => $brandMembership->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $member->id,
            'role_id' => $editor->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('settings.team.brand-memberships.update', $brandMembership), [
                'status' => 'inactive',
                'role_id' => $admin->id,
            ])
            ->assertRedirect(route('settings.team'));

        $this->assertDatabaseHas('brand_memberships', [
            'id' => $brandMembership->id,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $member->id,
            'role_id' => $admin->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);
    }

    public function test_workspace_owner_can_impersonate_only_users_inside_own_account(): void
    {
        [$owner, $account] = $this->tenantWithRole('owner');
        $member = User::factory()->create(['name' => 'Account Colleague']);
        $foreign = User::factory()->create(['name' => 'Foreign User']);
        $foreignAccount = Account::query()->create(['name' => 'Foreign Company', 'slug' => 'foreign-company']);

        $member->accounts()->attach($account, ['status' => 'active']);
        $foreign->accounts()->attach($foreignAccount, ['status' => 'active']);

        $this->actingAs($owner)
            ->post(route('workspace.users.impersonate', $foreign))
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('workspace.users.impersonate', $member))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
        $this->assertSame($owner->id, session('impersonator_user_id'));
        $this->assertSame('workspace', session('impersonation_scope'));
        $this->assertSame($account->id, session('impersonation_account_id'));
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'workspace.user.impersonated',
            'account_id' => $account->id,
        ]);

        $this->post(route('impersonation.stop'))
            ->assertRedirect(route('settings.team'));

        $this->assertAuthenticatedAs($owner);
        $this->assertNull(session('impersonation_scope'));
        $this->assertNull(session('impersonation_account_id'));
    }

    public function test_active_impersonation_cannot_start_nested_workspace_impersonation(): void
    {
        [$owner, $account] = $this->tenantWithRole('owner');
        $current = User::factory()->create(['name' => 'Current Impersonated']);
        $target = User::factory()->create(['name' => 'Nested Workspace Target']);

        $current->accounts()->attach($account, ['status' => 'active']);
        $target->accounts()->attach($account, ['status' => 'active']);

        $this->actingAs($current)
            ->withSession([
                'impersonator_user_id' => $owner->id,
                'impersonated_user_id' => $current->id,
                'impersonation_scope' => 'workspace',
                'impersonation_account_id' => $account->id,
            ])
            ->post(route('workspace.users.impersonate', $target))
            ->assertForbidden();

        $this->assertAuthenticatedAs($current);
    }

    public function test_team_impersonation_actions_are_hidden_while_impersonating(): void
    {
        [$owner, $account] = $this->tenantWithRole('owner');
        $current = User::factory()->create(['name' => 'Current Impersonated']);
        $target = User::factory()->create(['name' => 'Hidden Team Target']);
        $role = Role::query()->where('name', 'admin')->firstOrFail();

        $current->accounts()->attach($account, ['status' => 'active']);
        $current->roles()->attach($role, ['account_id' => $account->id]);
        $target->accounts()->attach($account, ['status' => 'active']);

        $this->actingAs($current)
            ->withSession([
                'impersonator_user_id' => $owner->id,
                'impersonated_user_id' => $current->id,
                'impersonation_scope' => 'workspace',
                'impersonation_account_id' => $account->id,
            ])
            ->get(route('settings.team'))
            ->assertOk()
            ->assertSee('Impersonation active')
            ->assertDontSee(route('workspace.users.impersonate', $target), false);
    }

    public function test_modules_settings_show_active_and_inactive_modules(): void
    {
        [$user] = $this->tenantWithRole('owner', 'starter_monthly');

        $this->actingAs($user)
            ->get(route('settings.modules'))
            ->assertOk()
            ->assertSee('Core')
            ->assertSee('Active')
            ->assertSee('Agentic Social')
            ->assertSee('Inactive')
            ->assertSee('No payment integration yet');
    }

    public function test_tenant_admin_can_configure_account_and_brand_llm_defaults(): void
    {
        $this->seed(LlmProviderSeeder::class);
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $openai = LlmProvider::query()->where('provider', 'openai')->firstOrFail();
        $openaiModel = LlmModel::query()->where('provider_id', $openai->id)->where('model', 'gpt-4.1-mini')->firstOrFail();
        $anthropic = LlmProvider::query()->where('provider', 'anthropic')->firstOrFail();
        $anthropicModel = LlmModel::query()->where('provider_id', $anthropic->id)->where('model', 'claude-sonnet-4-20250514')->firstOrFail();

        $this->actingAs($user)
            ->get(route('settings.llm'))
            ->assertOk()
            ->assertSee('LLM settings')
            ->assertSee('Available providers')
            ->assertSee('OpenAI')
            ->assertSee('Claude Sonnet 4')
            ->assertDontSee('OPENAI_API_KEY');

        $this->actingAs($user)
            ->patch(route('settings.llm.update'), [
                'scope' => 'account',
                'default_provider_id' => $openai->id,
                'default_model_id' => $openaiModel->id,
                'temperature' => '0.30',
                'max_tokens' => 2500,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('llm_settings', [
            'account_id' => $account->id,
            'brand_id' => null,
            'default_provider_id' => $openai->id,
            'default_model_id' => $openaiModel->id,
            'max_tokens' => 2500,
        ]);

        $this->actingAs($user)
            ->patch(route('settings.llm.update'), [
                'scope' => 'brand',
                'default_provider_id' => $anthropic->id,
                'default_model_id' => $anthropicModel->id,
                'temperature' => '0.70',
                'max_tokens' => 5000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('llm_settings', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'default_provider_id' => $anthropic->id,
            'default_model_id' => $anthropicModel->id,
            'max_tokens' => 5000,
        ]);
    }

    public function test_integrations_settings_show_scoped_connections_and_google_oauth_status(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $integration = Integration::query()->where('key', 'google')->firstOrFail();

        IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Alpha Google',
            'status' => 'active',
        ]);
        IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Stale Google',
            'status' => 'expired',
            'metadata' => ['token_error_message' => 'Google token refresh failed.'],
        ]);
        IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => Account::query()->create(['name' => 'Other', 'slug' => 'other'])->id,
            'name' => 'Other Google',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('LinkedIn')
            ->assertSee('Manage LinkedIn')
            ->assertSee('Alpha Google')
            ->assertSee('Google OAuth')
            ->assertSee('Reconnect Google integration to restore GA4 and Search Console sync.')
            ->assertSee('Google token refresh failed.')
            ->assertSee('Connect Google')
            ->assertDontSee('Other Google');
    }

    public function test_linkedin_integration_settings_show_placeholders_permissions_and_scoped_profiles(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);
        config()->set('integrations.providers.linkedin.oauth.client_id', null);
        config()->set('integrations.providers.linkedin.oauth.client_secret', null);
        config()->set('integrations.providers.linkedin.oauth.redirect_uri', null);

        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $integration = Integration::query()->where('key', 'linkedin')->firstOrFail();

        IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Maria LinkedIn',
            'status' => 'active',
            'provider_account_id' => 'linkedin-person-123',
            'provider_account_name' => 'Maria LinkedIn',
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            'metadata' => ['linkedin_account_type' => 'personal_profile'],
        ]);
        IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => Account::query()->create(['name' => 'Other', 'slug' => 'other-linkedin'])->id,
            'name' => 'Hidden LinkedIn',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('settings.integrations.linkedin'))
            ->assertOk()
            ->assertSee('LinkedIn integration')
            ->assertSee('OAuth credentials not configured yet')
            ->assertSee('Connect LinkedIn')
            ->assertSee('Pages')
            ->assertSee('Organization publishing requires LinkedIn approval')
            ->assertSee('Placeholder list of pages')
            ->assertSee('Connected profiles')
            ->assertSee('Maria LinkedIn')
            ->assertSee('Disconnect')
            ->assertSee('openid')
            ->assertSee('profile')
            ->assertSee('email')
            ->assertSee('w_member_social')
            ->assertSee('r_member_social')
            ->assertSee('r_organization_social')
            ->assertSee('w_organization_social')
            ->assertDontSee('Hidden LinkedIn');
    }

    public function test_social_profile_settings_show_accessible_profiles_and_permissions(): void
    {
        $this->seed(IntegrationCatalogSeeder::class);
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $integration = Integration::query()->where('key', 'linkedin')->firstOrFail();
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => null,
            'brand_id' => null,
            'name' => 'Ricardo LinkedIn',
            'status' => 'active',
            'provider_account_id' => 'linkedin-ricardo',
        ]);
        $profile = SocialProfile::query()->create([
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'linkedin-ricardo',
            'display_name' => 'Ricardo LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);
        $profile->permissions()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'can_view' => true,
            'can_prepare' => true,
            'can_schedule' => false,
            'can_publish' => false,
        ]);
        SocialProfile::query()->create([
            'integration_connection_id' => $connection->id,
            'owner_user_id' => User::factory()->create()->id,
            'provider' => 'linkedin',
            'display_name' => 'Hidden LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->get(route('settings.social-profiles'))
            ->assertOk()
            ->assertSee('Social profiles')
            ->assertSee('Ricardo LinkedIn')
            ->assertSee('Prepare')
            ->assertSee('Publish')
            ->assertSee('Sharing editor placeholder')
            ->assertDontSee('Hidden LinkedIn');
    }

    public function test_properties_settings_are_brand_scoped_and_content_module_gated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        Property::factory()->forBrand($brand)->create([
            'name' => 'Visible Website',
            'type' => 'website',
            'url' => 'https://visible.example',
        ]);
        Property::factory()->forBrand($otherBrand)->create([
            'name' => 'Hidden Website',
            'type' => 'blog',
            'url' => 'https://hidden.example',
        ]);

        $this->actingAs($user)
            ->get(route('settings.properties'))
            ->assertOk()
            ->assertSee('Visible Website')
            ->assertSee('https://visible.example')
            ->assertDontSee('Hidden Website');

        [$ownerNoContent] = $this->tenantWithRole('owner', 'core_only');

        $this->actingAs($ownerNoContent)
            ->get(route('settings.properties'))
            ->assertForbidden();
    }

    public function test_workspace_owner_can_create_and_update_only_current_brand_properties(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $foreignBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Foreign Brand',
            'slug' => 'foreign-brand',
        ]);
        $foreignProperty = Property::factory()->forBrand($foreignBrand)->create([
            'name' => 'Foreign Property',
        ]);

        $this->actingAs($user)
            ->post(route('settings.properties.store'), [
                'name' => 'Knowledge Website',
                'type' => 'website',
                'url' => 'https://knowledge.example',
                'primary_language' => 'en',
                'status' => 'active',
            ])
            ->assertRedirect(route('settings.properties'));

        $property = Property::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('name', 'Knowledge Website')
            ->firstOrFail();

        $this->actingAs($user)
            ->patch(route('settings.properties.update', $property), [
                'name' => 'Updated Knowledge Website',
                'type' => 'blog',
                'url' => 'https://knowledge.example/blog',
                'primary_language' => 'nl',
                'status' => 'inactive',
            ])
            ->assertRedirect(route('settings.properties'));

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'name' => 'Updated Knowledge Website',
            'type' => 'blog',
            'url' => 'https://knowledge.example/blog',
            'primary_language' => 'nl',
            'status' => 'inactive',
        ]);

        $this->actingAs($user)
            ->patch(route('settings.properties.update', $foreignProperty), [
                'name' => 'Should Not Update',
                'type' => 'website',
                'url' => 'https://blocked.example',
                'status' => 'active',
            ])
            ->assertNotFound();
    }

    public function test_channels_settings_are_brand_scoped_and_hide_credentials(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->seed(ConnectorCatalogSeeder::class);

        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $property = Property::factory()->forBrand($brand)->create(['name' => 'Main Website']);
        $version = ConnectorVersion::query()
            ->whereHas('manifest', fn ($query) => $query->where('type', 'wordpress'))
            ->firstOrFail();

        $channel = PublishingChannel::factory()->forProperty($property)->create([
            'provider' => 'wordpress',
            'name' => 'Visible WordPress',
            'status' => 'draft',
            'credentials' => ['token' => 'super-secret-token'],
        ]);
        ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'channel_id' => $channel->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'Production WordPress Connector',
            'status' => 'active',
            'enabled_capabilities' => ['publish_content', 'preview_url'],
            'last_health_check' => ['status' => 'ok', 'message' => 'Healthy'],
            'last_health_checked_at' => now()->subMinutes(5),
        ]);
        PublishingChannel::factory()->forBrand($otherBrand)->create([
            'provider' => 'linkedin',
            'name' => 'Hidden LinkedIn',
            'credentials' => ['token' => 'hidden-secret-token'],
        ]);

        $this->actingAs($user)
            ->get(route('settings.channels'))
            ->assertOk()
            ->assertSee('Visible WordPress')
            ->assertSee('Main Website')
            ->assertSee('Production WordPress Connector')
            ->assertSee('Connector ready')
            ->assertSee('Publish Content')
            ->assertSee('Preview Url')
            ->assertSee('ok')
            ->assertDontSee('Hidden LinkedIn')
            ->assertDontSee('super-secret-token')
            ->assertDontSee('hidden-secret-token');
    }

    public function test_settings_routes_require_permissions_and_active_core_module(): void
    {
        [$viewer] = $this->tenantWithRole('viewer');
        [$ownerNoCore] = $this->tenantWithRole('owner', null);

        $this->actingAs($viewer)
            ->get(route('settings.account'))
            ->assertForbidden();

        $this->actingAs($ownerNoCore)
            ->get(route('settings.account'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, ?string $plan = 'scale_monthly'): array
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

        if ($plan) {
            if ($plan === 'core_only') {
                app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
                $contentModuleId = Module::query()->where('key', 'content')->value('id');
                $account->subscriptionModules()->where('module_id', $contentModuleId)->update(['status' => 'canceled']);
            } else {
                app(SubscriptionService::class)->activatePlan($account, $plan);
            }
        }

        return [$user, $account, $brand];
    }
}
