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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionGuardedActivationDesignService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedTelemetryValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4cContext(string $slug = 'phase-4c'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4C '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4C Workspace',
        'display_name' => 'Phase 4C Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4C Site',
        'site_url' => 'https://phase-4c.test',
        'base_url' => 'https://phase-4c.test',
        'allowed_domains' => ['phase-4c.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4C objective '.Str::random(4),
        'goal' => 'Validate scoped telemetry',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4cOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 4C telemetry validation',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4C '.Str::lower(Str::random(8)),
            'reasoning' => 'Telemetry validation is report-only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4C'],
        ],
    ], $overrides));
}

function phase4cDesignReport(Workspace|string $workspace, AgenticMarketingObjective|string $objective, array $overrides = []): array
{
    $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
    $objectiveId = $objective instanceof AgenticMarketingObjective ? (string) $objective->id : (string) $objective;
    $activationFlagEnabled = (bool) config('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', false);

    return array_replace_recursive([
        'phase' => '4A',
        'workspace_id' => $workspaceId,
        'objective_ids' => [$objectiveId],
        'activation_candidate' => 'yes',
        'activation_candidate_bool' => true,
        'blocked_reasons' => [],
        'safe_empty_scope_diagnostic_available' => true,
        'activation_flag_defined' => true,
        'activation_flag_enabled' => $activationFlagEnabled,
        'activation_flag_consumed_for_switching' => false,
        'selected_planner_current' => 'legacy',
        'selected_planner_after_phase_4a' => 'legacy',
        'selected_planner_after_phase_4b' => 'legacy',
        'selected_action_ownership_mode_after_phase_4a' => 'legacy_owned',
        'runtime_behavior_changed' => false,
        'planner_output_changed' => false,
        'canonical_planner_output_selected' => false,
        'runtime_activation_implemented' => false,
        'readiness_chain_status' => [
            'phase_3t' => ['status' => 'ready_for_scoped_expansion'],
            'phase_3u' => ['status' => 'eligible'],
            'phase_3v' => ['status' => 'guard_allowed'],
            'phase_3w' => ['status' => 'legacy', 'available' => true],
            'phase_3x' => ['status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY],
            'phase_3y' => ['status' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY],
            'phase_3z' => [
                'status' => AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED,
                'available' => true,
                'exact_scope_match' => true,
            ],
            'phase_4b' => [
                'safe_empty_scope_diagnostic_available' => true,
                'activation_flag_defined' => true,
                'activation_flag_enabled' => $activationFlagEnabled,
                'activation_flag_consumed_for_switching' => false,
            ],
        ],
        'phase_3y_switch_decision' => [
            'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        ],
    ], $overrides);
}

function phase4cDesign(array $report): AgenticPlannerDefaultSelectionGuardedActivationDesignService
{
    return new class($report) extends AgenticPlannerDefaultSelectionGuardedActivationDesignService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function report(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase4cValidation(array $report): AgenticPlannerDefaultSelectionScopedTelemetryValidationService
{
    return new AgenticPlannerDefaultSelectionScopedTelemetryValidationService(phase4cDesign($report));
}

function phase4cReadyAuditSnapshot(Workspace $workspace, AgenticMarketingObjective $objective): AgenticPlannerDefaultSelectionRuntimeSwitchAudit
{
    return AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->create([
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
        'operator_acknowledgements' => [
            'metadata_only_review' => true,
            'runtime_switch_contract' => true,
        ],
        'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
        'payload_namespace' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE,
        'payload_version' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION,
        'payload' => [],
        'created_at' => now(),
    ]);
}

function phase4cStoreReady3zDiagnostics(Workspace $workspace, AgenticMarketingObjective $objective): void
{
    app()->instance(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY, [
        'phase' => '3Z',
        'ok' => true,
        'consumption_hook_called' => true,
        'switch_service_called' => true,
        'switch_decision' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY,
        'pre_switch_audit_snapshot_present' => true,
        'blocked_reasons' => [],
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
        'runtime_behavior_changed' => false,
        'planner_output_changed' => false,
        'canonical_planner_output_selected' => false,
        'activation_flag_consumed_for_switching' => false,
        'requested_scope' => [
            'workspace_id' => (string) $workspace->id,
            'objective_ids' => [(string) $objective->id],
            'site_id' => $objective->client_site_id ? (string) $objective->client_site_id : null,
            'limit' => 1,
        ],
        'consumption_status' => AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED,
    ]);
}

function phase4cValidate(Workspace $workspace, AgenticMarketingObjective $objective, array $reportOverrides = [], array $inputOverrides = []): array
{
    return phase4cValidation(phase4cDesignReport($workspace, $objective, $reportOverrides))->validate(array_replace([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ], $inputOverrides));
}

it('blocks when require real scope is passed and objective records are missing', function (): void {
    [$organization, $workspace] = phase4cContext('phase-4c-missing-objective');
    $missingObjectiveId = (string) Str::uuid();
    $report = phase4cValidation(phase4cDesignReport($workspace, $missingObjectiveId))->validate([
        'workspace' => (string) $workspace->id,
        'objectives' => [$missingObjectiveId],
        'limit' => 1,
        'require_real_scope' => true,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
    ]);

    expect($organization)->not->toBeNull()
        ->and($report['real_scope_detected'])->toBeFalse()
        ->and($report['objective_records_found'])->toBeFalse()
        ->and($report['telemetry_complete'])->toBeFalse()
        ->and($report['telemetry_blocked_reasons'])->toContain('real_scope_required_but_missing');
});

it('blocks real scope when the objective exists but belongs to a different workspace', function (): void {
    [, $workspace] = phase4cContext('phase-4c-cross-workspace-requested');
    [, $otherWorkspace, , $otherObjective] = phase4cContext('phase-4c-cross-workspace-objective');
    phase4cReadyAuditSnapshot($otherWorkspace, $otherObjective);
    phase4cStoreReady3zDiagnostics($workspace, $otherObjective);

    $report = phase4cValidation(phase4cDesignReport($workspace, $otherObjective))->validate([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $otherObjective->id],
        'limit' => 1,
        'require_real_scope' => true,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
    ]);

    expect($report['real_scope_detected'])->toBeFalse()
        ->and($report['objective_records_found'])->toBeFalse()
        ->and(data_get($report, 'real_scope_status.workspace_record_found'))->toBeTrue()
        ->and(data_get($report, 'real_scope_status.found_objective_ids'))->toBe([])
        ->and(data_get($report, 'real_scope_status.objective_ids'))->toBe([(string) $otherObjective->id])
        ->and($report['telemetry_complete'])->toBeFalse()
        ->and($report['telemetry_blocked_reasons'])->toContain('real_scope_required_but_missing');
});

it('passes real_scope_detected when workspace and objective records exist', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-real-scope');
    phase4cReadyAuditSnapshot($workspace, $objective);
    phase4cStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4cValidate($workspace, $objective);

    expect($report['real_scope_detected'])->toBeTrue()
        ->and($report['objective_records_found'])->toBeTrue()
        ->and(data_get($report, 'real_scope_status.workspace_record_found'))->toBeTrue();
});

it('sets telemetry_complete false when the audit snapshot is missing', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-audit-missing');
    phase4cStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4cValidate($workspace, $objective);

    expect($report['audit_snapshot_present'])->toBeFalse()
        ->and($report['telemetry_complete'])->toBeFalse()
        ->and($report['telemetry_blocked_reasons'])->toContain('matching_audit_snapshot_present');
});

it('sets telemetry_complete false when Phase 3Z diagnostics are missing and no empty-scope diagnostic exists', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-3z-missing');
    phase4cReadyAuditSnapshot($workspace, $objective);
    app()->forgetInstance(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);

    $report = phase4cValidate($workspace, $objective, [
        'readiness_chain_status' => [
            'phase_3z' => ['status' => 'missing', 'available' => false, 'exact_scope_match' => false],
        ],
    ]);

    expect($report['telemetry_complete'])->toBeFalse()
        ->and(data_get($report, 'empty_scope_diagnostic_status.status'))->toBe('missing')
        ->and($report['telemetry_blocked_reasons'])->toContain('phase_3z_consumption_ready_or_safe_empty_scope_diagnostic_present');
});

