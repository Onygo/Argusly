<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionOperatorSignOffRunbookService
{
    public const STATUS_READY = 'signoff_ready';

    public const STATUS_BLOCKED = 'signoff_blocked';

    public const REVIEW_ACKNOWLEDGED = 'operator_signoff_acknowledged';

    public const REVIEW_MISSING = 'operator_signoff_missing';

    public const MODE = 'operator_signoff_runbook_review_only';

    private const REMEDIATION_GUIDANCE = [
        'phase_3t_ready' => 'Re-run Phase 3T readiness for the explicit workspace/objective scope and resolve preview, duplicate, lifecycle, continuity, signature, and order-parity blockers until it reports ready_for_scoped_expansion.',
        'phase_3t_ready_for_scoped_expansion' => 'Re-run Phase 3T readiness for the explicit workspace/objective scope and resolve preview, duplicate, lifecycle, continuity, signature, and order-parity blockers until it reports ready_for_scoped_expansion.',
        'phase_3u_eligible' => 'Re-run the Phase 3U scoped rollout plan with explicit workspace/objective ids and resolve any eligibility, duplicate-risk, order-parity, or scope blockers until it reports eligible.',
        'phase_3v_guard_allowed' => 'Re-run the Phase 3V scoped runtime guard and resolve blocked guard reasons before requesting sign-off.',
        'phase_3w_diagnostics_present_and_legacy' => 'Capture Phase 3W planner-path diagnostics for the same scope and confirm selected_planner_remains=legacy.',
        'phase_3x_contract_ready' => 'Re-run Phase 3X runtime switch contract inspection and satisfy the non-activation, legacy-output, rollback, and acknowledgement contract gates.',
        'phase_3y_switch_ready' => 'Re-run Phase 3Y scoped runtime switch skeleton inspection for the same scope and resolve switch_blocked reasons without activating runtime switching.',
        'matching_audit_snapshot_present' => 'Create or refresh the required pre-switch evidence snapshot through the existing Phase 3Y/4C evidence path, then re-run Phase 4D. Do not write runtime audit rows from Phase 4E.',
        'phase_3z_consumption_ready_or_safe_empty_scope_diagnostic_present' => 'Run the Phase 3Z consumption hook diagnostics for the exact scope or capture the safe empty-scope diagnostic before repeating Phase 4C/4D evidence.',
        'phase_4a_activation_candidate_report_available' => 'Re-run Phase 4A guarded activation design for the explicit scope and resolve design-only blockers. Do not enable activation as remediation.',
        'phase_4b_empty_scope_diagnostic_available' => 'Re-run Phase 4B safe empty-scope diagnostics and confirm the activation flag remains report-only and non-consuming.',
        'activation_flag_disabled_or_non_consuming' => 'Restore the activation flag to disabled or report-only non-consuming behavior. Do not consume it for planner switching.',
        'no_runtime_activation' => 'Remove any runtime activation behavior from the evidence path and confirm planner output remains legacy.',
        'planner_output_remains_legacy' => 'Restore legacy planner output as authoritative and re-run Phase 4D.',
        'no_canonical_planner_output_replacing_legacy_output' => 'Remove canonical-output replacement from the inspected path and keep canonical output as evidence only.',
        'activation_flag_report_only_and_non_consuming' => 'Keep activation flag checks as report-only evidence and remove any switch consumption.',
        'no_action_creation' => 'Remove action creation from this phase and verify AgenticMarketingAction counts remain unchanged.',
        'no_ownership_migration' => 'Remove ownership migration from this phase and keep legacy Agentic opportunity ownership authoritative.',
        'no_lifecycle_sync' => 'Remove lifecycle synchronization from this phase and keep lifecycle state unchanged.',
        'no_payload_status_dedupe_mutation' => 'Remove payload, status, and dedupe writes from this phase, then re-run non-mutation verification.',
        'no_execution_parent_rewrite' => 'Remove execution parent rewrites and keep existing parent references unchanged.',
        'no_runtime_audit_write' => 'Remove runtime audit writes from this phase. Phase 4E may only report existing evidence.',
        'no_job_dispatch' => 'Remove queue dispatches from this phase and verify no jobs are dispatched during inspection.',
        'no_route_approval_change' => 'Remove route or approval changes from this phase and re-run route/approval non-mutation checks.',
        'rollback_remains_legacy_first' => 'Restore rollback_mode=legacy_first and confirm disabling any future activation would leave legacy output authoritative.',
        'real_scope_required_but_missing' => 'Provide existing workspace and objective ids for the same organization/workspace scope, or create the missing evidence in earlier MOS phases before re-running Phase 4D.',
        'real_scope_not_detected' => 'Use explicit, real workspace/objective ids. Do not use wildcard, global, inferred, or percentage scope.',
        'phase_4c_telemetry_incomplete' => 'Resolve Phase 4C telemetry blockers and re-run Phase 4D until final_evidence_status=evidence_ready.',
        'phase_4c_telemetry_complete' => 'Re-run Phase 4C/4D after all scoped telemetry gates pass.',
        'activation_flag_still_non_consuming' => 'Restore activation flag behavior to non-consuming report evidence before sign-off.',
        'selected_planner_remains_legacy' => 'Restore selected_planner_remains=legacy and confirm planner output remains unchanged.',
        'runtime_behavior_changed_false' => 'Remove runtime behavior changes from the inspected path and re-run evidence collection.',
        'no_action_ownership_payload_status_dedupe_lifecycle_job_mutation_observed' => 'Re-run non-mutation checks and remove any action, ownership, payload, status, dedupe, lifecycle, or job side effect.',
        'no_global_default_migration' => 'Remove global/default migration behavior and keep the scope explicit.',
        'no_percentage_rollout' => 'Remove percentage rollout behavior and keep the scope exact workspace/objective only.',
        'no_wildcard_or_inferred_scope' => 'Replace wildcard or inferred scope with explicit workspace and objective ids.',
        'rollback_path_confirmed_legacy_first' => 'Confirm rollback_mode=legacy_first in the Phase 4D rollback checklist before sign-off.',
        'rollback_requires_no_migration' => 'Remove migration requirements from rollback and keep legacy ownership authoritative.',
        'rollback_requires_no_historical_rewrite' => 'Remove historical rewrite requirements from rollback.',
        'rollback_requires_no_dedupe_status_mutation' => 'Remove dedupe/status mutation requirements from rollback.',
        'rollback_is_additive_metadata_only' => 'Keep additive metadata as review evidence only and do not require metadata removal for rollback.',
        'legacy_action_ownership_remains_authoritative' => 'Keep legacy AgenticMarketingAction ownership authoritative.',
        'activation_flag_disable_keeps_legacy_output' => 'Confirm disabling any future flag leaves legacy planner output selected.',
        'metadata_removal_not_required_for_rollback' => 'Confirm rollback does not require removing additive review metadata.',
        'explicit_workspace_objective_reviewed' => 'Review the exact workspace and objective ids and re-run with --require-real-scope.',
        'audit_snapshot_reviewed' => 'Review the matching audit snapshot evidence from Phase 4D before sign-off.',
        'metadata_only_ok_reviewed' => 'Review metadata_only_ok rows as evidence only, then re-run with --ack-metadata-only-review.',
        'duplicate_risk_zero' => 'Resolve duplicate open action risk in Phase 3T/3U evidence before sign-off.',
        'order_parity_confirmed' => 'Resolve order mismatch evidence and confirm canonical proposed order matches legacy default order.',
        'lifecycle_continuity_blockers_absent' => 'Resolve Phase 3I continuity and Phase 3J lifecycle blockers before sign-off.',
        'operator_signoff_acknowledgement_missing' => 'Have the operator explicitly acknowledge review/sign-off intent and re-run with --ack-operator-signoff.',
        'phase_4d_evidence_ready_required' => 'Re-run Phase 4D remediation until final_evidence_status=evidence_ready before operator sign-off can pass.',
    ];

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService $phase4d,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,ack_operator_signoff?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function inspect(array $input): array
    {
        $phase4d = $this->phase4d->evidence($input);
        $phase4dReady = ($phase4d['final_evidence_status'] ?? null) === AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY;
        $operatorAcknowledged = (bool) ($input['ack_operator_signoff'] ?? false);

        $blockedReasons = collect((array) ($phase4d['blocked_reasons'] ?? []));

        if (! $phase4dReady) {
            $blockedReasons->push('phase_4d_evidence_ready_required');
        }

        if (! $operatorAcknowledged) {
            $blockedReasons->push('operator_signoff_acknowledgement_missing');
        }

        $blockedReasons = $blockedReasons
            ->unique()
            ->values()
            ->all();

        $status = $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        return [
            'phase' => '4E',
            'mode' => self::MODE,
            'workspace_id' => $phase4d['workspace_id'] ?? null,
            'objective_ids' => (array) ($phase4d['objective_ids'] ?? []),
            'evidence_status' => [
                'phase_4d_final_evidence_status' => $phase4d['final_evidence_status'] ?? AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED,
                'required_phase_4d_status' => AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY,
                'phase_4d_evidence_ready' => $phase4dReady,
            ],
            'operator_review_status' => [
                'status' => $operatorAcknowledged ? self::REVIEW_ACKNOWLEDGED : self::REVIEW_MISSING,
                'operator_signoff_acknowledged' => $operatorAcknowledged,
                'review_evidence_only' => true,
            ],
            'signoff_readiness' => [
                'status' => $status,
                'ready' => $status === self::STATUS_READY,
                'blocked_reasons' => $blockedReasons,
            ],
            'blocked_remediation_guidance' => $this->blockedRemediationGuidance($blockedReasons),
            'rollback_confirmation' => [
                'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
                'legacy_first' => true,
                'legacy_output_remains_authoritative' => true,
                'additive_metadata_review_evidence_only' => true,
                'additive_metadata_required_for_rollback' => false,
                'metadata_removal_required_for_rollback' => false,
            ],
            'non_activation_confirmation' => [
                'activation_flag_consumed_for_switching' => false,
                'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
                'runtime_behavior_changed' => false,
                'planner_switching_activated' => false,
                'percentage_rollout_added' => false,
                'global_default_migration_performed' => false,
                'wildcard_scope_inferred' => false,
                'legacy_planner_output_replaced' => false,
                'agentic_marketing_action_created_or_mutated' => false,
                'ownership_migrated' => false,
                'lifecycle_synced' => false,
                'payload_status_dedupe_parent_approval_execution_mutated' => false,
                'runtime_audit_written' => false,
                'job_dispatched' => false,
                'route_or_approval_changed' => false,
                'historical_records_rewritten' => false,
            ],
            'phase_4d_evidence_report' => $phase4d,
            'runbook_statement' => 'Phase 4E is operator sign-off and runbook review evidence only. It requires Phase 4D evidence_ready and explicit operator acknowledgement, but it does not activate planner switching or mutate runtime state.',
        ];
    }

    /**
     * @param  array<int,string>  $blockedReasons
     * @return array<int,array<string,string>>
     */
    private function blockedRemediationGuidance(array $blockedReasons): array
    {
        return collect($blockedReasons)
            ->map(fn (string $reason): array => [
                'reason' => $reason,
                'guidance' => self::REMEDIATION_GUIDANCE[$reason] ?? 'Review the Phase 4D evidence chain for this blocked reason, remediate it in the earlier evidence-producing phase, and re-run Phase 4D before Phase 4E sign-off.',
            ])
            ->values()
            ->all();
    }
}
