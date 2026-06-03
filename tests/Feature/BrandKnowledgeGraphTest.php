<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use App\Services\BrandKnowledgeGraphService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BrandKnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_brand_entities_from_management_ui(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->post(route('settings.knowledge-graph.entities.store'), [
                'name' => 'Argusly',
                'entity_type' => 'company',
                'aliases' => 'Argusly AI, Argusly Platform',
                'description' => 'Agentic marketing intelligence platform.',
            ])
            ->assertRedirect(route('settings.knowledge-graph'));

        $entity = Entity::query()->where('account_id', $account->id)->firstOrFail();

        $this->assertSame('Argusly', $entity->name);
        $this->assertSame('company', $entity->entity_type);
        $this->assertSame(['Argusly AI', 'Argusly Platform'], $entity->aliases);
        $this->assertDatabaseHas('brand_entities', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'entity_id' => $entity->id,
        ]);

        $this->actingAs($user)
            ->get(route('settings.knowledge-graph'))
            ->assertOk()
            ->assertSee('Knowledge Graph')
            ->assertSee('Argusly')
            ->assertSee('AI visibility scoring')
            ->assertSee('Entity coverage')
            ->assertSee('Topic authority');
    }

    public function test_user_can_create_relationships_between_current_brand_entities(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $service = app(BrandKnowledgeGraphService::class);

        $company = $service->createForBrand($account, $brand, [
            'name' => 'Argusly',
            'entity_type' => 'company',
            'description' => 'Company entity.',
        ])->entity;
        $product = $service->createForBrand($account, $brand, [
            'name' => 'Intelligence Feed',
            'entity_type' => 'product',
            'description' => 'Product entity.',
        ])->entity;

        $this->actingAs($user)
            ->post(route('settings.knowledge-graph.relationships.store'), [
                'source_entity_id' => $company->id,
                'relationship_type' => 'offers',
                'target_entity_id' => $product->id,
            ])
            ->assertRedirect(route('settings.knowledge-graph'));

        $this->assertDatabaseHas('entity_relationships', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_entity_id' => $company->id,
            'target_entity_id' => $product->id,
            'relationship_type' => 'offers',
        ]);

        $this->actingAs($user)
            ->get(route('settings.knowledge-graph'))
            ->assertOk()
            ->assertSee('Argusly')
            ->assertSee('Offers')
            ->assertSee('Intelligence Feed');
    }

    public function test_knowledge_graph_is_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $thirdBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Third Brand', 'slug' => 'third-brand']);
        $service = app(BrandKnowledgeGraphService::class);

        $service->createForBrand($account, $brand, [
            'name' => 'Visible Topic',
            'entity_type' => 'topic',
        ]);
        $service->createForBrand($account, $otherBrand, [
            'name' => 'Hidden Same Account Topic',
            'entity_type' => 'topic',
        ]);
        $service->createForBrand($otherAccount, $thirdBrand, [
            'name' => 'Hidden Other Account Topic',
            'entity_type' => 'topic',
        ]);

        $this->actingAs($user)
            ->get(route('settings.knowledge-graph'))
            ->assertOk()
            ->assertSee('Visible Topic')
            ->assertDontSee('Hidden Same Account Topic')
            ->assertDontSee('Hidden Other Account Topic');
    }

    public function test_cross_account_entities_cannot_be_linked_to_brand(): void
    {
        [, $account, $brand] = $this->tenantWithRole('owner');
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreignEntity = Entity::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Foreign Topic',
            'entity_type' => 'topic',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(BrandKnowledgeGraphService::class)->attachToBrand($account, $brand, $foreignEntity);
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }
}
