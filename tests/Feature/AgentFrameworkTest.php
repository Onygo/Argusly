<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\AgentManager;
use App\Services\AgentRunner;
use App\Services\AgentTaskDispatcher;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_dashboard_initializes_and_shows_default_agents(): void
    {
        [$user] = $this->tenantWithRole('owner');

        $this->actingAs($user)
            ->get(route('app.agents'))
            ->assertOk()
            ->assertSee('Agent framework')
            ->assertSee('Content Agent')
            ->assertSee('SEO Agent')
            ->assertSee('Visibility Agent')
            ->assertSee('Research Agent')
            ->assertSee('Social Agent')
            ->assertSee('Campaign Agent')
            ->assertSee('Lifecycle Agent')
            ->assertSee('Competitor Agent');

        $this->assertDatabaseCount('agents', 8);
    }

    public function test_runner_and_dispatcher_create_placeholder_runs_and_tasks(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $manager = app(AgentManager::class);
        $agent = $manager->findAgent('content');

        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Refresh article',
            'summary' => 'Refresh a stale article.',
            'recommended_action' => 'Rewrite the article with current evidence.',
            'impact_score' => 80,
            'confidence_score' => 90,
            'status' => 'new',
        ]);

        $run = app(AgentRunner::class)->runPlaceholder($agent, $account, $brand);
        $task = app(AgentTaskDispatcher::class)->dispatchRecommendation($agent, $recommendation, $run);

        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->result['placeholder']);
        $this->assertSame('dispatched', $task->status);
        $this->assertSame($recommendation->id, $task->recommendation_id);
        $this->assertSame($run->id, $task->agent_run_id);

        $this->actingAs($user)
            ->get(route('app.agents'))
            ->assertOk()
            ->assertSee('Latest runs')
            ->assertSee('Refresh article');
    }

    public function test_agent_runs_are_tenant_and_brand_scoped(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $agent = app(AgentManager::class)->findAgent('research');

        app(AgentRunner::class)->runPlaceholder($agent, $account, $brand, ['label' => 'visible-run']);
        app(AgentRunner::class)->runPlaceholder($agent, $account, $otherBrand, ['label' => 'hidden-run']);

        $this->actingAs($user)
            ->get(route('app.agents'))
            ->assertOk()
            ->assertSee('Alpha Brand')
            ->assertDontSee('Other Brand');
    }

    public function test_agents_route_requires_agentic_module(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Starter Account', 'slug' => 'starter-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Starter Brand', 'slug' => 'starter-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $this->actingAs($user)
            ->get(route('app.agents'))
            ->assertForbidden();
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
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$user, $account, $brand];
    }
}
