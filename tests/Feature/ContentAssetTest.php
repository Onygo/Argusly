<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_index_is_tenant_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Beta Brand', 'slug' => 'beta-brand']);

        ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible tenant asset', 'status' => 'draft']);
        ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden tenant asset', 'status' => 'draft']);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertOk()
            ->assertSee('Visible tenant asset')
            ->assertDontSee('Hidden tenant asset');
    }

    public function test_content_index_and_show_are_brand_isolated(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $user->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visible = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible brand asset', 'status' => 'draft']);
        $hidden = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden brand asset', 'status' => 'draft']);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertOk()
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);

        $this->actingAs($user)
            ->get(route('app.content.show', $hidden))
            ->assertForbidden();
    }

    public function test_content_module_access_is_required(): void
    {
        [$user] = $this->tenantWithRole('owner', activatePlan: false);

        $this->actingAs($user)
            ->get(route('app.content.index'))
            ->assertForbidden();
    }

    public function test_viewer_cannot_edit_content_assets(): void
    {
        [$viewer, , $brand] = $this->tenantWithRole('viewer');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'draft']);

        $this->actingAs($viewer)
            ->get(route('app.content.edit', $asset))
            ->assertForbidden();
    }

    public function test_editor_can_create_and_edit_content_assets(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');

        $this->actingAs($editor)
            ->post(route('app.content.store'), $this->assetPayload(['title' => 'Editor created content asset']))
            ->assertRedirect();

        $asset = ContentAsset::query()->where('title', 'Editor created content asset')->firstOrFail();

        $this->assertSame($brand->id, $asset->brand_id);
        $this->assertSame('draft', $asset->status);

        $this->actingAs($editor)
            ->put(route('app.content.update', $asset), $this->assetPayload(['title' => 'Editor updated content asset']))
            ->assertRedirect(route('app.content.show', $asset));

        $this->assertDatabaseHas('content_assets', [
            'id' => $asset->id,
            'title' => 'Editor updated content asset',
        ]);
    }

    public function test_publisher_and_admin_can_approve_and_publish_content_assets(): void
    {
        [$publisher, , $publisherBrand] = $this->tenantWithRole('publisher');
        $publisherAsset = ContentAsset::factory()->forBrand($publisherBrand)->create(['status' => 'review']);

        $this->actingAs($publisher)
            ->post(route('app.content.approve', $publisherAsset))
            ->assertRedirect(route('app.content.show', $publisherAsset));

        $this->assertDatabaseHas('content_assets', [
            'id' => $publisherAsset->id,
            'status' => 'approved',
        ]);

        [$admin, , $adminBrand] = $this->tenantWithRole('admin', slug: 'admin-account');
        $adminAsset = ContentAsset::factory()->forBrand($adminBrand)->create(['status' => 'approved']);

        $this->actingAs($admin)
            ->post(route('app.content.publish', $adminAsset))
            ->assertRedirect(route('app.content.show', $adminAsset));

        $adminAsset->refresh();

        $this->assertSame('published', $adminAsset->status);
        $this->assertNotNull($adminAsset->published_at);
        $this->assertNotNull($adminAsset->first_published_at);
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true, string $slug = 'alpha-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
            app(CreditService::class)->grant($account, 1000, $user, 'Test credits');
        }

        return [$user, $account, $brand];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assetPayload(array $overrides = []): array
    {
        return [
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Content asset placeholder',
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'excerpt' => 'Placeholder excerpt.',
            'body' => 'Placeholder body.',
            ...$overrides,
        ];
    }
}
