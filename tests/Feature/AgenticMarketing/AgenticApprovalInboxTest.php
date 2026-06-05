<?php

use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);
    [$this->organization, $this->workspace, $this->site, $this->user] = makeAgenticApprovalInboxTenant('approval-inbox');

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

it('lists approval required Agentic Action runs with proposal details', function () {
    [$action, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);
    makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site, [
        'status' => AgenticActionRun::STATUS_BLOCKED,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.approvals.index'))
        ->assertOk()
        ->assertSee('Approval Inbox')
        ->assertSee('create article')
        ->assertSee('Create draft content')
        ->assertSee('Primary Site')
        ->assertSee((string) $run->estimated_credits)
        ->assertDontSee('blocked-only-reason');
});

it('stores approver details and approves the linked action', function () {
    [$action, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.approve', $run))
        ->assertRedirect();

    expect($run->fresh()->status)->toBe(AgenticActionRun::STATUS_APPROVED)
        ->and($run->fresh()->approved_by)->toBe($this->user->id)
        ->and($run->fresh()->approved_at)->not->toBeNull()
        ->and($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_APPROVED);
});

it('rejects an action and prevents it from running', function () {
    [$action, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.reject', $run), ['note' => 'Too risky'])
        ->assertRedirect();

    Bus::fake();

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.run', $run->fresh()))
        ->assertRedirect()
        ->assertSessionHas('status', 'Only approved actions with a linked action can be run.');

    Bus::assertNotDispatched(ExecuteAgenticMarketingActionJob::class);
    expect($run->fresh()->status)->toBe(AgenticActionRun::STATUS_REJECTED)
        ->and($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_DISMISSED);
});

it('records requested changes as approval notes', function () {
    [, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.request-changes', $run), [
            'note' => 'Please narrow this to decision-stage buyers.',
        ])
        ->assertRedirect();

    $fresh = $run->fresh();

    expect($fresh->status)->toBe(AgenticActionRun::STATUS_APPROVAL_REQUIRED)
        ->and(data_get($fresh->input_snapshot, 'approval_notes.0.note'))->toBe('Please narrow this to decision-stage buyers.');
});

it('queues an approved action from the inbox', function () {
    [$action, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site, [
        'status' => AgenticActionRun::STATUS_APPROVED,
        'approved_by' => $this->user->id,
        'approved_at' => now(),
    ], [
        'status' => AgenticMarketingAction::STATUS_APPROVED,
    ]);
    Bus::fake();

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.run', $run))
        ->assertRedirect();

    Bus::assertDispatched(ExecuteAgenticMarketingActionJob::class);
    expect($action->fresh()->status)->toBe(AgenticMarketingAction::STATUS_RUNNING)
        ->and($run->fresh()->status)->toBe(AgenticActionRun::STATUS_QUEUED);
});

it('bulk approves only low risk approval runs', function () {
    [, $lowRisk] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site, [
        'estimated_credits' => 5,
    ]);
    [, $highRisk] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site, [
        'estimated_credits' => 24,
        'input_snapshot' => ['payload' => ['planning' => ['risk_level' => 'high']]],
    ]);

    $this->actingAs($this->user)
        ->post(route('app.agentic-marketing.approvals.bulk-approve'), [
            'run_ids' => [(string) $lowRisk->id, (string) $highRisk->id],
        ])
        ->assertRedirect();

    expect($lowRisk->fresh()->status)->toBe(AgenticActionRun::STATUS_APPROVED)
        ->and($highRisk->fresh()->status)->toBe(AgenticActionRun::STATUS_APPROVAL_REQUIRED);
});

it('allows platform admins to view but not approve customer content', function () {
    [, $run] = makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);
    $admin = User::factory()->create([
        'is_admin' => true,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('app.agentic-marketing.approvals.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->post(route('app.agentic-marketing.approvals.approve', $run))
        ->assertForbidden();
});

it('keeps approval inbox tenant isolated', function () {
    makeAgenticApprovalInboxRun($this->organization, $this->workspace, $this->site);
    [$otherOrganization, $otherWorkspace, $otherSite] = makeAgenticApprovalInboxTenant('other-approval-inbox');
    makeAgenticApprovalInboxRun($otherOrganization, $otherWorkspace, $otherSite, [
        'reason' => 'Other tenant only',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.approvals.index'))
        ->assertOk()
        ->assertSee('Create draft content')
        ->assertDontSee('Other tenant only');
});

function makeAgenticApprovalInboxTenant(string $slug): array
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

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function makeAgenticApprovalInboxRun(
    Organization $organization,
    Workspace $workspace,
    ClientSite $site,
    array $runAttributes = [],
    array $actionAttributes = [],
): array {
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Approval inbox objective',
        'goal' => 'Review autonomous content safely.',
        'locale' => 'en',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);

    $opportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Approval inbox opportunity '.Str::lower(Str::random(5)),
        'type' => 'new_article',
        'priority_score' => 45,
        'status' => 'open',
        'payload' => ['signals' => ['topic_keyword' => Str::lower(Str::random(8))]],
    ]);

    $action = AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'opportunity_id' => $opportunity->id,
        'action_type' => 'create_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 8,
        'payload' => [
            'client_site_id' => (string) $site->id,
            'reason' => 'Create draft content',
            'recommendation' => 'Create draft content for approval.',
            'planning' => ['risk_level' => 'low'],
            'proposal_details' => [
                'items' => [
                    ['type' => 'brief', 'text' => 'Generated brief preview'],
                ],
            ],
        ],
    ], $actionAttributes));

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();
    $run->forceFill(array_merge([
        'status' => AgenticActionRun::STATUS_APPROVAL_REQUIRED,
        'reason' => 'Create draft content',
        'estimated_credits' => 8,
        'policy_snapshot' => ['mode' => 'guided'],
        'input_snapshot' => [
            'payload' => $action->payload,
        ],
    ], $runAttributes))->save();

    return [$action, $run->fresh()];
}
