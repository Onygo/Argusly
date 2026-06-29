<?php

use App\Enums\AgenticMarketingActionType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4aContext(string $slug = 'phase-4a'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4A '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4A Workspace',
        'display_name' => 'Phase 4A Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4A Site',
        'site_url' => 'https://phase-4a.test',
        'base_url' => 'https://phase-4a.test',
        'allowed_domains' => ['phase-4a.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4A objective '.Str::random(4),
        'goal' => 'Design guarded activation',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4aOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 4A guarded activation design',
        'type' => 'content_network',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4A '.Str::lower(Str::random(8)),
            'reasoning' => 'Activation design is report-only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4A'],
        ],
    ], $overrides));
}

function phase4aSwitchDecision(Workspace $workspace, AgenticMarketingObjective $objective, array $overrides = []): array
{
    $objectiveId = (string) $objective->id;

    $base = [
        'phase' => '3Y',
        'mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::MODE,
        'workspace_id' => (string) $workspace->id,
        'objective_ids' => [$objectiveId],
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
        'allowed_scope_status' => [
            'workspace_id' => (string) $workspace->id,
            'objective_ids' => [$objectiveId],
            'explicitly_allowed' => true,
        ],
        'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
        'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
        'payload_namespace' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE,
        'payload_version' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION,
        'runtime_switching_implemented' => false,
        'planner_selection_changed' => false,
        'planner_output_changed' => false,
        'phase_3x_contract_report' => [
            'final_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY,
            'phase_3v_guard_decision' => [
                'allowed' => true,
                'phase_3t_status' => 'ready_for_scoped_expansion',
                'phase_3u_eligibility' => 'eligible',
                'blocked_reasons' => [],
                'phase_3t_report' => [
                    'rollout_readiness_status' => 'ready_for_scoped_expansion',
                    'objective_rows' => [[
                        'objective_id' => $objectiveId,
                        'rollout_readiness_status' => 'ready_for_scoped_expansion',
                        'duplicate_open_action_risk_count' => 0,
                        'canonical_legacy_order_exact_match' => true,
                        'phase_3i_continuity_status' => 'no_blockers',
                        'phase_3j_lifecycle_status' => 'no_ambiguity_or_conflict',
                    ]],
                ],
                'phase_3u_plan' => [
                    'rollout_eligibility' => 'eligible',
                    'inspected_objectives' => [$objectiveId],
                    'duplicate_risk_confirmation' => ['confirmed' => true],
                    'order_parity_confirmation' => ['confirmed' => true],
                ],
            ],
            'phase_3w_planner_path_diagnostic_state' => [
                'available' => true,
                'summary' => [
                    'ok' => true,
                    'guard_called' => true,
                    'guard_allowed' => true,
                    'selected_planner_remains' => 'legacy',
                    'blocked_reasons' => [],
                ],
            ],
            'blocked_reasons' => [],
            'runtime_switching_implemented' => false,
            'planner_selection_changed' => false,
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function phase4aSwitch(array $decision): AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
{
    return new class($decision) extends AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $decision) {}

        public function decide(array $input): array
        {
            $this->receivedInput = $input;

            return $this->decision;
        }
    };
}

function phase4aReadyAuditSnapshot(Workspace $workspace, AgenticMarketingObjective $objective): AgenticPlannerDefaultSelectionRuntimeSwitchAudit
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

function phase4aStoreReady3zDiagnostics(Workspace $workspace, AgenticMarketingObjective $objective): void
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
        'requested_scope' => [
            'workspace_id' => (string) $workspace->id,
            'objective_ids' => [(string) $objective->id],
            'site_id' => $objective->client_site_id ? (string) $objective->client_site_id : null,
            'limit' => 1,
        ],
        'consumption_status' => AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED,
    ]);
}

function phase4aReport(Workspace $workspace, AgenticMarketingObjective $objective, array $decisionOverrides = []): array
{
    $decision = phase4aSwitchDecision($workspace, $objective, $decisionOverrides);

    return (new AgenticPlannerDefaultSelectionGuardedActivationDesignService(phase4aSwitch($decision)))->report([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
    ]);
}

