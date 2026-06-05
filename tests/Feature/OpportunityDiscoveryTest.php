<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityProviderRun;
use App\Services\OpportunityDiscoveryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_opportunity_dashboard_shows_strategic_opportunity_lanes(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $topic = $this->topicWithMentions($account, $brand, 'AI Visibility Strategy', 3);
        $competitor = Competitor::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'RivalStack',
            'website' => 'https://rival.example',
            'status' => 'active',
        ]);
        $competitor->snapshots()->create([
            'visibility_score' => 82,
            'mention_score' => 70,
            'share_of_voice' => 65,
            'captured_at' => now(),
        ]);
        $run = VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'query' => 'best AI visibility strategy',
            'normalized_answer' => 'RivalStack appears more often than Alpha.',
            'status' => 'completed',
            'captured_at' => now(),
            'metadata' => ['visibility_score' => 38],
        ]);
        $run->answerEntities()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'entity_name' => 'RivalStack',
            'entity_type' => 'competitor',
            'sentiment' => 'positive',
            'position' => 1,
        ]);

        ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Covered topic',
            'status' => 'published',
            'published_at' => now(),
            'body' => 'This covers another topic.',
        ]);

        $this->actingAs($user)
            ->get(route('app.intelligence.opportunities'))
            ->assertOk()
            ->assertSee('Opportunity Discovery')
            ->assertSee('Emerging topics')
            ->assertSee('Trend detection')
            ->assertSee('Content gaps')
            ->assertSee('AI gaps')
            ->assertSee('Competitor gaps')
            ->assertSee('Market opportunities')
            ->assertSee('AI Visibility Strategy')
            ->assertSee('RivalStack')
            ->assertSee('ChatGPT')
            ->assertSee('Trend detected in News');
    }

    public function test_opportunity_projection_creates_signals_and_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->topicWithMentions($account, $brand, 'Answer Engine Optimization', 4);

        $this->actingAs($user)
            ->post(route('app.intelligence.opportunities.project'))
            ->assertRedirect(route('app.intelligence.opportunities'));

        $signal = IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('source', 'opportunity_discovery')
            ->firstOrFail();

        $this->assertSame('content_opportunity', $signal->type);
        $this->assertSame('opportunity_discovery', $signal->source);
        $this->assertNotEmpty($signal->payload['opportunity_type']);
        $this->assertGreaterThan(0, Recommendation::query()->where('signal_id', $signal->id)->count());

        app(OpportunityDiscoveryService::class)->project($account, $brand);

        $this->assertSame(
            1,
            IntelligenceSignal::query()
                ->where('account_id', $account->id)
                ->where('dedupe_key', $signal->dedupe_key)
                ->count()
        );
    }

    public function test_opportunities_are_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->topicWithMentions($account, $brand, 'Visible Opportunity', 2);

        $otherAccount = Account::query()->create(['name' => 'Hidden Account', 'slug' => 'hidden-account']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Hidden Brand', 'slug' => 'hidden-brand']);
        $this->topicWithMentions($otherAccount, $otherBrand, 'Hidden Opportunity', 4);

        $this->actingAs($user)
            ->get(route('app.intelligence.opportunities'))
            ->assertOk()
            ->assertSee('Visible Opportunity')
            ->assertDontSee('Hidden Opportunity');
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
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function topicWithMentions(Account $account, Brand $brand, string $name, int $count): Topic
    {
        $source = Source::query()->firstOrCreate([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Opportunity News',
        ], [
            'type' => 'news',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $mention = Mention::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'source_id' => $source->id,
                'title' => "{$name} mention {$i}",
                'content' => "{$name} is getting market attention.",
                'sentiment' => 'positive',
                'impact_score' => 70 + $i,
                'published_at' => now()->subDays($i),
            ]);
            $mention->topics()->attach($topic->id, [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'relationship_type' => 'detected',
                'relevance_score' => 90,
            ]);
        }

        return $topic;
    }
}
