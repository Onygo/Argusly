<?php

use App\Agents\Support\AgentRunStatus;
use App\Enums\AgenticMarketingApprovalMode;
use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgentWorkflowRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingWorkflowOverride;
use App\Models\AgenticMarketingWorkflowRule;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AutonomousMarketingWorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

function makeAutonomousWorkflowScope(): array
{
    $organization = Organization::query()->create([
        'name' => 'Autonomous Workflow Org',
        'slug' => 'autonomous-workflow-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Autonomous Workflow Workspace',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Build agentic marketing demand',
        'goal' => 'Grow authority for Agentic Marketing.',
        'locale' => 'en',
        'audience' => 'Marketing leaders',
        'target_market' => 'B2B SaaS',
        'languages' => ['en'],
        'industry' => 'Marketing technology',
        'priority' => 'high',
        'kpi_type' => 'pipeline',
        'monthly_credit_budget' => 100,
        'brand_entities' => ['Argusly'],
        'competitors' => [],
        'channels' => ['linkedin', 'website'],
        'tone' => 'expert',
        'approval_mode' => AgenticMarketingApprovalMode::Manual->value,
        'status' => 'active',
        'payload' => [],
    ]);

    AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Agentic Marketing operating model article',
        'type' => AgenticMarketingOpportunityType::NewArticle->value,
        'priority_score' => 88,
        'status' => AgenticMarketingOpportunityStatus::Open->value,
        'payload' => [
            'topic' => 'Agentic Marketing',
            'signals' => [
                'suggested_title' => 'Agentic Marketing operating model',
                'topic_keyword' => 'agentic marketing',
                'gap_type' => 'missing_pillar',
            ],
            'score_explanation' => [
                'summary' => 'The workspace needs a pillar article before distribution expands.',
            ],
        ],
    ]);

    return [$organization, $user, $workspace, $objective];
}

it('runs autonomous marketing workflows with approval checkpoints and no autonomous publishing by default', function () {
    Queue::fake();
    [, $user, $workspace, $objective] = makeAutonomousWorkflowScope();

    AgenticMarketingWorkflowRule::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'trigger_type' => 'signal_monitor',
        'minimum_confidence_score' => 70,
        'maximum_actions_per_run' => 5,
        'generate_campaign_proposals' => true,
        'generate_content_drafts' => true,
        'auto_queue_approved_actions' => false,
        'requires_human_approval' => true,
    ]);

    $run = app(AutonomousMarketingWorkflowEngine::class)->run($workspace, 'signal_monitor', [
        'trigger_source' => 'test',
        'objective_id' => (string) $objective->id,
        'topic' => 'Agentic Marketing',
    ], $user);

    expect($run)->toBeInstanceOf(AgentWorkflowRun::class)
        ->and($run->status)->toBe(AgentRunStatus::SUCCESS)
        ->and(data_get($run->output_payload, 'safety.fully_autonomous_publishing_enabled'))->toBeFalse()
        ->and(data_get($run->output_payload, 'safety.publishing_requires_explicit_customer_approval'))->toBeTrue()
        ->and(data_get($run->output_payload, 'approval_checkpoints'))->not->toBeEmpty()
        ->and(data_get($run->output_payload, 'campaign_proposal.created'))->toBeTrue();

    $action = AgenticMarketingAction::query()->where('objective_id', $objective->id)->firstOrFail();
    expect($action->status)->toBe(AgenticMarketingAction::STATUS_PROPOSED)
        ->and(data_get($action->payload, 'automation.workflow_run_id'))->toBe((string) $run->id)
        ->and(data_get($action->payload, 'automation.approval_gate.decision'))->toBe('requires_approval')
        ->and(data_get($action->payload, 'automation.explainability.action_type'))->toBe('create_article');

    expect(Campaign::query()->where('workspace_id', $workspace->id)->exists())->toBeTrue();
    expect(AgenticMarketingAuditLog::query()->where('event', 'workflow.started')->where('subject_id', $run->id)->exists())->toBeTrue();
    expect(AgenticMarketingAuditLog::query()->where('event', 'workflow.completed')->where('subject_id', $run->id)->exists())->toBeTrue();
    Queue::assertNotPushed(ExecuteAgenticMarketingActionJob::class);
});

it('honors human pause overrides before planning work', function () {
    [, $user, $workspace] = makeAutonomousWorkflowScope();

    AgenticMarketingWorkflowOverride::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'user_id' => $user->id,
        'override_type' => AgenticMarketingWorkflowOverride::TYPE_PAUSE_WORKFLOW,
        'reason' => 'Leadership review window.',
    ]);

    $run = app(AutonomousMarketingWorkflowEngine::class)->run($workspace, 'signal_monitor', [
        'trigger_source' => 'test',
        'topic' => 'Agentic Marketing',
    ], $user);

    expect($run->status)->toBe(AgentRunStatus::SKIPPED)
        ->and($run->summary)->toContain('paused')
        ->and(AgenticMarketingAction::query()->count())->toBe(0)
        ->and(Campaign::query()->count())->toBe(0)
        ->and(AgenticMarketingAuditLog::query()->where('event', 'workflow.skipped')->where('subject_id', $run->id)->exists())->toBeTrue();
});

it('renders the workflow governance UI', function () {
    [, $user, $workspace] = makeAutonomousWorkflowScope();

    $this->actingAs($user)
        ->get(route('app.agentic-marketing.workflows.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Autonomous Workflow Orchestration')
        ->assertSee('Automation Policy')
        ->assertSee('Human Overrides');
});
