<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Module;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_topics_can_be_created_and_viewed_in_current_tenant_context(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('app.topics.store'), [
                'name' => 'AI Visibility',
                'description' => 'Track how the brand appears in AI answers.',
                'status' => 'active',
                'brand_scoped' => true,
                'priority' => 10,
                'importance_score' => 92,
            ])
            ->assertRedirect();

        $topic = Topic::query()->where('name', 'AI Visibility')->firstOrFail();

        $this->assertSame($account->id, $topic->account_id);
        $this->assertSame($brand->id, $topic->brand_id);
        $this->assertDatabaseHas('brand_topics', [
            'brand_id' => $brand->id,
            'topic_id' => $topic->id,
            'priority' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('app.topics.index'))
            ->assertOk()
            ->assertSee('AI Visibility')
            ->assertSee('Topics');
    }

    public function test_topic_pages_do_not_leak_other_brand_topics(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        $visible = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Brand Monitoring',
            'slug' => 'brand-monitoring',
            'status' => 'active',
        ]);

        Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden Topic',
            'slug' => 'hidden-topic',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.topics.index'))
            ->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee('Hidden Topic');
    }

    public function test_topic_clusters_and_future_topicable_links_are_available(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Agentic Marketing',
            'slug' => 'agentic-marketing',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('app.topics.clusters.store'), [
                'name' => 'AI Growth',
                'description' => 'Topics for AI-led growth programs.',
                'topic_ids' => [$topic->id],
            ])
            ->assertRedirect();

        $asset = ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Agentic marketing guide',
            'slug' => 'agentic-marketing-guide',
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
        ]);

        $asset->topics()->attach($topic->id, [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'relationship_type' => 'primary',
            'relevance_score' => 88,
        ]);

        $this->assertTrue($asset->topics()->whereKey($topic->id)->exists());
        $this->assertDatabaseHas('topic_cluster_topics', ['topic_id' => $topic->id]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Agency', 'slug' => 'alpha-agency']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Alpha Main',
            'slug' => 'alpha-main',
            'domain' => 'alpha.example',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $core = Module::query()->where('key', 'core')->firstOrFail();
        $this->assertDatabaseHas('subscription_modules', [
            'account_id' => $account->id,
            'module_id' => $core->id,
            'status' => 'active',
        ]);

        return [$user, $account, $brand];
    }
}
