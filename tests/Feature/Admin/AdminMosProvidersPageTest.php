<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Mos\MosProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows mos provider diagnostics to admin area users only', function (): void {
    $superadmin = makeAdminMosProviderUser('superadmin');
    $admin = makeAdminMosProviderUser('admin');
    $nonAdmin = makeAdminMosProviderUser(null);
    $registry = app(MosProviderRegistry::class);

    $response = $this->actingAs($superadmin)
        ->get(route('admin.mos-providers.index'));

    $response->assertOk()
        ->assertSee('MOS Providers')
        ->assertSee('None detected');

    foreach ($registry->diagnostics() as $provider) {
        $response
            ->assertSee($provider['key'])
            ->assertSee($provider['domain'])
            ->assertSee($provider['capabilities_list'])
            ->assertSee($provider['class']);
    }

    foreach ($registry->opportunityDiagnostics() as $provider) {
        $response
            ->assertSee($provider['provider_key'])
            ->assertSee(class_basename((string) $provider['legacy_model']))
            ->assertSee($provider['classification'])
            ->assertSee($provider['readiness'])
            ->assertSee($provider['risk_level']);
    }

    $this->actingAs($admin)
        ->get(route('admin.mos-providers.index'))
        ->assertOk()
        ->assertSee('MOS Providers');

    $this->actingAs($nonAdmin)
        ->get(route('admin.mos-providers.index'))
        ->assertForbidden();
});

function makeAdminMosProviderUser(?string $adminRole): User
{
    $organization = Organization::query()->create([
        'name' => 'MOS Provider Admin Org '.Str::lower(Str::random(4)),
        'slug' => 'mos-provider-admin-org-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'MOS Provider Admin User',
        'email' => 'mos-provider-admin+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $adminRole !== null,
        'admin_role' => $adminRole,
    ]);
}
