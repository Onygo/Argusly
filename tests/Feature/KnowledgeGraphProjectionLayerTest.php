<?php

namespace Tests\Feature;

use App\Jobs\ProjectDomainEventJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\ContentAsset;
use App\Models\DomainEvent;
use App\Models\DomainEventProjectorRun;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Services\DomainEvents\ProjectorRegistry;
use App\Services\Graph\GraphOpportunityService;
use App\Services\Graph\GraphProjectionService;
use App\Services\Graph\GraphQueryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class KnowledgeGraphProjectionLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_projection_rebuilds_idempotent_nodes_and_edges(): void
    {
        [$account, $brand] = $this->tenant();
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'AI Visibility',
        ]);
        $brand->topics()->attach($topic->id);
        $competitor = Competitor::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Brandwatch',
            'website' => 'https://brandwatch.example',
        ]);
        $competitor->topics()->attach($topic->id, [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'relationship_type' => 'primary',
            'relevance_score' => 91,
        ]);
        $asset = ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'AI Visibility Guide',
            'type' => 'article',
            'status' => 'published',
        ]);
        $asset->topics()->attach($topic->id, [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'relationship_type' => 'primary',
            'relevance_score' => 96,
        ]);

        $first = app(GraphProjectionService::class)->rebuild($account, $brand);
        $second = app(GraphProjectionService::class)->rebuild($account, $brand);

        $this->assertSame($first, $second);
        $this->assertDatabaseHas('graph_nodes', ['account_id' => $account->id, 'brand_id' => $brand->id, 'node_type' => 'brand', 'label' => $brand->name]);
        $this->assertDatabaseHas('graph_nodes', ['account_id' => $account->id, 'brand_id' => $brand->id, 'node_type' => 'topic', 'label' => 'AI Visibility']);
        $this->assertDatabaseHas('graph_edges', ['account_id' => $account->id, 'brand_id' => $brand->id, 'relationship_type' => 'supports']);
        $this->assertDatabaseHas('graph_edges', ['account_id' => $account->id, 'brand_id' => $brand->id, 'relationship_type' => 'competes_with']);
        $this->assertDatabaseHas('graph_edges', ['account_id' => $account->id, 'brand_id' => $brand->id, 'relationship_type' => 'covers']);
    }

    public function test_graph_edges_cannot_cross_accounts(): void
    {
        [$account, $brand] = $this->tenant('Alpha');
        [$otherAccount, $otherBrand] = $this->tenant('Beta');

        $source = app(GraphProjectionService::class)->brand($brand);
        $target = app(GraphProjectionService::class)->brand($otherBrand);

        $this->expectException(InvalidArgumentException::class);

        app(GraphProjectionService::class)->edge($source, $target, 'connected_to');
    }

    public function test_domain_events_update_the_graph_once(): void
    {
        [$account, $brand] = $this->tenant();
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Narrative Intelligence',
        ]);
        $event = DomainEvent::query()
            ->where('event_type', 'TopicCreated')
            ->where('subject_type', Topic::class)
            ->where('subject_id', $topic->id)
            ->latest()
            ->firstOrFail();

        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));
        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));

        $this->assertSame(1, GraphNode::query()->where('source_type', Topic::class)->where('source_id', $topic->id)->count());
        $this->assertSame(1, DomainEventProjectorRun::query()->where('event_uuid', $event->uuid)->where('projector', \App\Services\DomainEvents\GraphProjector::class)->where('status', 'completed')->count());
    }

    public function test_graph_queries_and_opportunities_are_tenant_safe(): void
    {
        [$account, $brand] = $this->tenant('Alpha');
        [$otherAccount, $otherBrand] = $this->tenant('Beta');

        $visibleTopic = Topic::query()->create(['account_id' => $account->id, 'brand_id' => $brand->id, 'name' => 'Creator Intelligence']);
        Topic::query()->create(['account_id' => $otherAccount->id, 'brand_id' => $otherBrand->id, 'name' => 'Hidden Topic']);

        app(GraphProjectionService::class)->rebuild();

        $summary = app(GraphQueryService::class)->summary($account, $brand);
        $opportunities = app(GraphOpportunityService::class)->discover($account, $brand);

        $this->assertTrue($summary['mostMentionedTopics']->contains(fn (GraphNode $node) => $node->label === 'Creator Intelligence'));
        $this->assertFalse(GraphNode::query()->forTenant($account, $brand)->where('label', 'Hidden Topic')->exists());
        $this->assertTrue($opportunities->contains(fn (Recommendation $recommendation) => str_contains($recommendation->title, $visibleTopic->name)));
        $this->assertFalse(Recommendation::query()->where('account_id', $account->id)->where('title', 'like', '%Hidden Topic%')->exists());
    }

    public function test_graph_explorer_renders_summary_tables(): void
    {
        [$account, $brand, $user] = $this->tenantWithUser();
        Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Relationship Intelligence',
        ]);
        app(GraphProjectionService::class)->rebuild($account, $brand);

        $this->actingAs($user)
            ->get(route('app.intelligence.graph'))
            ->assertOk()
            ->assertSee('Knowledge Graph')
            ->assertSee('Node Counts')
            ->assertSee('Edge Counts')
            ->assertSee('Relationship Intelligence');
    }

    private function tenant(string $name = 'Alpha'): array
    {
        $account = Account::query()->create(['name' => "{$name} Account", 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => "{$name} Brand", 'slug' => fake()->unique()->slug()]);

        return [$account, $brand];
    }

    private function tenantWithUser(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        [$account, $brand] = $this->tenant();
        $user = User::factory()->create();
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$account, $brand, $user];
    }
}
