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
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedTelemetryValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phase4dContext(string $slug = 'phase-4d'): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 4D '.Str::random(6),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 4D Workspace',
        'display_name' => 'Phase 4D Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Phase 4D Site',
        'site_url' => 'https://phase-4d.test',
        'base_url' => 'https://phase-4d.test',
        'allowed_domains' => ['phase-4d.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Phase 4D objective '.Str::random(4),
        'goal' => 'Review operator readiness evidence',
        'locale' => 'en',
        'audience' => 'content teams',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function phase4dOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Phase 4D readiness evidence',
        'type' => AgenticMarketingOpportunityType::ContentNetwork->value,
        'priority_score' => 70,
        'status' => 'open',
        'payload' => [
            'detector' => 'content_network_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'Phase 4D '.Str::lower(Str::random(8)),
            'reasoning' => 'Evidence is operator-facing only.',
            'recommendation' => 'Keep default planner legacy.',
            'signals' => ['topic_keyword' => 'Phase 4D'],
        ],
    ], $overrides));
}

function phase4dTelemetryReport(string $workspace = 'workspace-id', array $objectives = ['objective-id'], array $overrides = []): array
{
    return array_replace_recursive([
        'phase' => '4C',
        'workspace_id' => $workspace,
        'objective_ids' => $objectives,
        'real_scope_detected' => true,
        'objective_records_found' => true,
        'real_scope_status' => [
            'workspace_id' => $workspace,
            'objective_ids' => $objectives,
            'workspace_record_found' => true,
            'objective_records_found' => true,
            'explicit_workspace_objective_scope' => true,
            'wildcard_scope_rejected' => false,
            'global_scope_rejected' => false,
            'percentage_scope_rejected' => true,
            'inferred_scope_rejected' => true,
            'real_scope_detected' => true,
        ],
        'telemetry_complete' => true,
        'telemetry_blocked_reasons' => [],
        'audit_snapshot_present' => true,
        'audit_snapshot_present_status' => 'yes',
        'activation_flag_state' => 'disabled_report_only_non_consuming',
        'activation_flag_consumed_for_switching' => false,
        'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
        'runtime_behavior_changed' => false,
        'phase_3t_through_4b_status_summary' => [
            'phase_3t' => ['status' => 'ready_for_scoped_expansion'],
            'phase_3u' => ['status' => 'eligible'],
            'phase_3v' => ['status' => 'guard_allowed'],
            'phase_3w' => ['status' => 'legacy', 'available' => true],
            'phase_3x' => ['status' => AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY],
            'phase_3y' => ['status' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY, 'audit_snapshot_status' => 'present'],
            'phase_3z' => ['status' => 'switch_ready_consumed', 'available' => true, 'exact_scope_match' => true],
            'phase_4a' => ['status' => 'activation_candidate_report_available'],
            'phase_4b' => ['status' => 'diagnostics_available', 'activation_flag_consumed_for_switching' => false],
        ],
        'phase_4a_activation_design_report' => [
            'phase_3v_guard_decision' => [
                'blocked_reasons' => [],
                'phase_3u_plan' => [
                    'duplicate_risk_confirmation' => ['confirmed' => true],
                    'order_parity_confirmation' => ['confirmed' => true],
                ],
            ],
            'phase_3y_switch_decision' => [
                'blocked_reasons' => [],
                'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
            ],
        ],
    ], $overrides);
}

function phase4dValidation(array $report): AgenticPlannerDefaultSelectionScopedTelemetryValidationService
{
    return new class($report) extends AgenticPlannerDefaultSelectionScopedTelemetryValidationService
    {
        public array $receivedInput = [];

        public function __construct(private readonly array $report) {}

        public function validate(array $input): array
        {
            $this->receivedInput = $input;

            return $this->report;
        }
    };
}

function phase4dEvidenceService(array $report): AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService
{
    return new AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService(phase4dValidation($report));
}

