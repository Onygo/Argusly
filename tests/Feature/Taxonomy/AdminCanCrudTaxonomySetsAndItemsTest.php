<?php

use App\Models\Organization;
use App\Models\TaxonomyItem;
use App\Models\TaxonomySet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows admin to crud taxonomy sets and items', function () {
    $admin = makeTaxonomyAdminUser();

    $this->actingAs($admin)
        ->get(route('admin.editorial-taxonomy.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.sets.store'), [
            'name' => 'Custom Set',
            'description' => 'Custom description',
            'is_default' => '0',
        ])
        ->assertRedirect();

    $set = TaxonomySet::query()->where('name', 'Custom Set')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.items.store', $set), [
            'type' => TaxonomyItem::TYPE_INTENT,
            'name' => 'Retention Intent',
            'slug' => 'retention_intent',
            'is_active' => '1',
        ])
        ->assertRedirect();

    $item = TaxonomyItem::query()
        ->where('taxonomy_set_id', $set->id)
        ->where('slug', 'retention_intent')
        ->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.items.update', ['set' => $set, 'item' => $item]), [
            'type' => TaxonomyItem::TYPE_AUDIENCE,
            'name' => 'Ops Audience',
            'slug' => 'ops_audience',
            'is_active' => '1',
        ])
        ->assertRedirect();

    $item->refresh();
    expect($item->type)->toBe(TaxonomyItem::TYPE_AUDIENCE)
        ->and($item->name)->toBe('Ops Audience')
        ->and($item->slug)->toBe('ops_audience');

    $this->actingAs($admin)
        ->post(route('admin.editorial-taxonomy.sets.update', $set), [
            'name' => 'Custom Set Updated',
            'description' => 'Updated',
            'is_default' => '1',
        ])
        ->assertRedirect();

    $set->refresh();
    expect($set->name)->toBe('Custom Set Updated')
        ->and($set->is_default)->toBeTrue();

    $this->actingAs($admin)
        ->delete(route('admin.editorial-taxonomy.items.destroy', ['set' => $set, 'item' => $item]))
        ->assertRedirect();

    $this->assertSoftDeleted('taxonomy_items', ['id' => $item->id]);

    $this->actingAs($admin)
        ->delete(route('admin.editorial-taxonomy.sets.destroy', $set))
        ->assertRedirect(route('admin.editorial-taxonomy.index'));

    expect(TaxonomySet::query()->whereKey($set->id)->exists())->toBeFalse();
});

function makeTaxonomyAdminUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Taxonomy Admin Org',
        'slug' => 'taxonomy-admin-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Taxonomy Admin',
        'email' => 'taxonomy-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

