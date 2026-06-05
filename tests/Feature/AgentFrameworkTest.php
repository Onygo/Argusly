<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\AgenticMarketingWorkflowService;
use App\Services\AgentManager;
use App\Services\AgentRunner;
use App\Services\AgentTaskDispatcher;
use App\Services\AgentTaskPlannerService;
use App\Services\ApprovalService;
use App\Services\CreditService;
use App\Services\RecommendationActionService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
            ->assertSee('Competitor Agent')
            ->assertSee('Monitoring Agent');

        $this->assertDatabaseCount('agents', 9);
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

    public function test_accepted_recommendation_plans_task_for_matching_agent(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create social post',
            'summary' => 'Repurpose this article.',
            'recommended_action' => 'Create a social post from this recommendation.',
            'action_type' => 'create_social_post',
            'action_payload' => ['content_asset_id' => 123],
            'status' => 'new',
        ]);

        app(RecommendationActionService::class)->accept($recommendation, $user);

        $task = AgentTask::query()->where('recommendation_id', $recommendation->id)->firstOrFail();

        $this->assertSame('Social Agent', $task->agent->name);
        $this->assertSame('pending', $task->status);
        $this->assertSame('create_social_post', $task->payload['action_type']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'AgentTaskPlanned',
            'subject_id' => $task->id,
        ]);

        app(RecommendationActionService::class)->accept($recommendation->refresh(), $user);
        $this->assertSame(1, AgentTask::query()->where('recommendation_id', $recommendation->id)->count());
    }

    public function test_agent_task_planner_maps_action_types_to_expected_agents(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $expected = [
            'refresh_content' => 'Content Agent',
            'create_answer_block' => 'SEO Agent',
            'translate_content' => 'Content Agent',
            'create_social_post' => 'Social Agent',
            'run_visibility_check' => 'Visibility Agent',
            'reconnect_integration' => 'Monitoring Agent',
        ];

        foreach ($expected as $actionType => $agentName) {
            $recommendation = Recommendation::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'title' => str($actionType)->headline()->toString(),
                'summary' => 'Planned action.',
                'recommended_action' => 'Plan this agent task.',
                'action_type' => $actionType,
                'status' => 'accepted',
            ]);

            $task = app(AgentTaskPlannerService::class)->planForRecommendation($recommendation, $user);

            $this->assertSame($agentName, $task->agent->name);
        }
    }

    public function test_agent_task_can_be_approved_queued_completed_or_failed(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $planner = app(AgentTaskPlannerService::class);
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Refresh content',
            'summary' => 'Refresh a stale article.',
            'recommended_action' => 'Prepare a content refresh task.',
            'action_type' => 'refresh_content',
            'status' => 'accepted',
        ]);
        $task = $planner->planForRecommendation($recommendation, $user);

        $this->assertSame('approved', $planner->approve($task, $user)->status);
        $this->assertSame('queued', $planner->queue($task->refresh(), $user)->status);
        $this->assertSame('completed', $planner->complete($task->refresh(), $user, ['message' => 'No AI execution yet.'])->status);

        $failedRecommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Visibility check',
            'summary' => 'Run a visibility check.',
            'recommended_action' => 'Prepare a visibility task.',
            'action_type' => 'run_visibility_check',
            'status' => 'accepted',
        ]);
        $failedTask = $planner->planForRecommendation($failedRecommendation, $user);

        $this->assertSame('failed', $planner->fail($failedTask, $user, 'Provider unavailable.')->status);
        $this->assertSame('Provider unavailable.', $failedTask->refresh()->payload['failure_reason']);
    }

    public function test_agent_task_planning_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [, $otherAccount, $otherBrand] = $this->tenantWithRole('owner');
        $foreignRecommendation = Recommendation::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Foreign task',
            'summary' => 'Should not be planned.',
            'recommended_action' => 'Do not expose.',
            'action_type' => 'refresh_content',
            'status' => 'accepted',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(AgentTaskPlannerService::class)->planForRecommendation($foreignRecommendation, $user);
    }

    public function test_agentic_workflow_plans_recommendations_with_approval_and_audit_trail(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create campaign task plan',
            'summary' => 'Campaign needs coordinated work.',
            'recommended_action' => 'Create a task plan for the launch campaign.',
            'action_type' => 'create_campaign_task_plan',
            'status' => 'new',
            'impact_score' => 82,
            'confidence_score' => 88,
        ]);

        $this->actingAs($user)
            ->get(route('app.agents'))
            ->assertOk()
            ->assertSee('Workflow engine')
            ->assertSee('Planning engine')
            ->assertSee('Create campaign task plan');

        $this->actingAs($user)
            ->post(route('app.agents.recommendations.plan', $recommendation))
            ->assertRedirect(route('app.agents.tasks'));

        $task = AgentTask::query()->where('recommendation_id', $recommendation->id)->firstOrFail();

        $this->assertSame('Content Agent', $task->agent->name);
        $this->assertSame('pending', $task->status);
        $this->assertDatabaseHas('approvals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => AgentTask::class,
            'subject_id' => $task->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'AgenticWorkflowPlanned',
            'subject_type' => AgentTask::class,
            'subject_id' => $task->id,
        ]);
    }

    public function test_approved_agentic_task_can_be_queued_run_and_monitored(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Run visibility check',
            'summary' => 'Visibility needs a follow-up run.',
            'recommended_action' => 'Run a visibility follow-up task.',
            'action_type' => 'run_visibility_check',
            'status' => 'accepted',
        ]);
        $task = app(AgenticMarketingWorkflowService::class)->planRecommendation($recommendation, $user);
        $approval = Approval::query()
            ->where('subject_type', AgentTask::class)
            ->where('subject_id', $task->id)
            ->firstOrFail();

        app(ApprovalService::class)->approve($approval, $user, 'Ready.');

        $this->actingAs($user)
            ->post(route('app.agents.tasks.queue', $task->refresh()))
            ->assertRedirect();
        $this->assertSame('queued', $task->refresh()->status);

        $this->actingAs($user)
            ->post(route('app.agents.tasks.run', $task->refresh()))
            ->assertRedirect(route('app.agents.runs'));

        $task->refresh();
        $run = AgentRun::query()->where('id', $task->agent_run_id)->firstOrFail();

        $this->assertSame('completed', $task->status);
        $this->assertSame('completed', $run->status);
        $this->assertSame('guarded', $run->result['runtime']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'AgentRunStarted',
            'subject_id' => $run->id,
        ]);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'AgenticWorkflowCompleted',
            'subject_id' => $task->id,
        ]);

        $this->actingAs($user)
            ->get(route('app.agents.runs'))
            ->assertOk()
            ->assertSee('Run monitoring')
            ->assertSee('Visibility Agent');
    }

    public function test_briefing_can_seed_agentic_planning_workflow(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $briefing = Briefing::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Launch narrative briefing',
            'objective' => 'Prepare campaign content from the approved narrative.',
            'audience' => 'Enterprise buyers',
            'tone_of_voice' => 'Clear',
            'key_message' => 'Argusly turns intelligence into action.',
            'channels' => ['blog', 'linkedin'],
            'languages' => ['en'],
            'status' => 'approved',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('app.agents.briefings.plan', $briefing))
            ->assertRedirect(route('app.agents.tasks'));

        $task = AgentTask::query()->where('title', 'Prepare content plan for Launch narrative briefing')->firstOrFail();

        $this->assertSame('Content Agent', $task->agent->name);
        $this->assertSame('briefing_to_plan', $task->payload['workflow']);
        $this->assertSame($briefing->id, $task->payload['briefing_id']);
        $this->assertDatabaseHas('approvals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => AgentTask::class,
            'subject_id' => $task->id,
            'status' => 'pending',
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
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');
        app(CreditService::class)->grant($account, 5000, $user, 'Test LLM credits');

        return [$user, $account, $brand];
    }
}
