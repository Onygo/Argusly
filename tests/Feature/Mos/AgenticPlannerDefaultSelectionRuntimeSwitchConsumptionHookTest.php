<?php

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase3zContext(string $slug = 'phase-3z'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 3Z '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 3Z Workspace',
        'display_name' => 'Phase 3Z Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 3Z Site',
        'site_url' => 'https://phase-3z.test',
        'base_url' => 'https://phase-3z.test',
        'allowed_domains' => ['phase-3z.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3Z objective '.Str::random(4),
        'goal' => 'Consume the runtime switch decision without switching',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase3zOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 3Z runtime switch consumption',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 3Z '.Str::lower(Str::random(8)),
            'reasoning' => 'Runtime switch consumption is diagnostic-only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 3Z'],
        ],
    ], $overrides));
}

function phase3zSwitchDecision(string $decision, array $blockedReasons = []): array
{
    return [
        'phase' => '3Y',
        'mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::MODE,
        'phase_3t_status' => 'ready_for_scoped_expansion',
        'phase_3u_eligibility' => 'eligible',
        'phase_3v_guard_allowed' => true,
        'phase_3w_selected_planner_remains' => 'legacy',
        'phase_3x_contract_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY,
        'switch_flag_enabled' => true,
        'runtime_guard_flag_enabled' => true,
        'switch_decision' => $decision,
        'blocked_reasons' => $blockedReasons,
        'rollback_mode' => 'legacy_first',
        'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
        'payload_namespace' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE,
        'payload_version' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION,
        'runtime_switching_implemented' => false,
        'planner_selection_changed' => false,
        'planner_output_changed' => false,
    ];
}

function phase3zSwitchService(array $decision): AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
{
    return new class($decision) extends AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
    {
        public int $calls = 0;

        public array $receivedInput = [];

        public function __construct(private readonly array $decision) {}

        public function decide(array $input): array
        {
            $this->calls++;
            $this->receivedInput = $input;

            return $this->decision;
        }
    };
}

function phase3zWriteReadyAuditSnapshot(Workspace $workspace, AgenticMarketingObjective $objective, array $overrides = []): AgenticPlannerDefaultSelectionRuntimeSwitchAudit
{
    return AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->create(array_replace_recursive([
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => [(string) $objective->id],
        'phase_3t_status' => 'ready_for_scoped_expansion',
        'phase_3u_eligibility' => 'eligible',
        'phase_3v_guard_allowed' => true,
        'phase_3w_selected_planner_remains' => 'legacy',
        'phase_3x_contract_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY,
        'switch_flag_enabled' => true,
        'runtime_guard_flag_enabled' => true,
        'switch_decision' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY,
        'blocked_reasons' => [],
        'operator_acknowledgements' => ['runtime_switch_contract' => true],
        'rollback_mode' => 'legacy_first',
        'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
        'payload_namespace' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE,
        'payload_version' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION,
        'payload' => phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY),
        'created_at' => now(),
    ], $overrides));
}

it('does not resolve or call the switch service when the switch flag is false and keeps legacy output', function (): void {
    [, , , $objective] = phase3zContext('phase-3z-flag-false');
    $legacy = phase3zOpportunity($objective);
    $resolved = false;
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', false);
    app()->bind(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, function () use (&$resolved): AgenticPlannerDefaultSelectionScopedRuntimeSwitchService {
        $resolved = true;

        throw new RuntimeException('Phase 3Z must not resolve the switch service when the switch flag is false.');
    });

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($resolved)->toBeFalse()
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and(data_get($action->payload, 'planning.planner'))->toBe(AgenticMarketingActionPlanner::class)
        ->and($diagnostics['consumption_hook_called'])->toBeTrue()
        ->and($diagnostics['switch_service_called'])->toBeFalse()
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['selected_action_ownership_mode'])->toBe('legacy_owned')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(0);
});