it('returns evidence_blocked when Phase 4C telemetry_complete is false', function (): void {
    $service = phase4dEvidenceService(phase4dTelemetryReport(overrides: [
        'telemetry_complete' => false,
        'telemetry_blocked_reasons' => ['phase_4c_telemetry_incomplete'],
    ]));

    $report = $service->evidence([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ]);

    expect($report['final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED)
        ->and($report['telemetry_complete'])->toBeFalse()
        ->and($report['blocked_reasons'])->toContain('phase_4c_telemetry_incomplete')
        ->and($report['blocked_reasons'])->toContain('phase_4c_telemetry_complete');
});

it('returns evidence_ready only when telemetry is complete and every checklist gate passes', function (): void {
    $report = phase4dEvidenceService(phase4dTelemetryReport())->evidence([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ]);

    expect($report['final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY)
        ->and($report['blocked_reasons'])->toBe([])
        ->and(collect($report['non_activation_checklist'])->every(fn (array $check): bool => (bool) $check['passed']))->toBeTrue()
        ->and(collect($report['rollback_checklist'])->every(fn (array $check): bool => (bool) $check['passed']))->toBeTrue()
        ->and(collect($report['operator_approval_checklist'])->every(fn (array $check): bool => (bool) $check['passed']))->toBeTrue()
        ->and(collect($report['rollback_checklist'])->pluck('id')->all())->toContain(
            'rollback_path_confirmed_legacy_first',
            'rollback_requires_no_migration',
            'rollback_requires_no_historical_rewrite',
            'rollback_requires_no_dedupe_status_mutation',
            'rollback_is_additive_metadata_only',
        )
        ->and(collect($report['operator_approval_checklist'])->pluck('id')->all())->toContain(
            'explicit_workspace_objective_reviewed',
            'phase_4c_telemetry_complete',
            'audit_snapshot_reviewed',
            'metadata_only_ok_reviewed',
            'duplicate_risk_zero',
            'order_parity_confirmed',
            'lifecycle_continuity_blockers_absent',
            'activation_flag_still_non_consuming',
            'rollback_path_confirmed_legacy_first',
            'no_action_ownership_payload_status_dedupe_lifecycle_job_mutation_observed',
        );
});

it('blocks evidence when any operator approval gate is not satisfied', function (): void {
    $report = phase4dEvidenceService(phase4dTelemetryReport())->evidence([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => false,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ]);

    expect($report['final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED)
        ->and($report['blocked_reasons'])->toContain('metadata_only_ok_reviewed');
});

it('composes Phase 4C telemetry validation and preserves its evidence fields', function (): void {
    $phase4c = phase4dValidation(phase4dTelemetryReport(overrides: [
        'activation_flag_state' => 'enabled_report_only_non_consuming',
        'telemetry_complete' => false,
        'telemetry_blocked_reasons' => ['matching_audit_snapshot_present'],
        'audit_snapshot_present' => false,
    ]));
    $service = new AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService($phase4c);

    $report = $service->evidence([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ]);

    expect($phase4c->receivedInput)->toMatchArray([
        'workspace' => 'workspace-id',
        'objectives' => ['objective-id'],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ])
        ->and($report['phase_4c_telemetry_validation_report']['telemetry_blocked_reasons'])->toBe(['matching_audit_snapshot_present'])
        ->and($report['blocked_reasons'])->toContain('matching_audit_snapshot_present')
        ->and(data_get($report, 'phase_3t_through_4c_chain_summary.phase_3t.status'))->toBe('ready_for_scoped_expansion')
        ->and(data_get($report, 'phase_3t_through_4c_chain_summary.phase_4c.status'))->toBe('telemetry_blocked')
        ->and($report['audit_snapshot_status'])->toBe('missing')
        ->and($report['activation_flag_state'])->toBe('enabled_report_only_non_consuming');
});

it('default command exits zero for blocked evidence while ci exits non-zero', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::class,
        phase4dEvidenceService(phase4dTelemetryReport(overrides: [
            'telemetry_complete' => false,
            'telemetry_blocked_reasons' => ['phase_4c_telemetry_incomplete'],
        ]))
    );

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-readiness-evidence', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('final evidence status: evidence_blocked');

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-readiness-evidence', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
        '--ci' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('final evidence status: evidence_blocked');
});

it('ci exits zero for ready evidence', function (): void {
    app()->instance(
        AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::class,
        phase4dEvidenceService(phase4dTelemetryReport())
    );

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-readiness-evidence', [
        '--workspace' => 'workspace-id',
        '--objectives' => 'objective-id',
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
        '--ci' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('final evidence status: evidence_ready')
        ->expectsOutputToContain('phase_3t status: ready_for_scoped_expansion')
        ->expectsOutputToContain('blocked reasons: none')
        ->expectsOutputToContain('non-activation checklist:')
        ->expectsOutputToContain('rollback checklist:')
        ->expectsOutputToContain('operator approval checklist:')
        ->expectsOutputToContain('selected planner remains: legacy')
        ->expectsOutputToContain('runtime_behavior_changed: no');
});

it('does not change planner output or create actions migrate ownership sync lifecycle mutate payload status dedupe write audits dispatch jobs or change routes and approvals', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4dContext('phase-4d-no-mutations');
    $legacy = phase4dOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4D action',
            'planning' => ['planner' => 'legacy-planner'],
            'approval' => ['route' => 'legacy-approval'],
        ],
    ]);

    $planner = app(AgenticMarketingActionPlanner::class);
    $plannerOutputBefore = $planner->previewPlannedActions($legacy);
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
        'routes' => Route::getRoutes()->count(),
    ];

    $report = phase4dEvidenceService(phase4dTelemetryReport((string) $workspace->id, [(string) $objective->id]))->evidence([
        'workspace' => (string) $workspace->id,
        'objectives' => [(string) $objective->id],
        'limit' => 1,
        'ack_metadata_only_review' => true,
        'ack_runtime_switch_contract' => true,
        'require_real_scope' => true,
    ]);

    expect($report['final_evidence_status'])->toBe(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY)
        ->and($report['selected_planner_remains'])->toBe('legacy')
        ->and($report['runtime_behavior_changed'])->toBeFalse()
        ->and($planner->previewPlannedActions($legacy->refresh()))->toBe($plannerOutputBefore)
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticMarketingAction::query()->count())->toBe($before['actions'])
        ->and(AgenticMarketingOpportunity::query()->count())->toBe($before['opportunities'])
        ->and(AgenticMarketingRun::query()->count())->toBe($before['runs'])
        ->and(AgenticMarketingRunItem::query()->count())->toBe($before['run_items'])
        ->and(AgenticActionRun::query()->count())->toBe($before['action_runs'])
        ->and(AgenticMarketingAuditLog::query()->count())->toBe($before['audit_logs'])
        ->and(Opportunity::query()->count())->toBe($before['canonical'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits'])
        ->and(Route::getRoutes()->count())->toBe($before['routes']);

    Bus::assertNothingDispatched();
});

