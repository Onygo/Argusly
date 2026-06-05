<?php

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires approval for guided content changes until customer approval is present', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'estimated_credit_impact' => 4]
    );

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['requires_approval'])->toBeTrue()
        ->and($decision['blocked'])->toBeFalse()
        ->and($decision['required_approval_type'])->toBe('guided_customer_approval');

    $approved = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'has_customer_approval' => true]
    );

    expect($approved['allowed'])->toBeTrue()
        ->and($approved['requires_approval'])->toBeFalse();
});

it('requires approval in autonomous mode when an action type is not explicitly enabled', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'autonomous_internal_linking_enabled' => false,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_ADD_INTERNAL_LINKS,
        $workspace,
        ['site_id' => (string) $site->id]
    );

    expect($decision['requires_approval'])->toBeTrue()
        ->and($decision['required_approval_type'])->toBe('action_type_not_enabled');
});

it('allows explicitly enabled autonomous actions inside the configured site and limits', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'max_autonomous_credits_per_month' => 500,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'estimated_credit_impact' => 8, 'priority_score' => 40]
    );

    expect($decision['allowed'])->toBeTrue()
        ->and(data_get($decision, 'policy_snapshot.mode'))->toBe('autonomous');
});

it('requires approval for new pages when configured', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_brief_generation_enabled' => true,
        'require_approval_for_new_pages' => true,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_CREATE_NEW_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'estimated_credit_impact' => 10]
    );

    expect($decision['requires_approval'])->toBeTrue()
        ->and($decision['required_approval_type'])->toBe('new_page_approval');
});

it('requires approval for external publication when configured', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_publication_enabled' => true,
        'require_approval_for_external_publication' => true,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_PUBLISH_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'is_external_publication' => true]
    );

    expect($decision['requires_approval'])->toBeTrue()
        ->and($decision['required_approval_type'])->toBe('external_publication_approval');
});

it('requires approval for high priority autonomous actions', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'require_approval_above_priority_score' => 60,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_RUN_AI_VISIBILITY_REFRESH,
        $workspace,
        ['site_id' => (string) $site->id, 'priority_score' => 90, 'estimated_credit_impact' => 5]
    );

    expect($decision['requires_approval'])->toBeTrue()
        ->and($decision['required_approval_type'])->toBe('priority_threshold');
});

it('requires approval for high cost autonomous actions before spending credits', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'max_autonomous_credits_per_month' => 1000,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'estimated_credit_impact' => 300, 'priority_score' => 30]
    );

    expect($decision['requires_approval'])->toBeTrue()
        ->and($decision['required_approval_type'])->toBe('high_credit_impact')
        ->and($decision['estimated_credit_impact'])->toBe(300);
});

it('blocks execution when autonomous monthly credit limits would be exceeded', function () {
    [$workspace, $site, $organization] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'max_autonomous_credits_per_month' => 100,
    ]);
    $objective = makeAgenticApprovalGateObjective($organization, $workspace, $site);
    AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'action_type' => 'refresh_article',
        'status' => AgenticMarketingAction::STATUS_COMPLETED,
        'estimated_credits' => 90,
        'credits_captured' => 90,
        'payload' => ['client_site_id' => (string) $site->id],
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'estimated_credit_impact' => 20, 'priority_score' => 30]
    );

    expect($decision['blocked'])->toBeTrue()
        ->and($decision['reason'])->toContain('credit limit');
});

it('blocks autonomous execution without a selected publishing site', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['estimated_credit_impact' => 5]
    );

    expect($decision['blocked'])->toBeTrue()
        ->and($decision['reason'])->toContain('publishing site');
});

it('blocks unsafe or incomplete content', function () {
    [$workspace, $site] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
    ]);

    $unsafe = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'unsafe' => true]
    );
    $incomplete = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'content_complete' => false]
    );

    expect($unsafe['blocked'])->toBeTrue()
        ->and($incomplete['blocked'])->toBeTrue()
        ->and($unsafe['reason'])->toContain('unsafe or incomplete');
});

it('maps existing Agentic Marketing action records through the gate', function () {
    [$workspace, $site, $organization] = makeAgenticApprovalGateScope();
    makeAgenticApprovalGateSettings($workspace, $site, [
        'autonomous_refresh_enabled' => true,
        'max_autonomous_credits_per_month' => 500,
    ]);
    $objective = makeAgenticApprovalGateObjective($organization, $workspace, $site);
    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Refreshable content',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
    ]);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'content_id' => (string) $content->id,
        'action_type' => 'refresh_article',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'estimated_credits' => 10,
        'payload' => ['content_id' => (string) $content->id, 'client_site_id' => (string) $site->id],
    ]);

    $decision = app(AgenticApprovalGate::class)->forMarketingAction($action, [
        'has_customer_approval' => true,
    ]);

    expect($decision['allowed'])->toBeTrue()
        ->and($decision['action'])->toBe(AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT);
});

function makeAgenticApprovalGateScope(string $slug = 'agentic-approval-gate'): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Approval Gate Site',
        'site_url' => 'https://'.$slug.'.example.test',
        'base_url' => 'https://'.$slug.'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site, $organization];
}

function makeAgenticApprovalGateSettings(Workspace $workspace, ClientSite $site, array $attributes = []): AgenticMarketingExecutionSetting
{
    return AgenticMarketingExecutionSetting::query()->create(array_merge([
        'organization_id' => (int) $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
        'autonomous_publication_enabled' => false,
        'autonomous_refresh_enabled' => false,
        'autonomous_internal_linking_enabled' => false,
        'autonomous_brief_generation_enabled' => false,
        'autonomous_chained_plans_enabled' => false,
        'max_autonomous_actions_per_day' => 5,
        'max_autonomous_credits_per_month' => 100,
        'require_approval_above_priority_score' => 80,
        'require_approval_for_new_pages' => true,
        'require_approval_for_external_publication' => true,
        'allowed_site_ids' => [(string) $site->id],
        'allowed_publishing_destination_ids' => [],
        'notification_email_enabled' => true,
    ], $attributes));
}

function makeAgenticApprovalGateObjective(Organization $organization, Workspace $workspace, ClientSite $site): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => (int) $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Approval gate objective',
        'goal' => 'Govern Agentic Marketing execution.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
}