it('calls the switch service when enabled but keeps legacy output when the decision is blocked', function (): void {
    [, $workspace, $site, $objective] = phase3zContext('phase-3z-blocked');
    $legacy = phase3zOpportunity($objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(
        AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
        ['phase_3x_contract_not_ready:contract_blocked']
    ));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($switch->calls)->toBe(1)
        ->and($switch->receivedInput)->toBe([
            'workspace' => (string) $workspace->id,
            'objectives' => [(string) $objective->id],
            'site' => (string) $site->id,
            'detector' => 'content_network_gaps',
            'limit' => 1,
        ])
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($diagnostics['switch_service_called'])->toBeTrue()
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED)
        ->and($diagnostics['blocked_reasons'])->toContain('phase_3x_contract_not_ready:contract_blocked')
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse();
});

it('keeps legacy output and blocks ready consumption when no audit snapshot exists', function (): void {
    [, , , $objective] = phase3zContext('phase-3z-ready-no-audit');
    $legacy = phase3zOpportunity($objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($switch->calls)->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($diagnostics['pre_switch_audit_snapshot_present'])->toBeFalse()
        ->and($diagnostics['blocked_reasons'])->toContain('pre_switch_audit_snapshot_missing')
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_AUDIT_MISSING)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(0);
});

it('keeps legacy output and reports ready consumed only when a matching audit snapshot exists', function (): void {
    [, $workspace, , $objective] = phase3zContext('phase-3z-ready-with-audit');
    $legacy = phase3zOpportunity($objective);
    phase3zWriteReadyAuditSnapshot($workspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($switch->calls)->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($diagnostics['pre_switch_audit_snapshot_present'])->toBeTrue()
        ->and($diagnostics['blocked_reasons'])->toBe([])
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['selected_action_ownership_mode'])->toBe('legacy_owned')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(1);
});

