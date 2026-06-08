<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders the refactored organization detail workspace sections', function () {
    [$organization, $superadmin] = makeAdminOrganizationDetailContext();

    $this->actingAs($superadmin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('Organization settings')
        ->assertSee('Legal and billing profile')
        ->assertSee('Account access')
        ->assertSee('Users')
        ->assertSee('Workspaces')
        ->assertSee('Admin actions')
        ->assertSee('Advanced and danger actions');
});

it('keeps organization settings update working', function () {
    [$organization, $superadmin] = makeAdminOrganizationDetailContext();

    $this->actingAs($superadmin)
        ->post(route('admin.organizations.update', $organization), [
            'name' => 'Renamed Org',
            'slug' => 'renamed-org-' . Str::lower(Str::random(6)),
            'custom_domain' => 'custom.example.com',
            'webhook_url' => 'https://hooks.example.com/admin',
            'api_enabled' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Organization updated.');

    $organization->refresh();

    expect($organization->name)->toBe('Renamed Org')
        ->and((string) $organization->custom_domain)->toBe('custom.example.com')
        ->and((string) $organization->webhook_url)->toBe('https://hooks.example.com/admin')
        ->and((bool) $organization->api_enabled)->toBeTrue();
});

it('keeps legal profile update working', function () {
    [$organization, $superadmin] = makeAdminOrganizationDetailContext();

    $this->actingAs($superadmin)
        ->post(route('admin.organizations.legal-profile.update', $organization), [
            'legal_name' => 'Argusly Legal B.V.',
            'billing_email' => 'billing@example.com',
            'vat_id' => 'NL123456789B01',
            'billing_address_line1' => 'Damrak 1',
            'billing_address_line2' => 'Floor 2',
            'billing_postal_code' => '1012LG',
            'billing_city' => 'Amsterdam',
            'billing_country_code' => 'nl',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Company legal profile updated.');

    $organization->refresh();

    expect((string) $organization->legal_name)->toBe('Argusly Legal B.V.')
        ->and((string) $organization->billing_email)->toBe('billing@example.com')
        ->and((string) $organization->vat_id)->toBe('NL123456789B01')
        ->and((string) data_get($organization->billing_address, 'line1'))->toBe('Damrak 1')
        ->and((string) data_get($organization->billing_address, 'country_code'))->toBe('NL');
});

it('renders admin actions based on authorization level', function () {
    [$organization, $superadmin, $admin] = makeAdminOrganizationDetailContext();

    $this->actingAs($superadmin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Save organization settings')
        ->assertSee('Save legal profile')
        ->assertSee('Regenerate API key')
        ->assertSee('Impersonate workspace');

    $this->actingAs($admin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Read-only for your admin role')
        ->assertSee('Deactivate organization')
        ->assertSee('Impersonate workspace')
        ->assertDontSee('Regenerate API key')
        ->assertDontSee('Save organization settings');
});

it('adds guarded confirmations for risky actions', function () {
    [$organization, $superadmin] = makeAdminOrganizationDetailContext();

    $content = $this->actingAs($superadmin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->getContent();

    // Updated copy for deactivation confirmation
    expect($content)->toContain('This will restrict customer operations until reactivated.')
        ->toContain('Regenerate this organization API key now? Existing integrations will stop working until updated.')
        ->toContain('Disable this user account? They will lose access until reactivated.')
        ->toContain('Impersonate this workspace using its primary active user context?');
});

it('renders users and workspaces sections with operational context', function () {
    [$organization, $superadmin] = makeAdminOrganizationDetailContext();

    $this->actingAs($superadmin)
        ->get(route('admin.organizations.show', $organization))
        ->assertOk()
        ->assertSee('Users')
        ->assertSee('Workspaces')
        ->assertSee('Owner User')
        ->assertSee('Pending User')
        ->assertSee('Workspace Alpha')
        ->assertSee('Workspace Beta')
        ->assertSee('Notifications');
});

it('keeps superadmin-only organization actions protected', function () {
    [$organization, , $admin] = makeAdminOrganizationDetailContext();

    $this->actingAs($admin)
        ->post(route('admin.organizations.update', $organization), [
            'name' => 'Blocked update',
            'slug' => (string) $organization->slug,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('admin.organizations.api-key.regenerate', $organization))
        ->assertForbidden();
});

function makeAdminOrganizationDetailContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Admin Org',
        'slug' => 'admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'legal_name' => 'Admin Org Legal BV',
        'billing_email' => 'finance@admin-org.test',
        'vat_id' => 'NL000000000B01',
        'billing_address' => [
            'line1' => 'Keizersgracht 1',
            'line2' => null,
            'postal_code' => '1015CJ',
            'city' => 'Amsterdam',
            'country_code' => 'NL',
        ],
        'custom_domain' => 'admin-org.example.com',
        'webhook_url' => 'https://hooks.admin-org.example.com/webhook',
        'api_enabled' => true,
    ]);

    $workspaceA = Workspace::query()->create([
        'name' => 'Workspace Alpha',
        'display_name' => 'Workspace Alpha',
        'organization_id' => $organization->id,
    ]);

    $workspaceB = Workspace::query()->create([
        'name' => 'Workspace Beta',
        'display_name' => 'Workspace Beta',
        'organization_id' => $organization->id,
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspaceA->id,
        'type' => 'wordpress',
        'name' => 'Alpha Site',
        'site_url' => 'https://alpha.example.com',
        'base_url' => 'https://alpha.example.com',
        'allowed_domains' => ['alpha.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspaceB->id,
        'type' => 'laravel',
        'name' => 'Beta Site',
        'site_url' => 'https://beta.example.com',
        'base_url' => 'https://beta.example.com',
        'allowed_domains' => ['beta.example.com'],
        'is_active' => true,
        'status' => 'pending',
    ]);

    $ownerUser = User::query()->create([
        'name' => 'Owner User',
        'email' => 'owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    User::query()->create([
        'name' => 'Pending User',
        'email' => 'pending+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'member',
        'active' => false,
        'approved_at' => null,
        'is_admin' => false,
    ]);

    $organization->primary_user_id = $ownerUser->id;
    $organization->save();

    $superadmin = User::query()->create([
        'name' => 'Superadmin',
        'email' => 'superadmin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => null,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);

    $admin = User::query()->create([
        'name' => 'Admin Operator',
        'email' => 'admin-operator+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => null,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);

    return [$organization, $superadmin, $admin];
}