it('reports activation_candidate=false when any chain gate fails', function (array $override, string $expectedReason): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-chain-fail');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4aReport($workspace, $objective, $override);

    expect($report['activation_candidate'])->toBe('no')
        ->and($report['blocked_reasons'])->toContain($expectedReason)
        ->and($report['selected_planner_after_phase_4a'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse();
})->with([
    '3T blocked' => [[
        'phase_3t_status' => 'blocked_by_preview',
        'phase_3x_contract_report' => [
            'phase_3v_guard_decision' => [
                'phase_3t_status' => 'blocked_by_preview',
                'phase_3t_report' => ['rollout_readiness_status' => 'blocked_by_preview'],
            ],
        ],
    ], 'phase_3t_ready_for_scoped_expansion'],
    '3U blocked' => [[
        'phase_3u_eligibility' => 'blocked',
        'phase_3x_contract_report' => [
            'phase_3v_guard_decision' => [
                'phase_3u_eligibility' => 'blocked',
                'phase_3u_plan' => ['rollout_eligibility' => 'blocked'],
            ],
        ],
    ], 'phase_3u_eligible'],
    '3V blocked' => [[
        'phase_3v_guard_allowed' => false,
        'phase_3x_contract_report' => [
            'phase_3v_guard_decision' => ['allowed' => false],
        ],
    ], 'phase_3v_guard_allowed'],
    '3W non legacy' => [[
        'phase_3w_selected_planner_remains' => 'canonical',
        'phase_3x_contract_report' => [
            'phase_3w_planner_path_diagnostic_state' => [
                'summary' => ['selected_planner_remains' => 'canonical'],
            ],
        ],
    ], 'phase_3w_selected_planner_remains_legacy'],
    '3X blocked' => [[
        'phase_3x_contract_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED,
        'phase_3x_contract_report' => [
            'final_status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED,
        ],
    ], 'phase_3x_contract_ready'],
    '3Y blocked' => [[
        'switch_decision' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
    ], 'phase_3y_switch_ready'],
]);

it('reports activation_candidate=false when the matching audit snapshot or Phase 3Z consumption gate is missing', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-audit-missing');

    $missingAuditReport = phase4aReport($workspace, $objective);
    phase4aReadyAuditSnapshot($workspace, $objective);
    $missing3zReport = phase4aReport($workspace, $objective);

    expect($missingAuditReport['activation_candidate'])->toBe('no')
        ->and($missingAuditReport['blocked_reasons'])->toContain('matching_phase_3y_audit_snapshot_exists')
        ->and($missing3zReport['activation_candidate'])->toBe('no')
        ->and($missing3zReport['blocked_reasons'])->toContain('phase_3z_switch_ready_consumed');
});

it('requires a ready Phase 3Y audit snapshot with operator acknowledgements before activation candidacy', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-audit-ready-fields');
    phase4aReadyAuditSnapshot($workspace, $objective)->forceFill([
        'operator_acknowledgements' => [
            'metadata_only_review' => true,
            'runtime_switch_contract' => false,
        ],
    ])->save();
    phase4aStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4aReport($workspace, $objective);

    expect($report['activation_candidate'])->toBe('no')
        ->and($report['phase_3y_matching_audit_snapshot_exists'])->toBeFalse()
        ->and($report['blocked_reasons'])->toContain('matching_phase_3y_audit_snapshot_exists')
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe(1);
});

it('requires Phase 3U duplicate risk and order parity confirmations for activation candidacy', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-confirmations');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4aReport($workspace, $objective, [
        'phase_3x_contract_report' => [
            'phase_3v_guard_decision' => [
                'phase_3u_plan' => [
                    'duplicate_risk_confirmation' => ['confirmed' => false],
                    'order_parity_confirmation' => ['confirmed' => false],
                ],
            ],
        ],
    ]);

    expect($report['activation_candidate'])->toBe('no')
        ->and($report['blocked_reasons'])->toContain('duplicate_risk_zero')
        ->and($report['blocked_reasons'])->toContain('order_parity_confirmed')
        ->and($report['selected_planner_after_phase_4a'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse();
});

it('reports activation_candidate=true only when all 3T through 3Z gates pass', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-ready');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', false);

    $report = phase4aReport($workspace, $objective);

    expect($report['activation_candidate'])->toBe('yes')
        ->and($report['activation_candidate_bool'])->toBeTrue()
        ->and($report['blocked_reasons'])->toBe([])
        ->and(collect($report['required_activation_gates'])->every(fn (array $gate): bool => (bool) $gate['passed']))->toBeTrue()
        ->and($report['safe_empty_scope_diagnostic_available'])->toBeTrue()
        ->and($report['activation_flag_defined'])->toBeTrue()
        ->and($report['activation_flag_enabled'])->toBeFalse()
        ->and($report['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and(data_get($report, 'readiness_chain_status.phase_3t.status'))->toBe('ready_for_scoped_expansion')
        ->and(data_get($report, 'readiness_chain_status.phase_3u.status'))->toBe('eligible')
        ->and(data_get($report, 'readiness_chain_status.phase_3z.status'))->toBe(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED)
        ->and(data_get($report, 'readiness_chain_status.phase_4b.safe_empty_scope_diagnostic_available'))->toBeTrue()
        ->and(data_get($report, 'readiness_chain_status.phase_4b.activation_flag_enabled'))->toBeFalse();
});

it('keeps selected planner legacy and runtime behavior unchanged after Phase 4A', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-legacy-output');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4aReport($workspace, $objective);

    expect($report['selected_planner_current'])->toBe('legacy')
        ->and($report['selected_planner_after_phase_4a'])->toBe('legacy')
        ->and($report['selected_planner_after_phase_4b'])->toBe('legacy')
        ->and($report['selected_action_ownership_mode_after_phase_4a'])->toBe('legacy_owned')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
        ->and($report['planner_output_changed'])->toBeFalse()
        ->and($report['canonical_planner_output_selected'])->toBeFalse()
        ->and($report['activation_flag_added'])->toBeTrue()
        ->and($report['activation_flag_defined'])->toBeTrue()
        ->and($report['activation_flag_enabled'])->toBeFalse()
        ->and($report['runtime_activation_implemented'])->toBeFalse();
});