it('does not use an enabled activation flag to switch planner output', function (): void {
    [, $workspace, , $objective] = phase3zContext('phase-4b-activation-enabled-runtime');
    $legacy = phase3zOpportunity($objective);
    phase3zWriteReadyAuditSnapshot($workspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($switch->calls)->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and(data_get($action->payload, 'planning.planner'))->toBe(AgenticMarketingActionPlanner::class)
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and($diagnostics['planner_output_changed'])->toBeFalse()
        ->and($diagnostics['canonical_planner_output_selected'])->toBeFalse()
        ->and($diagnostics['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(1);
});

it('requires an exact workspace and objective scope match before consuming a ready audit snapshot', function (): void {
    [$organization, $workspace, $site, $objective] = phase3zContext('phase-3z-audit-exact-scope');
    $legacy = phase3zOpportunity($objective);
    $otherObjective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 3Z other objective '.Str::random(4),
        'goal' => 'Do not satisfy exact scope matching',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);
    [, $otherWorkspace] = phase3zContext('phase-3z-audit-other-workspace');
    phase3zWriteReadyAuditSnapshot($workspace, $otherObjective);
    phase3zWriteReadyAuditSnapshot($otherWorkspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    $action = AgenticMarketingAction::query()->firstOrFail();

    expect($switch->calls)->toBe(1)
        ->and($summary['created'])->toBe(1)
        ->and($action->opportunity_id)->toBe($legacy->id)
        ->and($diagnostics['switch_decision'])->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and($diagnostics['pre_switch_audit_snapshot_present'])->toBeFalse()
        ->and($diagnostics['blocked_reasons'])->toContain('pre_switch_audit_snapshot_missing')
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_AUDIT_MISSING)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['selected_action_ownership_mode'])->toBe('legacy_owned')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(2);
});

it('records a safe in-process empty-scope diagnostic when no legacy candidate chunk is loaded', function (): void {
    [, , , $objective] = phase3zContext('phase-3z-no-candidates');
    app()->forgetInstance(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', false);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);

    expect($summary['opportunities'])->toBe(0)
        ->and($summary['created'])->toBe(0)
        ->and($summary['reused'])->toBe(0)
        ->and($summary['skipped'])->toBe(0)
        ->and($switch->calls)->toBe(0)
        ->and($diagnostics['empty_scope_diagnostic_recorded'])->toBeTrue()
        ->and($diagnostics['workspace_id'])->toBe((string) $objective->workspace_id)
        ->and($diagnostics['objective_ids'])->toBe([(string) $objective->id])
        ->and($diagnostics['legacy_candidate_count'])->toBe(0)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and($diagnostics['activation_blocked_reason'])->toBe('no_legacy_candidate_scope')
        ->and($diagnostics['switch_service_called'])->toBeFalse()
        ->and($diagnostics['activation_flag_enabled'])->toBeFalse()
        ->and($diagnostics['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED)
        ->and(AgenticMarketingAction::query()->count())->toBe(0)
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(0);
});

it('records the empty-scope diagnostic in process only when the hook receives no candidates', function (): void {
    Bus::fake();
    [, , , $objective] = phase3zContext('phase-4b-empty-hook-only');
    app()->forgetInstance(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);
    $before = [
        'actions' => AgenticMarketingAction::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'canonical' => Opportunity::query()->count(),
        'action_runs' => AgenticActionRun::query()->count(),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
    ];

    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::class)
        ->consumeObjectiveLegacyCandidates($objective, collect());

    expect(app()->bound(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY))->toBeTrue()
        ->and(app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY))->toBe($diagnostics)
        ->and($switch->calls)->toBe(0)
        ->and($diagnostics['empty_scope_diagnostic_recorded'])->toBeTrue()
        ->and($diagnostics['workspace_id'])->toBe((string) $objective->workspace_id)
        ->and($diagnostics['objective_ids'])->toBe([(string) $objective->id])
        ->and($diagnostics['legacy_candidate_count'])->toBe(0)
        ->and($diagnostics['selected_planner_remains'])->toBe('legacy')
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and($diagnostics['activation_blocked_reason'])->toBe('no_legacy_candidate_scope')
        ->and($diagnostics['switch_service_called'])->toBeFalse()
        ->and($diagnostics['activation_flag_enabled'])->toBeTrue()
        ->and($diagnostics['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED)
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits']);

    Bus::assertNothingDispatched();
});

it('does not mutate existing payload status dedupe or ownership fields while recording the empty-scope diagnostic', function (): void {
    Bus::fake();
    [, , , $objective] = phase3zContext('phase-3z-empty-no-mutations');
    $closedLegacy = phase3zOpportunity($objective, ['status' => 'dismissed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $closedLegacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 12,
        'payload' => [
            'title' => 'Existing empty-scope action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);
    $before = [
        'legacy' => $closedLegacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'canonical' => Opportunity::query()->count(),
        'action_runs' => AgenticActionRun::query()->count(),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
    ];

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);

    expect($summary['opportunities'])->toBe(0)
        ->and($switch->calls)->toBe(0)
        ->and($diagnostics['empty_scope_diagnostic_recorded'])->toBeTrue()
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and($closedLegacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits']);

    Bus::assertNothingDispatched();
});

it('does not create actions migrate ownership sync lifecycle mutate payload status dedupe rewrite parents write audit rows or dispatch jobs', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase3zContext('phase-3z-no-mutations');
    phase3zWriteReadyAuditSnapshot($workspace, $objective);
    $legacy = phase3zOpportunity($objective, [
        'type' => AgenticMarketingOpportunityType::Refresh->value,
        'content_id' => null,
        'payload' => [
            'detector' => 'content_decay',
            'signals' => ['lifecycle_stage' => 'refresh_needed'],
        ],
    ]);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 3Z action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', true);
    $switch = phase3zSwitchService(phase3zSwitchDecision(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY));
    app()->instance(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class, $switch);
    $before = [
        'legacy' => $legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'canonical' => Opportunity::query()->count(),
        'action_runs' => AgenticActionRun::query()->count(),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
    ];

    $summary = app(AgenticMarketingActionPlanner::class)->planForObjective($objective);
    $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);

    expect($summary['created'])->toBe(0)
        ->and($summary['skipped'])->toBe(1)
        ->and($diagnostics['consumption_status'])->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED)
        ->and($diagnostics['runtime_behavior_changed'])->toBeFalse()
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits']);

    Bus::assertNothingDispatched();
});
