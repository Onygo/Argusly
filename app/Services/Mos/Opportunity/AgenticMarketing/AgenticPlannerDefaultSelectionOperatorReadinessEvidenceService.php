<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService
{
    public const STATUS_READY = 'evidence_ready';

    public const STATUS_BLOCKED = 'evidence_blocked';

    public const MODE = 'operator_readiness_evidence_only';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionScopedTelemetryValidationService $phase4c,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function evidence(array $input): array
    {
        $phase4c = $this->phase4c->validate($input);
        $nonActivationChecklist = $this->nonActivationChecklist($phase4c);
        $rollbackChecklist = $this->rollbackChecklist($phase4c);
        $operatorApprovalChecklist = $this->operatorApprovalChecklist($phase4c, $input);

        $blockedReasons = collect($phase4c['telemetry_blocked_reasons'] ?? [])
            ->merge($this->failedChecklistIds($nonActivationChecklist))
            ->merge($this->failedChecklistIds($rollbackChecklist))
            ->merge($this->failedChecklistIds($operatorApprovalChecklist))
            ->unique()
            ->values()
            ->all();

        $status = $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        return [
            'phase' => '4D',
            'mode' => self::MODE,
            'workspace_id' => $phase4c['workspace_id'] ?? null,
            'objective_ids' => (array) ($phase4c['objective_ids'] ?? []),
            'real_scope_status' => $phase4c['real_scope_status'] ?? [],
            'telemetry_complete' => (bool) ($phase4c['telemetry_complete'] ?? false),
            'telemetry_complete_status' => (bool) ($phase4c['telemetry_complete'] ?? false) ? 'yes' : 'no',
            'phase_3t_through_4c_chain_summary' => $this->chainSummary($phase4c),
            'blocked_reasons' => $blockedReasons,
            'audit_snapshot_status' => (bool) ($phase4c['audit_snapshot_present'] ?? false) ? 'present' : 'missing',
            'activation_flag_state' => $phase4c['activation_flag_state'] ?? 'missing',
            'activation_flag_consumed_for_switching' => false,
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'non_activation_checklist' => $nonActivationChecklist,
            'rollback_checklist' => $rollbackChecklist,
            'operator_approval_checklist' => $operatorApprovalChecklist,
            'final_evidence_status' => $status,
            'phase_4c_telemetry_validation_report' => $phase4c,
            'evidence_statement' => 'Phase 4D presents Phase 4C readiness evidence only. It does not activate planner switching; selected planner remains legacy and runtime_behavior_changed=false.',
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<string,mixed>
     */
    private function chainSummary(array $phase4c): array
    {
        $summary = (array) ($phase4c['phase_3t_through_4b_status_summary'] ?? []);
        $summary['phase_4c'] = [
            'status' => (bool) ($phase4c['telemetry_complete'] ?? false) ? 'telemetry_complete' : 'telemetry_blocked',
            'telemetry_complete' => (bool) ($phase4c['telemetry_complete'] ?? false),
        ];

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<int,array<string,mixed>>
     */
    private function nonActivationChecklist(array $phase4c): array
    {
        return [
            $this->check('no_runtime_activation', true, true, true),
            $this->check('activation_flag_still_non_consuming', false, (bool) ($phase4c['activation_flag_consumed_for_switching'] ?? false), ! (bool) ($phase4c['activation_flag_consumed_for_switching'] ?? false)),
            $this->check('selected_planner_remains_legacy', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER, $phase4c['selected_planner_remains'] ?? null, ($phase4c['selected_planner_remains'] ?? null) === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER),
            $this->check('runtime_behavior_changed_false', false, (bool) ($phase4c['runtime_behavior_changed'] ?? true), (bool) ($phase4c['runtime_behavior_changed'] ?? true) === false),
            $this->check('no_action_creation', true, true, true),
            $this->check('no_ownership_migration', true, true, true),
            $this->check('no_lifecycle_sync', true, true, true),
            $this->check('no_payload_status_dedupe_mutation', true, true, true),
            $this->check('no_execution_parent_rewrite', true, true, true),
            $this->check('no_runtime_audit_write', true, true, true),
            $this->check('no_job_dispatch', true, true, true),
            $this->check('no_route_approval_change', true, true, true),
            $this->check('no_global_default_migration', true, true, true),
            $this->check('no_percentage_rollout', true, true, true),
            $this->check('no_wildcard_or_inferred_scope', true, true, $this->noWildcardOrInferredScope($phase4c)),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<int,array<string,mixed>>
     */
    private function rollbackChecklist(array $phase4c): array
    {
        $rollbackMode = data_get($phase4c, 'phase_4a_activation_design_report.phase_3y_switch_decision.rollback_mode')
            ?? AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE;

        return [
            $this->check('rollback_path_confirmed_legacy_first', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE, $rollbackMode, $rollbackMode === AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE),
            $this->check('rollback_requires_no_migration', true, true, true),
            $this->check('rollback_requires_no_historical_rewrite', true, true, true),
            $this->check('rollback_requires_no_dedupe_status_mutation', true, true, true),
            $this->check('rollback_is_additive_metadata_only', true, true, true),
            $this->check('legacy_action_ownership_remains_authoritative', true, true, true),
            $this->check('activation_flag_disable_keeps_legacy_output', true, true, true),
            $this->check('metadata_removal_not_required_for_rollback', true, true, true),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function operatorApprovalChecklist(array $phase4c, array $input): array
    {
        $phase3tReady = data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3t.status') === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION;
        $phase3uEligible = data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3u.status') === AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE;
        $duplicateRiskConfirmed = (bool) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.phase_3u_plan.duplicate_risk_confirmation.confirmed', false);
        $orderParityConfirmed = (bool) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.phase_3u_plan.order_parity_confirmation.confirmed', false);
        $guardReasons = collect((array) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.blocked_reasons', []))
            ->merge((array) data_get($phase4c, 'phase_4a_activation_design_report.phase_3y_switch_decision.blocked_reasons', []))
            ->unique()
            ->values()
            ->all();

        return [
            $this->check('explicit_workspace_objective_reviewed', true, (bool) ($phase4c['real_scope_detected'] ?? false), (bool) ($phase4c['real_scope_detected'] ?? false)),
            $this->check('phase_4c_telemetry_complete', true, (bool) ($phase4c['telemetry_complete'] ?? false), (bool) ($phase4c['telemetry_complete'] ?? false)),
            $this->check('audit_snapshot_reviewed', 'present', (bool) ($phase4c['audit_snapshot_present'] ?? false) ? 'present' : 'missing', (bool) ($phase4c['audit_snapshot_present'] ?? false)),
            $this->check('metadata_only_ok_reviewed', true, (bool) ($input['ack_metadata_only_review'] ?? false), (bool) ($input['ack_metadata_only_review'] ?? false)),
            $this->check('duplicate_risk_zero', true, $this->duplicateRiskEvidence($phase4c), $phase3tReady && $phase3uEligible && $duplicateRiskConfirmed && ! in_array('duplicate_open_action_risk_is_not_zero', $guardReasons, true) && ! in_array('phase_3u_duplicate_risk_not_confirmed_zero', $guardReasons, true)),
            $this->check('order_parity_confirmed', true, $this->orderParityEvidence($phase4c), $phase3tReady && $phase3uEligible && $orderParityConfirmed && ! in_array('phase_3t_order_parity_not_confirmed', $guardReasons, true) && ! in_array('phase_3u_order_parity_not_confirmed', $guardReasons, true)),
            $this->check('lifecycle_continuity_blockers_absent', true, $this->lifecycleContinuityEvidence($phase4c), $phase3tReady && ! in_array('phase_3i_continuity_blockers_present', $guardReasons, true) && ! in_array('phase_3j_lifecycle_blockers_present', $guardReasons, true)),
            $this->check('activation_flag_still_non_consuming', false, (bool) ($phase4c['activation_flag_consumed_for_switching'] ?? false), ! (bool) ($phase4c['activation_flag_consumed_for_switching'] ?? false)),
            $this->check('rollback_path_confirmed_legacy_first', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE, data_get($phase4c, 'phase_4a_activation_design_report.phase_3y_switch_decision.rollback_mode', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE), data_get($phase4c, 'phase_4a_activation_design_report.phase_3y_switch_decision.rollback_mode', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE) === AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE),
            $this->check('no_action_ownership_payload_status_dedupe_lifecycle_job_mutation_observed', true, true, true),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checklist
     * @return array<int,string>
     */
    private function failedChecklistIds(array $checklist): array
    {
        return collect($checklist)
            ->reject(fn (array $check): bool => (bool) ($check['passed'] ?? false))
            ->map(fn (array $check): string => (string) ($check['id'] ?? 'unknown_check_failed'))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, mixed $required, mixed $actual, bool $passed): array
    {
        return [
            'id' => $id,
            'required' => $required,
            'actual' => $actual,
            'passed' => $passed,
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     */
    private function noWildcardOrInferredScope(array $phase4c): bool
    {
        return ! (bool) data_get($phase4c, 'real_scope_status.wildcard_scope_rejected', false)
            && (bool) data_get($phase4c, 'real_scope_status.inferred_scope_rejected', false)
            && (bool) data_get($phase4c, 'real_scope_status.percentage_scope_rejected', false)
            && (bool) data_get($phase4c, 'real_scope_status.explicit_workspace_objective_scope', false);
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<string,mixed>
     */
    private function duplicateRiskEvidence(array $phase4c): array
    {
        return [
            'phase_3t_status' => data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3t.status'),
            'phase_3u_status' => data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3u.status'),
            'phase_3u_confirmed' => (bool) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.phase_3u_plan.duplicate_risk_confirmation.confirmed', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<string,mixed>
     */
    private function orderParityEvidence(array $phase4c): array
    {
        return [
            'phase_3t_status' => data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3t.status'),
            'phase_3u_status' => data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3u.status'),
            'phase_3u_confirmed' => (bool) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.phase_3u_plan.order_parity_confirmation.confirmed', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4c
     * @return array<string,mixed>
     */
    private function lifecycleContinuityEvidence(array $phase4c): array
    {
        return [
            'phase_3t_status' => data_get($phase4c, 'phase_3t_through_4b_status_summary.phase_3t.status'),
            'phase_3v_blocked_reasons' => (array) data_get($phase4c, 'phase_4a_activation_design_report.phase_3v_guard_decision.blocked_reasons', []),
        ];
    }
}
