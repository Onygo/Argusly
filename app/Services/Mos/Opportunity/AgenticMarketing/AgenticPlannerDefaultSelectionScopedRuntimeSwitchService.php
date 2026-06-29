<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionScopedRuntimeSwitchService
{
    public const MODE = 'scoped_runtime_switch_skeleton';

    public const DECISION_BLOCKED = 'switch_blocked';

    public const DECISION_READY = 'switch_ready';

    public const AUDIT_PAYLOAD_NAMESPACE = 'mos.agentic_planner.default_selection.scoped_runtime_switch';

    public const AUDIT_PAYLOAD_VERSION = 'phase-3y:v1';

    public const SELECTED_PLANNER = 'legacy';

    public const SELECTED_ACTION_OWNERSHIP_MODE = 'legacy_owned';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionRuntimeSwitchContractService $contract,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $objectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));
        $switchFlagEnabled = (bool) config('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', false);
        $runtimeGuardFlagEnabled = (bool) config('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
        $allowedScope = $this->allowedSwitchScope($workspace, $objectiveIds);
        $operatorAcknowledgedRuntimeSwitchContract = (bool) ($input['ack_runtime_switch_contract'] ?? false)
            || (bool) ($allowedScope['runtime_switch_contract_acknowledged'] ?? false);

        $contractReport = $this->contract->report([
            'workspace' => $workspace,
            'objectives' => $objectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => $limit,
            'ack_metadata_only_review' => (bool) ($input['ack_metadata_only_review'] ?? false),
        ]);

        $phase3xStatus = $this->stringValue($contractReport['final_status'] ?? null)
            ?? AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED;
        $phase3vGuardAllowed = (bool) data_get($contractReport, 'phase_3v_guard_decision.allowed', false);
        $phase3wSelectedPlannerRemains = $this->stringValue(data_get($contractReport, 'phase_3w_planner_path_diagnostic_state.summary.selected_planner_remains'))
            ?? 'missing';

        $blockedReasons = $this->blockedReasons(
            switchFlagEnabled: $switchFlagEnabled,
            runtimeGuardFlagEnabled: $runtimeGuardFlagEnabled,
            workspace: $workspace,
            objectiveIds: $objectiveIds,
            allowedScope: $allowedScope,
            phase3xStatus: $phase3xStatus,
            phase3vGuardAllowed: $phase3vGuardAllowed,
            phase3wSelectedPlannerRemains: $phase3wSelectedPlannerRemains,
            operatorAcknowledgedRuntimeSwitchContract: $operatorAcknowledgedRuntimeSwitchContract,
        );

        $decision = $blockedReasons === [] ? self::DECISION_READY : self::DECISION_BLOCKED;

        return [
            'phase' => '3Y',
            'mode' => self::MODE,
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'phase_3t_status' => $this->stringValue(data_get($contractReport, 'phase_3v_guard_decision.phase_3t_status')) ?? 'missing',
            'phase_3u_eligibility' => $this->stringValue(data_get($contractReport, 'phase_3v_guard_decision.phase_3u_eligibility')) ?? 'missing',
            'phase_3v_guard_allowed' => $phase3vGuardAllowed,
            'phase_3w_selected_planner_remains' => $phase3wSelectedPlannerRemains,
            'phase_3x_contract_status' => $phase3xStatus,
            'switch_flag_enabled' => $switchFlagEnabled,
            'runtime_guard_flag_enabled' => $runtimeGuardFlagEnabled,
            'switch_decision' => $decision,
            'blocked_reasons' => $blockedReasons,
            'operator_acknowledgements' => [
                'metadata_only_review' => (bool) ($input['ack_metadata_only_review'] ?? false),
                'runtime_switch_contract' => $operatorAcknowledgedRuntimeSwitchContract,
            ],
            'allowed_scope_status' => [
                'workspace_id' => $workspace,
                'objective_ids' => $objectiveIds,
                'explicitly_allowed' => $allowedScope !== null,
                'statement' => $allowedScope !== null
                    ? 'Requested workspace/objective scope exactly matches the Phase 3Y switch allowlist.'
                    : 'Requested workspace/objective scope is not exactly allowed for the Phase 3Y switch.',
            ],
            'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
            'selected_planner' => self::SELECTED_PLANNER,
            'selected_action_ownership_mode' => self::SELECTED_ACTION_OWNERSHIP_MODE,
            'payload_namespace' => self::AUDIT_PAYLOAD_NAMESPACE,
            'payload_version' => self::AUDIT_PAYLOAD_VERSION,
            'phase_3x_contract_report' => $contractReport,
            'runtime_switching_implemented' => false,
            'planner_selection_changed' => false,
            'planner_output_changed' => false,
            'runtime_activation_statement' => 'Phase 3Y switch skeleton only. Planner output remains legacy-first; this decision does not create actions, migrate ownership, sync lifecycle, mutate payload/status/dedupe, rewrite execution parents, change approvals/routes, dispatch jobs, or perform percentage/global rollout.',
        ];
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @param  array<string,mixed>|null  $allowedScope
     * @return array<int,string>
     */
    private function blockedReasons(
        bool $switchFlagEnabled,
        bool $runtimeGuardFlagEnabled,
        ?string $workspace,
        array $objectiveIds,
        ?array $allowedScope,
        string $phase3xStatus,
        bool $phase3vGuardAllowed,
        string $phase3wSelectedPlannerRemains,
        bool $operatorAcknowledgedRuntimeSwitchContract,
    ): array {
        $blocked = [];

        if (! $switchFlagEnabled) {
            $blocked[] = 'scoped_runtime_switch_feature_flag_disabled';
        }

        if (! $runtimeGuardFlagEnabled) {
            $blocked[] = 'scoped_runtime_guard_feature_flag_disabled';
        }

        if ($workspace === null) {
            $blocked[] = 'scoped_runtime_switch_requires_explicit_workspace_scope';
        }

        if ($objectiveIds === []) {
            $blocked[] = 'scoped_runtime_switch_requires_explicit_objective_scope';
        }

        if ($allowedScope === null) {
            $blocked[] = 'workspace_objective_scope_not_explicitly_switch_allowed';
        }

        if ($phase3xStatus !== AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_READY) {
            $blocked[] = 'phase_3x_contract_not_ready:'.$phase3xStatus;
        }

        if (! $phase3vGuardAllowed) {
            $blocked[] = 'phase_3v_guard_not_allowed';
        }

        if ($phase3wSelectedPlannerRemains === 'missing') {
            $blocked[] = 'phase_3w_legacy_diagnostic_missing';
        } elseif ($phase3wSelectedPlannerRemains !== self::SELECTED_PLANNER) {
            $blocked[] = 'phase_3w_selected_planner_not_legacy:'.$phase3wSelectedPlannerRemains;
        }

        if (! $operatorAcknowledgedRuntimeSwitchContract) {
            $blocked[] = 'runtime_switch_contract_not_acknowledged';
        }

        return collect($blocked)->unique()->values()->all();
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @return array<string,mixed>|null
     */
    private function allowedSwitchScope(?string $workspace, array $objectiveIds): ?array
    {
        if ($workspace === null || $objectiveIds === []) {
            return null;
        }

        return collect((array) config('mos.agentic_planner.default_selection.switch_allowed_scopes', []))
            ->map(fn (mixed $scope): array => is_array($scope) ? $scope : [])
            ->reject(fn (array $scope): bool => $this->hasWildcardScope($scope))
            ->first(function (array $scope) use ($workspace, $objectiveIds): bool {
                return $this->stringValue($scope['workspace_id'] ?? null) === $workspace
                    && $this->sortedStrings((array) ($scope['objective_ids'] ?? [])) === $this->sortedStrings($objectiveIds);
            });
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function hasWildcardScope(array $scope): bool
    {
        if ($this->stringValue($scope['workspace_id'] ?? null) === '*') {
            return true;
        }

        return collect((array) ($scope['objective_ids'] ?? []))
            ->contains(fn (mixed $id): bool => $this->stringValue($id) === '*');
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
