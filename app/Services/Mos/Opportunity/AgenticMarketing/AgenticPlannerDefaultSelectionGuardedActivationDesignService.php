<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;

class AgenticPlannerDefaultSelectionGuardedActivationDesignService
{
    public const MODE = 'guarded_activation_design_only';

    public const EMPTY_CANDIDATE_OBSERVABILITY_DECISION = 'require_future_safe_empty_scope_diagnostic_before_activation';

    public const ACTIVATION_FLAG_CONFIG_KEY = 'mos.agentic_planner.default_selection.scoped_runtime_activation_enabled';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionScopedRuntimeSwitchService $switch,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function report(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $objectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));

        $phase3y = $this->switch->decide([
            'workspace' => $workspace,
            'objectives' => $objectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => $limit,
            'ack_metadata_only_review' => (bool) ($input['ack_metadata_only_review'] ?? false),
            'ack_runtime_switch_contract' => (bool) ($input['ack_runtime_switch_contract'] ?? false),
        ]);

        $phase3x = (array) ($phase3y['phase_3x_contract_report'] ?? []);
        $phase3v = (array) data_get($phase3x, 'phase_3v_guard_decision', []);
        $phase3u = (array) data_get($phase3v, 'phase_3u_plan', []);
        $phase3t = (array) data_get($phase3v, 'phase_3t_report', []);
        $phase3w = (array) data_get($phase3x, 'phase_3w_planner_path_diagnostic_state', []);
        $phase3z = $this->phase3zDiagnostics();
        $activationFlagDefined = $this->activationFlagDefined();
        $activationFlagEnabled = (bool) config(self::ACTIVATION_FLAG_CONFIG_KEY, false);

        $matchingAuditSnapshotExists = $this->matchingPreSwitchAuditSnapshotExists($workspace, $objectiveIds);
        $readinessChain = $this->readinessChainStatus(
            phase3t: $phase3t,
            phase3u: $phase3u,
            phase3v: $phase3v,
            phase3w: $phase3w,
            phase3x: $phase3x,
            phase3y: $phase3y,
            phase3z: $phase3z,
            matchingAuditSnapshotExists: $matchingAuditSnapshotExists,
            requestedWorkspace: $workspace,
            requestedObjectiveIds: $objectiveIds,
            activationFlagDefined: $activationFlagDefined,
            activationFlagEnabled: $activationFlagEnabled,
        );

        $activationGates = $this->requiredActivationGates(
            workspace: $workspace,
            objectiveIds: $objectiveIds,
            phase3t: $phase3t,
            phase3u: $phase3u,
            phase3v: $phase3v,
            phase3w: $phase3w,
            phase3x: $phase3x,
            phase3y: $phase3y,
            phase3z: $phase3z,
            matchingAuditSnapshotExists: $matchingAuditSnapshotExists,
            activationFlagDefined: $activationFlagDefined,
            activationFlagEnabled: $activationFlagEnabled,
        );
        $blockedReasons = $this->blockedReasons($activationGates, (array) ($phase3y['blocked_reasons'] ?? []));
        $activationCandidate = $blockedReasons === [];

        return [
            'phase' => '4A',
            'mode' => self::MODE,
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'readiness_chain_status' => $readinessChain,
            'activation_candidate' => $activationCandidate ? 'yes' : 'no',
            'activation_candidate_bool' => $activationCandidate,
            'blocked_reasons' => $blockedReasons,
            'required_activation_gates' => $activationGates,
            'required_rollback_gates' => $this->requiredRollbackGates(),
            'required_audit_before_use_gates' => $this->requiredAuditBeforeUseGates($matchingAuditSnapshotExists),
            'required_empty_candidate_observability_decision' => $this->emptyCandidateObservabilityDecision(),
            'safe_empty_scope_diagnostic_available' => true,
            'activation_flag_config_key' => self::ACTIVATION_FLAG_CONFIG_KEY,
            'activation_flag_defined' => $activationFlagDefined,
            'activation_flag_enabled' => $activationFlagEnabled,
            'activation_flag_consumed_for_switching' => false,
            'selected_planner_current' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'selected_planner_after_phase_4a' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'selected_planner_after_phase_4b' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'selected_action_ownership_mode_after_phase_4a' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
            'runtime_behavior_changed' => false,
            'planner_output_changed' => false,
            'canonical_planner_output_selected' => false,
            'activation_flag_added' => $activationFlagDefined,
            'runtime_activation_implemented' => false,
            'phase_3t_readiness_report' => $phase3t,
            'phase_3u_rollout_plan' => $phase3u,
            'phase_3v_guard_decision' => $phase3v,
            'phase_3w_diagnostics' => $phase3w,
            'phase_3x_contract_report' => $phase3x,
            'phase_3y_switch_decision' => $phase3y,
            'phase_3y_matching_audit_snapshot_exists' => $matchingAuditSnapshotExists,
            'phase_3z_consumption_diagnostics' => $phase3z,
            'forbidden_runtime_effects' => $this->forbiddenRuntimeEffects(),
            'phase_4a_statement' => 'Phase 4A is activation design only. Planner selection and planner output remain legacy; this report does not create actions, migrate ownership, sync lifecycle, mutate payload/status/dedupe fields, rewrite execution parents, write audits, dispatch jobs, change approvals/routes, or enable global/percentage rollout.',
            'phase_4b_statement' => 'Phase 4B adds in-process empty-scope diagnostics and defines a disabled, exact-scope activation flag contract. The flag is reported only and is not consumed to switch planner output.',
        ];
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     * @param  array<string,mixed>  $phase3v
     * @param  array<string,mixed>  $phase3w
     * @param  array<string,mixed>  $phase3x
     * @param  array<string,mixed>  $phase3y
     * @param  array<string,mixed>|null  $phase3z
     * @param  array<int,string>  $requestedObjectiveIds
     * @return array<string,mixed>
     */
    private function readinessChainStatus(
        array $phase3t,
        array $phase3u,
        array $phase3v,
        array $phase3w,
        array $phase3x,
        array $phase3y,
        ?array $phase3z,
        bool $matchingAuditSnapshotExists,
        ?string $requestedWorkspace,
        array $requestedObjectiveIds,
        bool $activationFlagDefined,
        bool $activationFlagEnabled,
    ): array {
        return [
            'phase_3t' => [
                'status' => $this->stringValue($phase3t['rollout_readiness_status'] ?? null)
                    ?? $this->stringValue($phase3v['phase_3t_status'] ?? null)
                    ?? $this->stringValue($phase3y['phase_3t_status'] ?? null)
                    ?? 'missing',
            ],
            'phase_3u' => [
                'status' => $this->stringValue($phase3u['rollout_eligibility'] ?? null)
                    ?? $this->stringValue($phase3v['phase_3u_eligibility'] ?? null)
                    ?? $this->stringValue($phase3y['phase_3u_eligibility'] ?? null)
                    ?? 'missing',
            ],
            'phase_3v' => [
                'status' => (bool) ($phase3v['allowed'] ?? false) ? 'guard_allowed' : 'guard_blocked',
            ],
            'phase_3w' => [
                'status' => $this->stringValue(data_get($phase3w, 'summary.selected_planner_remains'))
                    ?? $this->stringValue($phase3y['phase_3w_selected_planner_remains'] ?? null)
                    ?? 'missing',
                'available' => (bool) ($phase3w['available'] ?? false),
            ],
            'phase_3x' => [
                'status' => $this->stringValue($phase3x['final_status'] ?? null)
                    ?? $this->stringValue($phase3y['phase_3x_contract_status'] ?? null)
                    ?? 'missing',
            ],
            'phase_3y' => [
                'status' => $this->stringValue($phase3y['switch_decision'] ?? null)
                    ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
                'matching_audit_snapshot_exists' => $matchingAuditSnapshotExists,
            ],
            'phase_3z' => [
                'status' => $this->stringValue($phase3z['consumption_status'] ?? null) ?? 'missing',
                'available' => $phase3z !== null,
                'exact_scope_match' => $phase3z !== null && $this->scopeMatches(
                    requestedWorkspace: $requestedWorkspace,
                    requestedObjectiveIds: $requestedObjectiveIds,
                    actualWorkspace: $this->stringValue(data_get($phase3z, 'requested_scope.workspace_id')),
                    actualObjectiveIds: (array) data_get($phase3z, 'requested_scope.objective_ids', []),
                ),
            ],
            'phase_4b' => [
                'safe_empty_scope_diagnostic_available' => true,
                'activation_flag_defined' => $activationFlagDefined,
                'activation_flag_enabled' => $activationFlagEnabled,
                'activation_flag_consumed_for_switching' => false,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     * @param  array<string,mixed>  $phase3v
     * @param  array<string,mixed>  $phase3w
     * @param  array<string,mixed>  $phase3x
     * @param  array<string,mixed>  $phase3y
     * @param  array<string,mixed>|null  $phase3z
     * @return array<int,array<string,mixed>>
     */
    private function requiredActivationGates(
        ?string $workspace,
        array $objectiveIds,
        array $phase3t,
        array $phase3u,
        array $phase3v,
        array $phase3w,
        array $phase3x,
        array $phase3y,
        ?array $phase3z,
        bool $matchingAuditSnapshotExists,
        bool $activationFlagDefined,
        bool $activationFlagEnabled,
    ): array {
        $phase3tStatus = $this->stringValue($phase3t['rollout_readiness_status'] ?? null)
            ?? $this->stringValue($phase3v['phase_3t_status'] ?? null)
            ?? $this->stringValue($phase3y['phase_3t_status'] ?? null)
            ?? 'missing';
        $phase3uEligibility = $this->stringValue($phase3u['rollout_eligibility'] ?? null)
            ?? $this->stringValue($phase3v['phase_3u_eligibility'] ?? null)
            ?? $this->stringValue($phase3y['phase_3u_eligibility'] ?? null)
            ?? 'missing';
        $phase3wSelectedPlanner = $this->stringValue(data_get($phase3w, 'summary.selected_planner_remains'))
            ?? $this->stringValue($phase3y['phase_3w_selected_planner_remains'] ?? null)
            ?? 'missing';
        $phase3xStatus = $this->stringValue($phase3x['final_status'] ?? null)
            ?? $this->stringValue($phase3y['phase_3x_contract_status'] ?? null)
            ?? 'missing';
        $phase3yDecision = $this->stringValue($phase3y['switch_decision'] ?? null)
            ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED;
        $phase3zStatus = $this->stringValue($phase3z['consumption_status'] ?? null) ?? 'missing';
        $phase3zScopeMatches = $phase3z !== null && $this->scopeMatches(
            requestedWorkspace: $workspace,
            requestedObjectiveIds: $objectiveIds,
            actualWorkspace: $this->stringValue(data_get($phase3z, 'requested_scope.workspace_id')),
            actualObjectiveIds: (array) data_get($phase3z, 'requested_scope.objective_ids', []),
        );

        $operatorAcknowledgements = (array) ($phase3y['operator_acknowledgements'] ?? []);

        return [
            $this->gate(
                id: 'exact_workspace_objective_scope',
                required: 'Requested workspace and objective ids must exactly match the Phase 3Y switch allowlist.',
                actual: (array) ($phase3y['allowed_scope_status'] ?? []),
                passed: $workspace !== null
                    && $objectiveIds !== []
                    && (bool) data_get($phase3y, 'allowed_scope_status.explicitly_allowed', false)
            ),
            $this->gate(
                id: 'phase_3t_ready_for_scoped_expansion',
                required: AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION,
                actual: $phase3tStatus,
                passed: $phase3tStatus === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION
            ),
            $this->gate(
                id: 'phase_3u_eligible',
                required: AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE,
                actual: $phase3uEligibility,
                passed: $phase3uEligibility === AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE
            ),
            $this->gate(
                id: 'phase_3v_guard_allowed',
                required: 'allowed',
                actual: (bool) ($phase3v['allowed'] ?? $phase3y['phase_3v_guard_allowed'] ?? false) ? 'allowed' : 'blocked',
                passed: (bool) ($phase3v['allowed'] ?? $phase3y['phase_3v_guard_allowed'] ?? false)
            ),
            $this->gate(
                id: 'phase_3w_selected_planner_remains_legacy',
                required: AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
                actual: $phase3wSelectedPlanner,
                passed: $phase3wSelectedPlanner === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER
            ),
            $this->gate(
                id: 'phase_3x_contract_ready',
                required: AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY,
                actual: $phase3xStatus,
                passed: $phase3xStatus === AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY
            ),
            $this->gate(
                id: 'phase_3y_switch_ready',
                required: AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY,
                actual: $phase3yDecision,
                passed: $phase3yDecision === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY
            ),
            $this->gate(
                id: 'matching_phase_3y_audit_snapshot_exists',
                required: 'present',
                actual: $matchingAuditSnapshotExists ? 'present' : 'missing',
                passed: $matchingAuditSnapshotExists
            ),
            $this->gate(
                id: 'phase_3z_switch_ready_consumed',
                required: AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED,
                actual: $phase3zStatus,
                passed: $phase3zScopeMatches
                    && $phase3zStatus === AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED
                    && (bool) ($phase3z['pre_switch_audit_snapshot_present'] ?? false)
                    && (bool) ($phase3z['runtime_behavior_changed'] ?? true) === false
            ),
            $this->gate(
                id: 'activation_flag_contract_defined_disabled_and_non_consuming',
                required: 'defined, disabled, report-only',
                actual: [
                    'activation_flag_defined' => $activationFlagDefined,
                    'activation_flag_enabled' => $activationFlagEnabled,
                    'activation_flag_consumed_for_switching' => false,
                ],
                passed: $activationFlagDefined && ! $activationFlagEnabled
            ),
            $this->gate(
                id: 'operator_acknowledgements_present',
                required: 'metadata_only_review and runtime_switch_contract',
                actual: $operatorAcknowledgements,
                passed: (bool) ($operatorAcknowledgements['metadata_only_review'] ?? false)
                    && (bool) ($operatorAcknowledgements['runtime_switch_contract'] ?? false)
            ),
            $this->gate(
                id: 'duplicate_risk_zero',
                required: 'zero duplicate open legacy action risk',
                actual: $this->duplicateRiskActual($phase3t, $phase3u),
                passed: $this->duplicateRiskZero($phase3t, $phase3u)
            ),
            $this->gate(
                id: 'order_parity_confirmed',
                required: 'canonical and legacy order parity confirmed',
                actual: $this->orderParityActual($phase3t, $phase3u),
                passed: $this->orderParityConfirmed($phase3t, $phase3u)
            ),
            $this->gate(
                id: 'lifecycle_continuity_blockers_absent',
                required: 'lifecycle ambiguity/conflict and continuity blockers absent',
                actual: $this->lifecycleContinuityActual($phase3t),
                passed: $this->lifecycleContinuityBlockersAbsent($phase3t)
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gate(string $id, mixed $required, mixed $actual, bool $passed): array
    {
        return [
            'id' => $id,
            'required' => $required,
            'actual' => $actual,
            'passed' => $passed,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $activationGates
     * @param  array<int,mixed>  $phase3yBlockedReasons
     * @return array<int,string>
     */
    private function blockedReasons(array $activationGates, array $phase3yBlockedReasons): array
    {
        $gateReasons = collect($activationGates)
            ->filter(fn (array $gate): bool => ! (bool) ($gate['passed'] ?? false))
            ->map(fn (array $gate): string => (string) $gate['id'])
            ->values();

        return $gateReasons
            ->merge(collect($phase3yBlockedReasons)->map(fn (mixed $reason): string => (string) $reason))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function requiredRollbackGates(): array
    {
        return [
            $this->gate('disable_future_activation_flag_returns_to_legacy_selection', 'documented', 'documented', true),
            $this->gate('no_data_migration_required', 'documented', 'documented', true),
            $this->gate('no_historical_action_rewrite', 'documented', 'documented', true),
            $this->gate('no_dedupe_or_status_mutation', 'documented', 'documented', true),
            $this->gate('canonical_metadata_remains_additive_only', 'documented', 'documented', true),
            $this->gate('legacy_agentic_marketing_opportunity_ownership_remains_rollback_authority', 'documented', 'documented', true),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function requiredAuditBeforeUseGates(bool $matchingAuditSnapshotExists): array
    {
        return [
            $this->gate('matching_phase_3y_audit_snapshot_exists_before_use', 'present', $matchingAuditSnapshotExists ? 'present' : 'missing', $matchingAuditSnapshotExists),
            $this->gate('audit_snapshot_scope_matches_workspace_and_objectives', 'exact scope', $matchingAuditSnapshotExists ? 'exact scope' : 'unverified', $matchingAuditSnapshotExists),
            $this->gate('audit_snapshot_includes_3t_through_3x_statuses', 'required', $matchingAuditSnapshotExists ? 'readable from Phase 3Y audit snapshot' : 'unverified', $matchingAuditSnapshotExists),
            $this->gate('audit_snapshot_includes_operator_acknowledgements', 'required', $matchingAuditSnapshotExists ? 'readable from Phase 3Y audit snapshot' : 'unverified', $matchingAuditSnapshotExists),
            $this->gate('audit_snapshot_includes_selected_planner_and_ownership_mode', 'legacy and legacy_owned', $matchingAuditSnapshotExists ? 'readable from Phase 3Y audit snapshot' : 'unverified', $matchingAuditSnapshotExists),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyCandidateObservabilityDecision(): array
    {
        return [
            'decision' => self::EMPTY_CANDIDATE_OBSERVABILITY_DECISION,
            'keep_current_phase_3z_behavior' => false,
            'safe_empty_scope_diagnostic_available' => true,
            'current_phase_4b_behavior' => 'Phase 4B records an in-process diagnostic when no legacy candidate chunk exists and does not call the switch service for that empty scope.',
            'future_activation_requirement' => 'Before any real activation, empty candidate scopes must continue to emit a safe diagnostic proving the switch decision was intentionally not consumed because the scope was empty.',
            'phase_4a_behavior_changed' => false,
            'phase_4b_behavior_changed' => false,
            'design_only' => false,
        ];
    }

    private function activationFlagDefined(): bool
    {
        return array_key_exists(
            'scoped_runtime_activation_enabled',
            (array) config('mos.agentic_planner.default_selection', [])
        );
    }

    /**
     * @return array<int,string>
     */
    private function forbiddenRuntimeEffects(): array
    {
        return [
            'no actual planner switching',
            'no canonical planner output replacing legacy output',
            'no AgenticMarketingAction creation',
            'no AgenticMarketingAction.opportunity_id changes',
            'no ownership migration',
            'no lifecycle sync',
            'no payload/status/dedupe mutation',
            'no execution-parent rewrite',
            'no job dispatch',
            'no route or approval change',
            'no global/default migration',
            'no percentage rollout',
            'no audit writes',
        ];
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     * @return array<string,mixed>
     */
    private function duplicateRiskActual(array $phase3t, array $phase3u): array
    {
        return [
            'phase_3u_confirmed' => (bool) data_get($phase3u, 'duplicate_risk_confirmation.confirmed', false),
            'phase_3t_counts' => collect((array) ($phase3t['objective_rows'] ?? []))
                ->map(fn (array $row): int => (int) ($row['duplicate_open_action_risk_count'] ?? -1))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     */
    private function duplicateRiskZero(array $phase3t, array $phase3u): bool
    {
        $rows = collect((array) ($phase3t['objective_rows'] ?? []));

        return (bool) data_get($phase3u, 'duplicate_risk_confirmation.confirmed', false)
            && $rows->isNotEmpty()
            && $rows->every(fn (array $row): bool => (int) ($row['duplicate_open_action_risk_count'] ?? -1) === 0);
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     * @return array<string,mixed>
     */
    private function orderParityActual(array $phase3t, array $phase3u): array
    {
        return [
            'phase_3u_confirmed' => (bool) data_get($phase3u, 'order_parity_confirmation.confirmed', false),
            'phase_3t_rows' => collect((array) ($phase3t['objective_rows'] ?? []))
                ->map(fn (array $row): bool => (bool) ($row['canonical_legacy_order_exact_match'] ?? false))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     */
    private function orderParityConfirmed(array $phase3t, array $phase3u): bool
    {
        $rows = collect((array) ($phase3t['objective_rows'] ?? []));

        return (bool) data_get($phase3u, 'order_parity_confirmation.confirmed', false)
            && $rows->isNotEmpty()
            && $rows->every(fn (array $row): bool => (bool) ($row['canonical_legacy_order_exact_match'] ?? false));
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @return array<int,array<string,string>>
     */
    private function lifecycleContinuityActual(array $phase3t): array
    {
        return collect((array) ($phase3t['objective_rows'] ?? []))
            ->map(fn (array $row): array => [
                'objective_id' => (string) ($row['objective_id'] ?? 'missing'),
                'continuity' => (string) ($row['phase_3i_continuity_status'] ?? 'missing'),
                'lifecycle' => (string) ($row['phase_3j_lifecycle_status'] ?? 'missing'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $phase3t
     */
    private function lifecycleContinuityBlockersAbsent(array $phase3t): bool
    {
        $rows = collect((array) ($phase3t['objective_rows'] ?? []));

        return $rows->isNotEmpty()
            && $rows->every(fn (array $row): bool => ($row['phase_3i_continuity_status'] ?? null) === 'no_blockers'
                && ($row['phase_3j_lifecycle_status'] ?? null) === 'no_ambiguity_or_conflict');
    }

    /**
     * @param  array<int,string>  $requestedObjectiveIds
     * @param  array<int,mixed>  $actualObjectiveIds
     */
    private function scopeMatches(?string $requestedWorkspace, array $requestedObjectiveIds, ?string $actualWorkspace, array $actualObjectiveIds): bool
    {
        return $requestedWorkspace !== null
            && $actualWorkspace === $requestedWorkspace
            && $requestedObjectiveIds !== []
            && $this->sortedStrings($requestedObjectiveIds) === $this->sortedStrings($actualObjectiveIds);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function phase3zDiagnostics(): ?array
    {
        if (! app()->bound(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY)) {
            return null;
        }

        $diagnostics = app(AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::DIAGNOSTICS_KEY);

        return is_array($diagnostics) ? $diagnostics : null;
    }

    /**
     * @param  array<int,string>  $objectiveIds
     */
    private function matchingPreSwitchAuditSnapshotExists(?string $workspaceId, array $objectiveIds): bool
    {
        if ($workspaceId === null || $objectiveIds === []) {
            return false;
        }

        $expectedObjectiveIds = $this->sortedStrings($objectiveIds);

        return AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()
            ->where('workspace_id', $workspaceId)
            ->where('switch_decision', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY)
            ->where('selected_planner', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER)
            ->where('selected_action_ownership_mode', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE)
            ->where('payload_namespace', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE)
            ->where('payload_version', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->contains(function (AgenticPlannerDefaultSelectionRuntimeSwitchAudit $audit) use ($expectedObjectiveIds): bool {
                return $this->sortedStrings((array) $audit->objective_ids) === $expectedObjectiveIds
                    && $audit->phase_3t_status === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION
                    && $audit->phase_3u_eligibility === AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE
                    && $audit->phase_3v_guard_allowed === true
                    && $audit->phase_3w_selected_planner_remains === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER
                    && $audit->phase_3x_contract_status === AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY
                    && (bool) data_get($audit->operator_acknowledgements, 'metadata_only_review', false)
                    && (bool) data_get($audit->operator_acknowledgements, 'runtime_switch_contract', false);
            });
    }

    /**
     * @return array<int,string>
     */
    private function objectiveIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $id): ?string => $this->stringValue($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function sortedStrings(array $values): array
    {
        $values = collect($values)
            ->map(fn (mixed $value): ?string => $this->stringValue($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($values, SORT_STRING);

        return $values;
    }
}