it('command does not write runtime switch audits or mutate planner and action fields', function (): void {
    Bus::fake();
    [, $workspace, , $objective] = phase4dContext('phase-4d-command-no-mutations');
    $legacy = phase4dOpportunity($objective);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $legacy->id,
        'action_type' => AgenticMarketingActionType::CreateArticle->value,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 24,
        'payload' => [
            'title' => 'Existing Phase 4D command action',
            'planning' => ['planner' => 'legacy-planner'],
        ],
    ]);

    $planner = app(AgenticMarketingActionPlanner::class);
    $plannerOutputBefore = $planner->previewPlannedActions($legacy);
    $before = [
        'legacy' => $legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']),
        'action' => $action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']),
        'runtime_switch_audits' => AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count(),
    ];

    app()->instance(
        AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::class,
        phase4dEvidenceService(phase4dTelemetryReport((string) $workspace->id, [(string) $objective->id]))
    );

    $this->artisan('mos:inspect-agentic-planner-default-selection-operator-readiness-evidence', [
        '--workspace' => (string) $workspace->id,
        '--objectives' => (string) $objective->id,
        '--limit' => 1,
        '--ack-metadata-only-review' => true,
        '--ack-runtime-switch-contract' => true,
        '--require-real-scope' => true,
    ])->assertSuccessful();

    expect($planner->previewPlannedActions($legacy->refresh()))->toBe($plannerOutputBefore)
        ->and($legacy->refresh()->only(['status', 'payload', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash']))->toBe($before['legacy'])
        ->and($action->refresh()->only(['opportunity_id', 'status', 'dedupe_hash', 'payload_hash', 'open_dedupe_hash', 'payload']))->toBe($before['action'])
        ->and(AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->count())->toBe($before['runtime_switch_audits']);

    Bus::assertNothingDispatched();
});