it('includes the safe empty-candidate diagnostic availability, activation flag contract and rollback design', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-design-sections');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);

    $report = phase4aReport($workspace, $objective);

    expect(data_get($report, 'required_empty_candidate_observability_decision.decision'))
        ->toBe(AgenticPlannerDefaultSelectionGuardedActivationDesignService::EMPTY_CANDIDATE_OBSERVABILITY_DECISION)
        ->and(data_get($report, 'required_empty_candidate_observability_decision.safe_empty_scope_diagnostic_available'))->toBeTrue()
        ->and(data_get($report, 'required_empty_candidate_observability_decision.phase_4a_behavior_changed'))->toBeFalse()
        ->and($report['safe_empty_scope_diagnostic_available'])->toBeTrue()
        ->and($report['activation_flag_config_key'])->toBe(AgenticPlannerDefaultSelectionGuardedActivationDesignService::ACTIVATION_FLAG_CONFIG_KEY)
        ->and($report['activation_flag_defined'])->toBeTrue()
        ->and($report['activation_flag_enabled'])->toBeFalse()
        ->and($report['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and(collect($report['required_rollback_gates'])->pluck('id')->all())->toContain(
            'disable_future_activation_flag_returns_to_legacy_selection',
            'no_data_migration_required',
            'no_historical_action_rewrite',
            'no_dedupe_or_status_mutation',
            'canonical_metadata_remains_additive_only',
            'legacy_agentic_marketing_opportunity_ownership_remains_rollback_authority'
        );
});

it('keeps the activation flag disabled by default and has no global percentage or wildcard activation config', function (): void {
    $config = (array) config('mos.agentic_planner.default_selection');

    expect($config)->toHaveKey('scoped_runtime_activation_enabled')
        ->and($config['scoped_runtime_activation_enabled'])->toBeFalse()
        ->and($config)->not->toHaveKey('global_runtime_activation_enabled')
        ->and($config)->not->toHaveKey('global_activation_enabled')
        ->and($config)->not->toHaveKey('activation_percentage')
        ->and($config)->not->toHaveKey('rollout_percentage')
        ->and($config)->not->toHaveKey('percentage_rollout')
        ->and($config)->not->toHaveKey('wildcard_scope')
        ->and($config)->not->toHaveKey('wildcard_scopes')
        ->and($config['allowed_scopes'])->toBe([])
        ->and($config['switch_allowed_scopes'])->toBe([]);
});

it('does not use the activation flag to switch planner output and blocks candidacy when it is enabled', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4b-activation-flag-enabled');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);
    config()->set('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', true);

    $report = phase4aReport($workspace, $objective);

    expect($report['activation_candidate'])->toBe('no')
        ->and($report['blocked_reasons'])->toContain('activation_flag_contract_defined_disabled_and_non_consuming')
        ->and($report['activation_flag_defined'])->toBeTrue()
        ->and($report['activation_flag_enabled'])->toBeTrue()
        ->and($report['activation_flag_consumed_for_switching'])->toBeFalse()
        ->and($report['selected_planner_after_phase_4b'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
        ->and($report['planner_output_changed'])->toBeFalse();
});

it('does not create actions migrate ownership sync lifecycle mutate payload status dedupe dispatch jobs or write audits', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4aContext('phase-4a-no-mutations');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);
    $legacy = phase4aOpportunity($objective, ['status' => 'closed']);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4A action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);
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

    $report = phase4aReport($workspace, $objective);

    expect($report['activation_candidate'])->toBe('yes')
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

it('prints the Phase 4A inspection command report without writing audits', function (): void {
    [, $workspace, , $objective] = phase4aContext('phase-4a-command');
    phase4aReadyAuditSnapshot($workspace, $objective);
    phase4aStoreReady3zDiagnostics($workspace, $objective);
    app()->instance(AgenticPlannerDefaultSelectionGuardedActivationDesignService::class, new AgenticPlannerDefaultSelectionGuardedActivationDesignService(
        phase4aSwitch(phase4aSwitchDecision($workspace, $objective))
    ));
    $beforeAudits = AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count();

    $this->artisan('mos:inspect-agentic-planner-default-selection-guarded-activation-design', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Phase 4A Agentic planner default-selection guarded activation design.')
        ->expectsOutputToContain('activation candidate: yes')
        ->expectsOutputToContain('selected planner after Phase 4A: legacy')
        ->expectsOutputToContain('selected planner after Phase 4B: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no')
        ->expectsOutputToContain('safe_empty_scope_diagnostic_available: yes')
        ->expectsOutputToContain('activation_flag_defined: yes')
        ->expectsOutputToContain('activation_flag_enabled: no')
        ->expectsOutputToContain('empty-candidate observability decision: '.AgenticPlannerDefaultSelectionGuardedActivationDesignService::EMPTY_CANDIDATE_OBSERVABILITY_DECISION);

    expect(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($beforeAudits);
});