it('sets telemetry_complete true only when all telemetry gates are satisfied', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-complete');
    phase4cOpportunity($objective);
    phase4cReadyAuditSnapshot($workspace, $objective);
    phase4cStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4cValidate($workspace, $objective);

    expect($report['telemetry_complete'])->toBeTrue()
        ->and($report['telemetry_blocked_reasons'])->toBe([])
        ->and($report['legacy_candidate_count'])->toBe(1)
        ->and(data_get($report, 'phase_3t_through_4b_status_summary.phase_3y.status'))->toBe(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
        ->and(data_get($report, 'phase_3t_through_4b_status_summary.phase_3y.matching_audit_snapshot_exists'))->toBeTrue()
        ->and(data_get($report, 'phase_3t_through_4b_status_summary.phase_3y.audit_snapshot_status'))->toBe('present')
        ->and(collect($report['pre_activation_acceptance_checklist'])->every(fn (array $check): bool => (bool) $check['passed']))->toBeTrue();
});

it('allows safe empty-scope diagnostics to satisfy the Phase 3Z telemetry checklist item', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-empty-scope');
    phase4cReadyAuditSnapshot($workspace, $objective);
    app()->instance(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY, [
        'phase' => '4B',
        'empty_scope_diagnostic_recorded' => true,
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => [(string) $objective->id],
        'legacy_candidate_count' => 0,
        'selected_planner_remains' => 'legacy',
        'runtime_behavior_changed' => false,
        'activation_flag_consumed_for_switching' => false,
        'consumption_status' => AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED,
    ]);

    $report = phase4cValidate($workspace, $objective, [
        'readiness_chain_status' => [
            'phase_3z' => [
                'status' => AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED,
                'available' => true,
                'exact_scope_match' => true,
            ],
        ],
    ]);

    expect(data_get($report, 'empty_scope_diagnostic_status.recorded'))->toBeTrue()
        ->and($report['telemetry_complete'])->toBeTrue();
});

