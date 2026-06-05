<?php

use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);

    [$this->organization, $this->workspace, $this->user, $this->site] = makeAgenticActionRunTenant('agentic-action-run');

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

it('creates an audit record when an Agentic Marketing action is proposed', function () {
    $objective = makeAgenticActionRunObjective($this->organization, $this->workspace);

    $action = makeAgenticActionRunAction($objective, [
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 12,
    ]);

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($run->workspace_id)->toBe($this->workspace->id)
        ->and($run->goal_id)->toBe($objective->id)
        ->and($run->status)->toBe(AgenticActionRun::STATUS_PROPOSED)
        ->and($run->estimated_credits)->toBe(12);
});

it('marks autonomous action decisions as agent-executed when no customer approval is present', function () {
    $objective = makeAgenticActionRunObjective($this->organization, $this->workspace, [
        'client_site_id' => $this->site->id,
    ]);
    $action = makeAgenticActionRunAction($objective, [
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 5,
        'payload' => ['client_site_id' => (string) $this->site->id],
    ]);
    makeAgenticActionRunAutonomousSettings($this->workspace, $this->site);

    $decision = app(AgenticApprovalGate::class)->forMarketingAction($action, [
        'has_customer_approval' => false,
    ]);
    app(AgenticActionRunLogger::class)->recordGateDecision($action, $decision);

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($decision['allowed'])->toBeTrue()
        ->and($run->execution_mode_snapshot)->toBe('autonomous')
        ->and($run->executed_by_agent)->toBeTrue()
        ->and($run->status)->toBe(AgenticActionRun::STATUS_APPROVED);
});

it('stores the approving user when a customer approves an action', function () {
    $objective = makeAgenticActionRunObjective($this->organization, $this->workspace);
    $action = makeAgenticActionRunAction($objective, [
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.actions.approve', $action))
        ->assertRedirect();

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($run->status)->toBe(AgenticActionRun::STATUS_APPROVED)
        ->and($run->approved_by)->toBe($this->user->id)
        ->and($run->approved_at)->not->toBeNull();
});

it('updates the audit record when a queued action job fails', function () {
    $objective = makeAgenticActionRunObjective($this->organization, $this->workspace);
    $action = makeAgenticActionRunAction($objective, [
        'status' => AgenticMarketingAction::STATUS_RUNNING,
        'execution_claim_id' => 'claim-123',
        'execution_claimed_at' => now(),
    ]);

    $job = new ExecuteAgenticMarketingActionJob((string) $action->id, $this->user->id, 'claim-123');
    $job->failed(new RuntimeException('Provider timed out'));

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($run->status)->toBe(AgenticActionRun::STATUS_FAILED)
        ->and($run->error_message)->toContain('Provider timed out');
});

it('keeps customer recent run UI isolated by tenant', function () {
    $ownObjective = makeAgenticActionRunObjective($this->organization, $this->workspace, ['name' => 'Own objective']);
    makeAgenticActionRunAction($ownObjective, [
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'action_type' => 'update_meta',
    ]);

    [$otherOrganization, $otherWorkspace] = makeAgenticActionRunTenant('other-agentic-action-run');
    $otherObjective = makeAgenticActionRunObjective($otherOrganization, $otherWorkspace, ['name' => 'Other objective']);
    makeAgenticActionRunAction($otherObjective, [
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'action_type' => 'refresh_article',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index'))
        ->assertOk()
        ->assertSee('update meta')
        ->assertDontSee('Other objective');
});

function makeAgenticActionRunTenant(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug.' workspace',
        'display_name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Primary Site',
        'site_url' => 'https://'.$slug.'.example.test',
        'base_url' => 'https://'.$slug.'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $user, $site];
}

function makeAgenticActionRunObjective(Organization $organization, Workspace $workspace, array $attributes = []): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Agentic action run objective',
        'goal' => 'Improve AI visibility with governed execution.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ], $attributes));
}

function makeAgenticActionRunAction(AgenticMarketingObjective $objective, array $attributes = []): AgenticMarketingAction
{
    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'action_type' => 'refresh_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 4,
        'payload' => [
            'reason' => 'Refresh the content for stronger entity coverage.',
            'recommendation' => 'Update answer-focused sections.',
        ],
    ], $attributes));
}

function makeAgenticActionRunAutonomousSettings(Workspace $workspace, ClientSite $site): AgenticMarketingExecutionSetting
{
    return AgenticMarketingExecutionSetting::query()->create(array_merge(
        AgenticMarketingExecutionSetting::defaultsFor($workspace)->getAttributes(),
        [
            'workspace_id' => $workspace->id,
            'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
            'autonomous_refresh_enabled' => true,
            'max_autonomous_credits_per_month' => 500,
            'allowed_site_ids' => [(string) $site->id],
        ]
    ));
}
