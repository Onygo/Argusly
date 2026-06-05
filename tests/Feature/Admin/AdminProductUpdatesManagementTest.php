<?php

use App\Models\Organization;
use App\Models\ProductUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires admin authorization for product updates routes', function () {
    $client = makeProductUpdatesClientUser();
    $admin = makeProductUpdatesAdminUser();

    $guestResponse = $this->get(route('admin.product-updates.index'));
    $guestResponse->assertRedirect();
    expect((string) $guestResponse->headers->get('Location'))->toEndWith('/login');

    $this->actingAs($client)
        ->get(route('admin.product-updates.index'))
        ->assertStatus(403);

    $this->actingAs($admin)
        ->get(route('admin.product-updates.index'))
        ->assertOk();
});

it('allows admin to create a product update', function () {
    $admin = makeProductUpdatesAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.product-updates.store'), [
            'title' => 'Admin created update',
            'summary' => 'Summary from admin',
            'body_markdown' => "## Details\n\nShipped from admin panel.",
            'version' => 'v0.5.0',
            'tags_input' => 'connector, billing',
            'is_public' => '1',
            'published_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('admin.product-updates.index'));

    $update = ProductUpdate::query()->where('title', 'Admin created update')->first();

    expect($update)->not->toBeNull()
        ->and($update?->is_public)->toBeTrue()
        ->and($update?->version)->toBe('v0.5.0')
        ->and($update?->tags)->toBe(['connector', 'billing']);
});

function makeProductUpdatesAdminUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Product Updates Admin Org ' . Str::lower(Str::random(4)),
        'slug' => 'product_updates_admin_org_' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Product Updates Admin',
        'email' => 'product_updates_admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

function makeProductUpdatesClientUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Product Updates Client Org ' . Str::lower(Str::random(4)),
        'slug' => 'product_updates_client_org_' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Product Updates Client',
        'email' => 'product_updates_client+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);
}