it('keeps selected planner legacy and runtime_behavior_changed false when the activation flag is true', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-activation-enabled');
    phase4cReadyAuditSnapshot($workspace, $objective);
    phase4cStoreReady3zDiagnostics($workspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', true);

    $report = phase4cValidate($workspace, $objective);

    expect($report['activation_flag_enabled'])->toBeTrue()
        ->and($report['activation_flag_state'])->toBe('enabled_report_only_non_consuming')
        ->and($report['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and($report['telemetry_complete'])->toBeTrue()
        ->and($report['selected_planner'])->toBe('legacy')
        ->and($report['selected_planner_remains'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse();
});

it('does not create actions migrate ownership sync lifecycle mutate payload status dedupe dispatch jobs write audits or change routes and approvals', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4cContext('phase-4c-no-mutations');
    $legacy = phase4cOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4C action',
            'planning' => ['planner' => 'legacy-planner'],
            'approval' => ['route' => 'legacy-approval'],
        ],
    ]);
    phase4cReadyAuditSnapshot($workspace, $objective);
    phase4cStoreReady3zDiagnostics($workspace, $objective);

    $before = [
        'legacy' => $legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'actions' => AgenticMarketingAction::query()->count(),
        'opportunities' => AgenticMarketingOpportunity::query()->count(),
        'runs' => AgenticMarketingRun::query()->count(),
        'run_items' => AgenticMarketingRunItem::query()->count(),
        'action_runs' => AgenticActionRun::query()->count(),
        'audit_logs' => AgenticMarketingAuditLog::query()->count(),
        'canonical' => Opportunity::query()->count(),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
    ];

    $report = phase4cValidate($workspace, $objective);
    $checklistIds = collect($report['pre_activation_acceptance_checklist'])
        ->pluck('id')
        ->all();

    expect($report['telemetry_complete'])->toBeTrue()
        ->and($checklistIds)->toContain('no_runtime_activation')
        ->and($checklistIds)->toContain('planner_output_remains_legacy')
        ->and($checklistIds)->toContain('no_canonical_planner_output_replacing_legacy_output')
        ->and($checklistIds)->toContain('activation_flag_report_only_and_non_consuming')
        ->and($checklistIds)->toContain('no_action_creation')
        ->and($checklistIds)->toContain('no_execution_parent_rewrite')
        ->and($checklistIds)->toContain('no_runtime_audit_write')
        ->and($checklistIds)->toContain('no_route_approval_change')
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits']);

    Bus::assertNothingDispatched();
});

it('prints the Phase 4C inspection command report without writing audits', function (): void {
    [, $workspace, , $objective] = phase4cContext('phase-4c-command');
    phase4cReadyAuditSnapshot($workspace, $objective);
    phase4cStoreReady3zDiagnostics($workspace, $objective);
    app()->instance(
        AgenticPlannerDefaultSelectionScopedTelemetryValidationService::class,
        phase4cValidation(phase4cDesignReport($workspace, $objective))
    );
    $beforeAudits = AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count();

    $this->artisan('mos:inspect-agentic-planner-default-selection-scoped-telemetry-validation', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Phase 4C Agentic planner default-selection scoped telemetry validation.')
        ->expectsOutputToContain('objective records found: yes')
        ->expectsOutputToContain('activation flag state: disabled_report_only_non_consuming')
        ->expectsOutputToContain('activation_flag_consumed_for_switching: no')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no')
        ->expectsOutputToContain('telemetry_complete: yes');

    expect(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($beforeAudits);
});
