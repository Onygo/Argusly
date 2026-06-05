<?php

use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('supports dry run without claiming or dispatching actions', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('autonomous-dry-run');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $opportunity = makeAutonomousAgenticOpportunity($objective);
    $action = makeAutonomousAgenticAction($objective, $opportunity);
    makeAutonomousAgenticSettings($workspace, $site);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--dry-run' => true, '--workspace' => (string) $workspace->id])
        ->expectsOutputToContain('[dry-run] Would dispatch')
        ->assertSuccessful();

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_PROPOSED)
        ->and(AgenticActionRun::query()->where('action_id', $action->id)->where('status', AgenticActionRun::STATUS_QUEUED)->exists())->toBeFalse();
});

it('respects per-run and daily autonomous action limits', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('autonomous-limit');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $firstOpportunity = makeAutonomousAgenticOpportunity($objective, ['title' => 'First opportunity']);
    $secondOpportunity = makeAutonomousAgenticOpportunity($objective, ['title' => 'Second opportunity']);
    makeAutonomousAgenticAction($objective, $firstOpportunity);
    makeAutonomousAgenticAction($objective, $secondOpportunity);
    makeAutonomousAgenticSettings($workspace, $site, ['max_autonomous_actions_per_day' => 2]);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id, '--limit' => 1])
        ->assertSuccessful();

    Bus::assertDispatchedTimes(ExecuteAgenticMarketingActionJob::class, 1);

    AgenticActionRun::query()->create([
        'workspace_id' => (string) $workspace->id,
        'action_type' => 'create_article',
        'execution_mode_snapshot' => 'autonomous',
        'status' => AgenticActionRun::STATUS_QUEUED,
        'executed_by_agent' => true,
    ]);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->expectsOutputToContain('daily autonomous action limit reached')
        ->assertSuccessful();

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
});

it('avoids creating duplicate actions for the same opportunity', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('autonomous-duplicates');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $opportunity = makeAutonomousAgenticOpportunity($objective);
    makeAutonomousAgenticSettings($workspace, $site);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    expect(AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id)->count())->toBe(1);
});

it('blocks work when the autonomous monthly credit limit would be exceeded', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('autonomous-credit-limit');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $opportunity = makeAutonomousAgenticOpportunity($objective);
    makeAutonomousAgenticAction($objective, $opportunity, ['estimated_credits' => 24]);
    makeAutonomousAgenticAction($objective, makeAutonomousAgenticOpportunity($objective, ['title' => 'Spent opportunity']), [
        'status' => AgenticMarketingAction::STATUS_COMPLETED,
        'credits_captured' => 20,
    ]);
    makeAutonomousAgenticSettings($workspace, $site, ['max_autonomous_credits_per_month' => 25]);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
    expect(AgenticActionRun::query()->where('action_id', $opportunity->actions()->first()?->id)->where('status', AgenticActionRun::STATUS_BLOCKED)->exists())->toBeTrue();
});

it('ignores guided mode workspaces', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('guided-ignored');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $opportunity = makeAutonomousAgenticOpportunity($objective);
    makeAutonomousAgenticAction($objective, $opportunity);
    AgenticMarketingExecutionSetting::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => (string) $workspace->id,
        'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_GUIDED,
    ]);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->expectsOutputToContain('No autonomous Agentic Marketing workspaces matched')
        ->assertSuccessful();

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
});

it('dispatches allowed autonomous work to the agentic marketing queue', function () {
    [$organization, $workspace, $site] = makeAutonomousAgenticTenant('autonomous-executed');
    $objective = makeAutonomousAgenticObjective($organization, $workspace, $site);
    $opportunity = makeAutonomousAgenticOpportunity($objective);
    makeAutonomousAgenticSettings($workspace, $site);
    Bus::fake();

    $this->artisan('agentic:run-autonomous', ['--workspace' => (string) $workspace->id])
        ->assertSuccessful();

    Bus::assertDispatched(ExecuteAgenticMarketingActionJob::class, fn (ExecuteAgenticMarketingActionJob $job): bool => $job->queue === 'agentic-marketing');

    $action = AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id)->firstOrFail();
    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($action->status)->toBe(AgenticMarketingAction::STATUS_RUNNING)
        ->and($run->status)->toBe(AgenticActionRun::STATUS_QUEUED)
        ->and($run->execution_mode_snapshot)->toBe('autonomous')
        ->and($run->executed_by_agent)->toBeTrue();
});

function makeAutonomousAgenticTenant(string $slug): array
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

    return [$organization, $workspace, $site];
}

function makeAutonomousAgenticObjective(Organization $organization, Workspace $workspace, ClientSite $site): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Autonomous objective',
        'goal' => 'Grow AI visibility with safe autonomous execution.',
        'locale' => 'en',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
}

function makeAutonomousAgenticOpportunity(AgenticMarketingObjective $objective, array $attributes = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_merge([
        'objective_id' => $objective->id,
        'title' => 'Autonomous article opportunity',
        'type' => 'new_article',
        'priority_score' => 40,
        'status' => 'open',
        'payload' => [
            'signals' => [
                'topic_keyword' => Str::slug((string) ($attributes['title'] ?? 'agentic marketing automation')),
                'suggested_title' => (string) ($attributes['title'] ?? 'Agentic Marketing Automation Guide'),
            ],
        ],
    ], $attributes));
}

function makeAutonomousAgenticAction(AgenticMarketingObjective $objective, AgenticMarketingOpportunity $opportunity, array $attributes = []): AgenticMarketingAction
{
    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 12,
        'payload' => [
            'client_site_id' => (string) $objective->client_site_id,
            'title' => 'Agentic Marketing Automation Guide',
            'reason' => 'Create draft content for a high-fit opportunity.',
        ],
    ], $attributes));
}

function makeAutonomousAgenticSettings(Workspace $workspace, ClientSite $site, array $attributes = []): AgenticMarketingExecutionSetting
{
    return AgenticMarketingExecutionSetting::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
        'autonomous_brief_generation_enabled' => true,
        'max_autonomous_actions_per_day' => 5,
        'max_autonomous_credits_per_month' => 500,
        'require_approval_above_priority_score' => 90,
        'require_approval_for_new_pages' => false,
        'require_approval_for_external_publication' => true,
        'allowed_site_ids' => [(string) $site->id],
        'notification_email_enabled' => true,
    ], $attributes));
}
