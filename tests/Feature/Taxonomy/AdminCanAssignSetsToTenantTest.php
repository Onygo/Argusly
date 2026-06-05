<?php

use App\Models\Organization;
use App\Models\TaxonomySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows admin to assign taxonomy sets to tenants', function () {
    $admin = makeTaxonomyAssignmentAdminUser();

    $tenantA = Organization::query()->create([
        'name' => 'Tenant A',
        'slug' => 'tenant-a-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $tenantB = Organization::query()->create([
        'name' => 'Tenant B',
        'slug' => 'tenant-b-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $set = TaxonomySet::query()->create([
        'name' => 'Assigned Set',
        'description' => null,
        'is_default' => false,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.assignments.update', $set), [
            'tenant_ids' => [$tenantA->id, $tenantB->id],
        ])
        ->assertRedirect();

    expect(DB::table('taxonomy_set_tenant')
        ->where('taxonomy_set_id', $set->id)
        ->where('tenant_id', $tenantA->id)
        ->exists())->toBeTrue();

    expect(DB::table('taxonomy_set_tenant')
        ->where('taxonomy_set_id', $set->id)
        ->where('tenant_id', $tenantB->id)
        ->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.assignments.update', $set), [
            'tenant_ids' => [$tenantB->id],
        ])
        ->assertRedirect();

    expect(DB::table('taxonomy_set_tenant')
        ->where('taxonomy_set_id', $set->id)
        ->where('tenant_id', $tenantA->id)
        ->exists())->toBeFalse();
});

function makeTaxonomyAssignmentAdminUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Taxonomy Assignment Admin Org',
        'slug' => 'taxonomy-assignment-admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Taxonomy Assignment Admin',
        'email' => 'taxonomy-assignment-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

