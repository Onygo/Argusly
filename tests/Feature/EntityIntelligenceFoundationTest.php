<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\EntityAlias;
use App\Models\EntityRelationship;
use App\Models\Mention;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\TestCase;

class EntityIntelligenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_entities_can_be_searched_filtered_and_opened(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $entity = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Argusly Intelligence Platform',
            'entity_type' => 'product',
            'description' => 'Entity-first visibility product.',
            'status' => 'active',
        ]);
        EntityAlias::query()->create(['entity_id' => $entity->id, 'alias' => 'AIP']);
        Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Hidden Draft Person',
            'entity_type' => 'person',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('app.entities.index', [
                'search' => 'AIP',
                'entity_type' => 'product',
                'status' => 'active',
                'scope' => 'brand',
            ]))
            ->assertOk()
            ->assertSee('Entities')
            ->assertSee('Argusly Intelligence Platform')
            ->assertSee('AIP')
            ->assertSee('Relationship visualization')
            ->assertDontSee('Hidden Draft Person');

        $this->actingAs($user)
            ->get(route('app.entities.show', $entity))
            ->assertOk()
            ->assertSee('Entity detail')
            ->assertSee('Argusly Intelligence Platform')
            ->assertSee('Relationship visualization')
            ->assertSee('Aliases')
            ->assertSee('visibility')
            ->assertSee('relationship intelligence');
    }

    public function test_entity_relationships_mentions_and_topics_show_on_detail_page(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $source = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Argusly',
            'entity_type' => 'company',
            'status' => 'active',
        ]);
        $target = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Prompt Monitor',
            'entity_type' => 'product',
            'status' => 'active',
        ]);
        EntityRelationship::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relationship_type' => 'offers',
            'strength' => 87,
        ]);
        $mention = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Argusly launches prompt monitoring',
            'content' => 'Launch note.',
            'sentiment' => 'positive',
        ]);
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Prompt Monitoring',
            'slug' => 'prompt-monitoring',
            'status' => 'active',
        ]);
        $source->mentions()->attach($mention);
        $source->topics()->attach($topic);

        $this->actingAs($user)
            ->get(route('app.entities.show', $source))
            ->assertOk()
            ->assertSee('Prompt Monitor')
            ->assertSee('Offers')
            ->assertSee('87')
            ->assertSee('Argusly launches prompt monitoring')
            ->assertSee('Prompt Monitoring');
    }

    public function test_entities_are_tenant_safe_and_brand_aware(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreignBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Foreign Brand', 'slug' => 'foreign-brand']);

        Entity::query()->create(['account_id' => null, 'brand_id' => null, 'name' => 'Global Entity', 'entity_type' => 'topic']);
        Entity::query()->create(['account_id' => $account->id, 'brand_id' => null, 'name' => 'Account Entity', 'entity_type' => 'organization']);
        Entity::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'Visible Brand Entity', 'entity_type' => 'company']);
        Entity::query()->create(['account_id' => $account->id, 'brand_id' => $otherBrand->id, 'name' => 'Hidden Same Account Entity', 'entity_type' => 'company']);
        Entity::query()->create(['account_id' => $otherAccount->id, 'brand_id' => $foreignBrand->id, 'name' => 'Hidden Other Account Entity', 'entity_type' => 'company']);

        $this->actingAs($user)
            ->get(route('app.entities.index'))
            ->assertOk()
            ->assertSee('Global Entity')
            ->assertSee('Account Entity')
            ->assertSee('Visible Brand Entity')
            ->assertDontSee('Hidden Same Account Entity')
            ->assertDontSee('Hidden Other Account Entity');
    }

    public function test_entity_policies_and_relationship_validation(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $entity = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Policy Entity',
            'entity_type' => 'creator',
        ]);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = Entity::query()->create([
            'account_id' => $otherAccount->id,
            'name' => 'Foreign Entity',
            'entity_type' => 'company',
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $entity));

        $this->expectException(InvalidArgumentException::class);

        EntityRelationship::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_entity_id' => $entity->id,
            'target_entity_id' => $foreign->id,
            'relationship_type' => 'partner_of',
        ]);
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
