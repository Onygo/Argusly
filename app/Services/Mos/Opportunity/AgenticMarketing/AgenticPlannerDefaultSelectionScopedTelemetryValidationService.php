<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;
use App\Models\Workspace;

class AgenticPlannerDefaultSelectionScopedTelemetryValidationService
{
    public const MODE = 'scoped_telemetry_validation_only';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionGuardedActivationDesignService $activationDesign,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function validate(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $objectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $site = $this->stringValue($input['site'] ?? null);
        $detector = $this->stringValue($input['detector'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));
        $requireRealScope = (bool) ($input['require_real_scope'] ?? false);

        $phase4a = $this->activationDesign->report([
            'workspace' => $workspace,
            'objectives' => $objectiveIds,
            'site' => $site,
            'detector' => $detector,
            'limit' => $limit,
            'ack_metadata_only_review' => (bool) ($input['ack_metadata_only_review'] ?? false),
            'ack_runtime_switch_contract' => (bool) ($input['ack_runtime_switch_contract'] ?? false),
        ]);

        $scope = $this->realScopeStatus($workspace, $objectiveIds);
        $phase3z = $this->phase3zDiagnostics();
        $auditSnapshotPresent = $this->matchingAuditSnapshotExists($workspace, $objectiveIds);
        $phaseSummary = $this->phaseSummary($phase4a, $auditSnapshotPresent);
        $legacyCandidateCount = $this->legacyCandidateCount($workspace, $objectiveIds, $site, $detector);
        $emptyScopeDiagnostic = $this->emptyScopeDiagnosticStatus($phase3z, $workspace, $objectiveIds);
        $checklist = $this->preActivationAcceptanceChecklist(
            phase4a: $phase4a,
            phaseSummary: $phaseSummary,
            auditSnapshotPresent: $auditSnapshotPresent,
            phase3z: $phase3z,
            emptyScopeDiagnosticStatus: $emptyScopeDiagnostic,
            requestedWorkspace: $workspace,
            requestedObjectiveIds: $objectiveIds,
        );

        $blockedReasons = $this->blockedReasons(
            checklist: $checklist,
            realScopeDetected: (bool) $scope['real_scope_detected'],
            requireRealScope: $requireRealScope,
        );
        $telemetryComplete = $blockedReasons === [];

        return [
            'phase' => '4C',
            'mode' => self::MODE,
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'real_scope_detected' => (bool) $scope['real_scope_detected'],
            'objective_records_found' => (bool) $scope['objective_records_found'],
            'objective_records_found_status' => (bool) $scope['objective_records_found'] ? 'yes' : 'no',
            'real_scope_status' => $scope,
            'legacy_candidate_count' => $legacyCandidateCount,
            'empty_scope_diagnostic_status' => $emptyScopeDiagnostic,
            'phase_3t_through_4b_status_summary' => $phaseSummary,
            'activation_flag_state' => $this->activationFlagState(),
            'activation_flag_enabled' => (bool) config(AgenticPlannerDefaultSelectionGuardedActivationDesignService::ACTIVATION_FLAG_CONFIG_KEY, false),
            'activation_flag_consumed_for_switching' => false,
            'selected_planner' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'audit_snapshot_present' => $auditSnapshotPresent,
            'audit_snapshot_present_status' => $auditSnapshotPresent ? 'yes' : 'no',
            'telemetry_complete' => $telemetryComplete,
            'telemetry_complete_status' => $telemetryComplete ? 'yes' : 'no',
            'telemetry_blocked_reasons' => $blockedReasons,
            'pre_activation_acceptance_checklist' => $checklist,
            'phase_4a_activation_design_report' => $phase4a,
            'phase_3z_consumption_diagnostics' => $phase3z,
            'validation_statement' => 'Phase 4C validates real scoped telemetry only. Planner output remains legacy and runtime behavior is unchanged.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function phaseSummary(array $phase4a, bool $auditSnapshotPresent): array
    {
        $chain = (array) ($phase4a['readiness_chain_status'] ?? []);

        return [
            'phase_3t' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3t.status')) ?? 'missing',
            ],
            'phase_3u' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3u.status')) ?? 'missing',
            ],
            'phase_3v' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3v.status')) ?? 'missing',
            ],
            'phase_3w' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3w.status')) ?? 'missing',
                'available' => (bool) data_get($chain, 'phase_3w.available', false),
            ],
            'phase_3x' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3x.status')) ?? 'missing',
            ],
            'phase_3y' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3y.status')) ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
                'matching_audit_snapshot_exists' => $auditSnapshotPresent,
                'audit_snapshot_status' => $auditSnapshotPresent ? 'present' : 'missing',
            ],
            'phase_3z' => [
                'status' => $this->stringValue(data_get($chain, 'phase_3z.status')) ?? 'missing',
                'available' => (bool) data_get($chain, 'phase_3z.available', false),
                'exact_scope_match' => (bool) data_get($chain, 'phase_3z.exact_scope_match', false),
            ],
            'phase_4a' => [
                'status' => (bool) ($phase4a['activation_candidate_bool'] ?? false) ? 'activation_candidate_report_available' : 'activation_candidate_report_blocked',
                'activation_candidate_report_available' => true,
            ],
            'phase_4b' => [
                'status' => (bool) ($phase4a['safe_empty_scope_diagnostic_available'] ?? false) ? 'diagnostics_available' : 'diagnostics_missing',
                'safe_empty_scope_diagnostic_available' => (bool) ($phase4a['safe_empty_scope_diagnostic_available'] ?? false),
                'activation_flag_defined' => (bool) ($phase4a['activation_flag_defined'] ?? false),
                'activation_flag_enabled' => (bool) ($phase4a['activation_flag_enabled'] ?? false),
                'activation_flag_consumed_for_switching' => false,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @return array<string,mixed>
     */
    private function realScopeStatus(?string $workspace, array $objectiveIds): array
    {
        $hasWildcard = $workspace === '*' || collect($objectiveIds)->contains('*');
        $workspaceExists = $workspace !== null && ! $hasWildcard && Workspace::query()->whereKey($workspace)->exists();
        $foundObjectiveIds = [];

        if ($workspaceExists && $objectiveIds !== []) {
            $foundObjectiveIds = AgenticMarketingObjective::query()
                ->where('workspace_id', $workspace)
                ->whereIn('id', $objectiveIds)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $objectiveRecordsFound = $objectiveIds !== []
            && $this->sortedStrings($foundObjectiveIds) === $this->sortedStrings($objectiveIds);

        return [
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'workspace_record_found' => $workspaceExists,
            'objective_records_found' => $objectiveRecordsFound,
            'found_objective_ids' => $foundObjectiveIds,
            'explicit_workspace_objective_scope' => $workspace !== null && $objectiveIds !== [],
            'wildcard_scope_rejected' => $hasWildcard,
            'global_scope_rejected' => $workspace === null || $objectiveIds === [],
            'percentage_scope_rejected' => true,
            'inferred_scope_rejected' => true,
            'real_scope_detected' => $workspaceExists && $objectiveRecordsFound && ! $hasWildcard,
        ];
    }

    /**
     * @param  array<int,string>  $objectiveIds
     */
    private function legacyCandidateCount(?string $workspace, array $objectiveIds, ?string $site, ?string $detector): int
    {
        if ($workspace === null || $objectiveIds === []) {
            return 0;
        }

        return AgenticMarketingOpportunity::query()
            ->whereIn('objective_id', $objectiveIds)
            ->whereHas('objective', function ($query) use ($workspace): void {
                $query->where('workspace_id', $workspace);
            })
            ->when($site !== null, function ($query) use ($site): void {
                $query->where(function ($query) use ($site): void {
                    $query
                        ->where('payload->client_site_id', $site)
                        ->orWhereHas('objective', function ($query) use ($site): void {
                            $query->where('client_site_id', $site);
                        });
                });
            })
            ->when($detector !== null, function ($query) use ($detector): void {
                $query->where('payload->detector', $detector);
            })
            ->count();
    }

    /**
     * @param  array<int,string>  $requestedObjectiveIds
     * @return array<string,mixed>
     */
    private function emptyScopeDiagnosticStatus(?array $phase3z, ?string $requestedWorkspace, array $requestedObjectiveIds): array
    {
        $status = $this->stringValue($phase3z['consumption_status'] ?? null) ?? 'missing';
        $scopeMatches = $phase3z !== null && $this->scopeMatches(
            requestedWorkspace: $requestedWorkspace,
            requestedObjectiveIds: $requestedObjectiveIds,
            actualWorkspace: $this->stringValue(data_get($phase3z, 'requested_scope.workspace_id') ?? data_get($phase3z, 'workspace_id')),
            actualObjectiveIds: (array) (data_get($phase3z, 'requested_scope.objective_ids') ?? data_get($phase3z, 'objective_ids', [])),
        );
        $recorded = $status === AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED
            && (bool) ($phase3z['empty_scope_diagnostic_recorded'] ?? false)
            && $scopeMatches;

        return [
            'status' => $status,
            'available' => $phase3z !== null,
            'recorded' => $recorded,
            'exact_scope_match' => $scopeMatches,
            'safe_empty_scope_diagnostic_available' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4a
     * @param  array<string,mixed>  $phaseSummary
     * @param  array<int,string>  $requestedObjectiveIds
     * @return array<int,array<string,mixed>>
     */
    private function preActivationAcceptanceChecklist(
        array $phase4a,
        array $phaseSummary,
        bool $auditSnapshotPresent,
        ?array $phase3z,
        array $emptyScopeDiagnosticStatus,
        ?string $requestedWorkspace,
        array $requestedObjectiveIds,
    ): array {
        $phase3zStatus = $this->stringValue($phase3z['consumption_status'] ?? null) ?? 'missing';
        $phase3zScopeMatches = $phase3z !== null && $this->scopeMatches(
            requestedWorkspace: $requestedWorkspace,
            requestedObjectiveIds: $requestedObjectiveIds,
            actualWorkspace: $this->stringValue(data_get($phase3z, 'requested_scope.workspace_id') ?? data_get($phase3z, 'workspace_id')),
            actualObjectiveIds: (array) (data_get($phase3z, 'requested_scope.objective_ids') ?? data_get($phase3z, 'objective_ids', [])),
        );
        $phase3zReady = $phase3zScopeMatches
            && $phase3zStatus === AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook::STATUS_SWITCH_READY_CONSUMED
            && (bool) ($phase3z['runtime_behavior_changed'] ?? true) === false;
        $emptyScopeReady = (bool) ($emptyScopeDiagnosticStatus['recorded'] ?? false);

        return [
            $this->check('phase_3t_ready', AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION, data_get($phaseSummary, 'phase_3t.status'), data_get($phaseSummary, 'phase_3t.status') === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION),
            $this->check('phase_3u_eligible', AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE, data_get($phaseSummary, 'phase_3u.status'), data_get($phaseSummary, 'phase_3u.status') === AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE),
            $this->check('phase_3v_guard_allowed', 'guard_allowed', data_get($phaseSummary, 'phase_3v.status'), data_get($phaseSummary, 'phase_3v.status') === 'guard_allowed'),
            $this->check('phase_3w_diagnostics_present_and_legacy', 'available and legacy', data_get($phaseSummary, 'phase_3w'), (bool) data_get($phaseSummary, 'phase_3w.available', false) && data_get($phaseSummary, 'phase_3w.status') === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER),
            $this->check('phase_3x_contract_ready', AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY, data_get($phaseSummary, 'phase_3x.status'), data_get($phaseSummary, 'phase_3x.status') === AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY),
            $this->check('phase_3y_switch_ready', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY, data_get($phaseSummary, 'phase_3y.status'), data_get($phaseSummary, 'phase_3y.status') === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY),
            $this->check('matching_audit_snapshot_present', 'present', $auditSnapshotPresent ? 'present' : 'missing', $auditSnapshotPresent),
            $this->check('phase_3z_consumption_ready_or_safe_empty_scope_diagnostic_present', 'ready consumed or safe empty scope diagnostic', [
                'phase_3z_status' => $phase3zStatus,
                'phase_3z_scope_matches' => $phase3zScopeMatches,
                'empty_scope_diagnostic_recorded' => $emptyScopeReady,
            ], $phase3zReady || $emptyScopeReady),
            $this->check('phase_4a_activation_candidate_report_available', 'available', 'available', isset($phase4a['activation_candidate'])),
            $this->check('phase_4b_empty_scope_diagnostic_available', 'available', data_get($phaseSummary, 'phase_4b.status'), (bool) data_get($phaseSummary, 'phase_4b.safe_empty_scope_diagnostic_available', false)),
            $this->check('activation_flag_disabled_or_non_consuming', 'disabled or non-consuming', [
                'activation_flag_enabled' => (bool) data_get($phaseSummary, 'phase_4b.activation_flag_enabled', false),
                'activation_flag_consumed_for_switching' => false,
            ], ! (bool) data_get($phaseSummary, 'phase_4b.activation_flag_consumed_for_switching', false)),
            $this->check('no_runtime_activation', true, true, true),
            $this->check('planner_output_remains_legacy', AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER, AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER, true),
            $this->check('no_canonical_planner_output_replacing_legacy_output', true, true, true),
            $this->check('activation_flag_report_only_and_non_consuming', true, true, true),
            $this->check('no_action_creation', true, true, true),
            $this->check('no_ownership_migration', true, true, true),
            $this->check('no_lifecycle_sync', true, true, true),
            $this->check('no_payload_status_dedupe_mutation', true, true, true),
            $this->check('no_execution_parent_rewrite', true, true, true),
            $this->check('no_runtime_audit_write', true, true, true),
            $this->check('no_job_dispatch', true, true, true),
            $this->check('no_route_approval_change', true, true, true),
            $this->check('rollback_remains_legacy_first', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE, data_get($phase4a, 'phase_3y_switch_decision.rollback_mode', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE), data_get($phase4a, 'phase_3y_switch_decision.rollback_mode', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE) === AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE),
        ];
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
     * @param  array<int,array<string,mixed>>  $checklist
     * @return array<int,string>
     */
    private function blockedReasons(array $checklist, bool $realScopeDetected, bool $requireRealScope): array
    {
        $blocked = collect($checklist)
            ->reject(fn (array $check): bool => (bool) ($check['passed'] ?? false))
            ->map(fn (array $check): string => (string) $check['id']);

        if (! $realScopeDetected) {
            $blocked->push($requireRealScope ? 'real_scope_required_but_missing' : 'real_scope_not_detected');
        }

        return $blocked
            ->unique()
            ->values()
            ->all();
    }

    private function activationFlagState(): string
    {
        return (bool) config(AgenticPlannerDefaultSelectionGuardedActivationDesignService::ACTIVATION_FLAG_CONFIG_KEY, false)
            ? 'enabled_report_only_non_consuming'
            : 'disabled_report_only_non_consuming';
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
    private function matchingAuditSnapshotExists(?string $workspaceId, array $objectiveIds): bool
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

        sort($values);

        return $values;
    }
}
