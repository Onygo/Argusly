<?php

use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Admin\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('organization status transitions', function () {
    it('allows admin to deactivate an active organization', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ACTIVE]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.hold', $organization))
            ->assertRedirect()
            ->assertSessionHas('status', 'Organization set to on hold.');

        $organization->refresh();
        expect($organization->status)->toBe(Organization::STATUS_ON_HOLD);
    });

    it('allows admin to activate an on-hold organization', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ON_HOLD]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.activate', $organization))
            ->assertRedirect()
            ->assertSessionHas('status', 'Customer activated.');

        $organization->refresh();
        expect($organization->status)->toBe(Organization::STATUS_ACTIVE);
    });

    it('allows admin to archive an active organization', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ACTIVE]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.archive', $organization))
            ->assertRedirect()
            ->assertSessionHas('status', 'Organization archived.');

        $organization->refresh();
        expect($organization->status)->toBe(Organization::STATUS_ARCHIVED);
    });

    it('allows admin to archive an on-hold organization', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ON_HOLD]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.archive', $organization))
            ->assertRedirect()
            ->assertSessionHas('status', 'Organization archived.');

        $organization->refresh();
        expect($organization->status)->toBe(Organization::STATUS_ARCHIVED);
    });

    it('allows admin to unarchive an archived organization', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ARCHIVED]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.unarchive', $organization))
            ->assertRedirect()
            ->assertSessionHas('status');

        $organization->refresh();
        expect($organization->status)->toBe(Organization::STATUS_ON_HOLD);
    });
});

describe('organization deletion authorization', function () {
    it('requires superadmin to access delete confirmation page', function () {
        [$organization, , $regularAdmin] = makeLifecycleTestContext();

        $this->actingAs($regularAdmin)
            ->get(route('admin.organizations.confirm-delete', $organization))
            ->assertForbidden();
    });

    it('allows superadmin to access delete confirmation page', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();

        $this->actingAs($superadmin)
            ->get(route('admin.organizations.confirm-delete', $organization))
            ->assertOk()
            ->assertSee('Delete Organization')
            ->assertSee($organization->name);
    });

    it('requires superadmin to delete organization', function () {
        [$organization, , $regularAdmin] = makeLifecycleTestContext();

        $this->actingAs($regularAdmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
            ])
            ->assertForbidden();
    });
});

describe('organization deletion confirmation', function () {
    it('requires exact organization name to confirm deletion', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => 'wrong-name',
            ])
            ->assertSessionHasErrors('confirmation_name');

        expect(Organization::find($organization->id))->not->toBeNull();
    });

    it('accepts case-insensitive organization name for confirmation', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();
        $organization->update(['name' => 'Test Organization']);

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => 'test organization',
            ])
            ->assertRedirect(route('admin.organizations'))
            ->assertSessionHas('status');

        expect(Organization::find($organization->id))->toBeNull();
    });

    it('successfully deletes organization with correct confirmation', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();
        $organizationId = $organization->id;

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
            ])
            ->assertRedirect(route('admin.organizations'))
            ->assertSessionHas('status');

        expect(Organization::find($organizationId))->toBeNull();
    });
});

describe('organization deletion with dependencies', function () {
    it('blocks deletion when organization has workspaces', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();

        Workspace::query()->create([
            'name' => 'Test Workspace',
            'display_name' => 'Test Workspace',
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('delete');

        expect(Organization::find($organization->id))->not->toBeNull();
    });

    it('blocks deletion when organization has multiple users', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();

        // Add a second user
        User::query()->create([
            'name' => 'Second User',
            'email' => 'second+' . Str::lower(Str::random(6)) . '@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'member',
            'active' => true,
            'approved_at' => now(),
            'is_admin' => false,
        ]);

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('delete');

        expect(Organization::find($organization->id))->not->toBeNull();
    });

    it('allows force deletion of organization with dependencies', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();
        $organizationId = $organization->id;

        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace',
            'display_name' => 'Test Workspace',
            'organization_id' => $organization->id,
        ]);

        ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'base_url' => 'https://test.example.com',
            'allowed_domains' => ['test.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
                'force_delete' => '1',
            ])
            ->assertRedirect(route('admin.organizations'))
            ->assertSessionHas('status');

        expect(Organization::find($organizationId))->toBeNull();
        expect(Workspace::find($workspace->id))->toBeNull();
    });
});

describe('organization deletion service', function () {
    it('correctly identifies organizations that can be safely deleted', function () {
        [$organization] = makeLifecycleTestContext();

        $service = new OrganizationDeletionService();
        $result = $service->canDelete($organization);

        expect($result['can_delete'])->toBeTrue();
        expect($result['reasons'])->toBeEmpty();
    });

    it('correctly identifies organizations with dependencies', function () {
        [$organization] = makeLifecycleTestContext();

        Workspace::query()->create([
            'name' => 'Test Workspace',
            'display_name' => 'Test Workspace',
            'organization_id' => $organization->id,
        ]);

        $service = new OrganizationDeletionService();
        $result = $service->canDelete($organization);

        expect($result['can_delete'])->toBeFalse();
        expect($result['reasons'])->not->toBeEmpty();
    });

    it('provides accurate related data summary', function () {
        [$organization] = makeLifecycleTestContext();

        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace',
            'display_name' => 'Test Workspace',
            'organization_id' => $organization->id,
        ]);

        ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Test Site',
            'site_url' => 'https://test.example.com',
            'base_url' => 'https://test.example.com',
            'allowed_domains' => ['test.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        BrandVoice::query()->create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => 'Test Voice',
            'description' => 'A test voice',
            'tone_attributes' => ['friendly'],
        ]);

        $service = new OrganizationDeletionService();
        $summary = $service->getRelatedDataSummary($organization);

        expect($summary['workspaces'])->toBe(1);
        expect($summary['client_sites'])->toBe(1);
        expect($summary['brand_voices'])->toBe(1);
    });
});

describe('organization status display', function () {
    it('shows correct status badges on index page', function () {
        $superadmin = createSuperadmin();

        Organization::query()->create([
            'name' => 'Active Org',
            'slug' => 'active-org-' . Str::lower(Str::random(6)),
            'status' => Organization::STATUS_ACTIVE,
        ]);

        Organization::query()->create([
            'name' => 'On Hold Org',
            'slug' => 'on-hold-org-' . Str::lower(Str::random(6)),
            'status' => Organization::STATUS_ON_HOLD,
        ]);

        Organization::query()->create([
            'name' => 'Archived Org',
            'slug' => 'archived-org-' . Str::lower(Str::random(6)),
            'status' => Organization::STATUS_ARCHIVED,
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('On hold')
            ->assertSee('Archived');
    });

    it('shows lifecycle actions on organization detail page', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ACTIVE]);

        $this->actingAs($superadmin)
            ->get(route('admin.organizations.show', $organization))
            ->assertOk()
            ->assertSee('Lifecycle actions')
            ->assertSee('Deactivate organization')
            ->assertSee('Archive organization')
            ->assertSee('Delete organization');
    });

    it('shows restore action for archived organizations', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ARCHIVED]);

        $this->actingAs($superadmin)
            ->get(route('admin.organizations.show', $organization))
            ->assertOk()
            ->assertSee('Restore from archive')
            ->assertSee('Restore organization');
    });
});

describe('non-admin access control', function () {
    it('prevents non-admin users from accessing organization management', function () {
        [$organization] = makeLifecycleTestContext();

        $regularUser = User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular+' . Str::lower(Str::random(6)) . '@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'member',
            'active' => true,
            'approved_at' => now(),
            'is_admin' => false,
        ]);

        $this->actingAs($regularUser)
            ->get(route('admin.organizations'))
            ->assertForbidden();
    });

    it('prevents non-admin users from performing lifecycle actions', function () {
        [$organization] = makeLifecycleTestContext();

        $regularUser = User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular+' . Str::lower(Str::random(6)) . '@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'member',
            'active' => true,
            'approved_at' => now(),
            'is_admin' => false,
        ]);

        $this->actingAs($regularUser)
            ->post(route('admin.organizations.hold', $organization))
            ->assertForbidden();

        $this->actingAs($regularUser)
            ->post(route('admin.organizations.archive', $organization))
            ->assertForbidden();
    });
});

describe('audit logging', function () {
    it('logs organization deactivation', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ACTIVE]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.hold', $organization));

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'action' => 'organization.deactivated',
        ]);
    });

    it('logs organization archival', function () {
        [$organization, $superadmin] = makeLifecycleTestContext(['status' => Organization::STATUS_ACTIVE]);

        $this->actingAs($superadmin)
            ->post(route('admin.organizations.archive', $organization));

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'action' => 'organization.archived',
        ]);
    });

    it('logs organization deletion', function () {
        [$organization, $superadmin] = makeLifecycleTestContext();
        $organizationId = $organization->id;

        $this->actingAs($superadmin)
            ->delete(route('admin.organizations.delete', $organization), [
                'confirmation_name' => $organization->name,
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Organization::class,
            'subject_id' => $organizationId,
            'action' => 'organization.deleted',
        ]);
    });
});

function makeLifecycleTestContext(array $organizationOverrides = []): array
{
    $organization = Organization::query()->create(array_merge([
        'name' => 'Lifecycle Test Org',
        'slug' => 'lifecycle-test-org-' . Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ], $organizationOverrides));

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

    $organization->primary_user_id = $ownerUser->id;
    $organization->save();

    $superadmin = createSuperadmin();
    $regularAdmin = createRegularAdmin();

    return [$organization, $superadmin, $regularAdmin];
}

function createSuperadmin(): User
{
    return User::query()->create([
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
}

function createRegularAdmin(): User
{
    return User::query()->create([
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
}
