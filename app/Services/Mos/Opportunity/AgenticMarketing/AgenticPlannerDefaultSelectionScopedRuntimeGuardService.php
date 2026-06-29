<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use Illuminate\Support\Collection;

class AgenticPlannerDefaultSelectionScopedRuntimeGuardService
{
    public const MODE = 'scoped_runtime_guard';

    public const ROLLBACK_MODE = 'legacy_first';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionRolloutReadinessService $readiness,
        private readonly AgenticPlannerDefaultSelectionScopedRolloutPlanService $rolloutPlan,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $requestedObjectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));
        $flagEnabled = (bool) config('mos.agentic_planner.default_selection.scoped_runtime_enabled', false);
        $allowedScope = $this->allowedScope($workspace, $requestedObjectiveIds);
        $operatorAcknowledgedMetadataOnlyReview = (bool) ($input['ack_metadata_only_review'] ?? false)
            || (bool) ($allowedScope['metadata_only_ok_review_acknowledged'] ?? false);

        $phase3t = $this->readiness->inspect([
            'workspace' => $workspace,
            'objectives' => $requestedObjectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => $limit,
            'include_metadata_only_ok' => true,
        ]);
        $phase3u = $this->rolloutPlan->plan([
            'workspace' => $workspace,
            'objectives' => $requestedObjectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => $limit,
            'include_metadata_only_ok' => true,
        ]);

        $phase3tRows = collect((array) ($phase3t['objective_rows'] ?? []));
        $phase3tObjectiveIds = $phase3tRows
            ->pluck('objective_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $phase3uObjectiveIds = collect((array) ($phase3u['inspected_objectives'] ?? []))
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $blockedReasons = $this->blockedReasons(
            flagEnabled: $flagEnabled,
            workspace: $workspace,
            requestedObjectiveIds: $requestedObjectiveIds,
            allowedScope: $allowedScope,
            operatorAcknowledgedMetadataOnlyReview: $operatorAcknowledgedMetadataOnlyReview,
            phase3t: $phase3t,
            phase3tRows: $phase3tRows,
            phase3tObjectiveIds: $phase3tObjectiveIds,
            phase3u: $phase3u,
            phase3uObjectiveIds: $phase3uObjectiveIds,
        );

        return [
            'allowed' => $blockedReasons === [],
            'mode' => self::MODE,
            'workspace_id' => $workspace,
            'objective_ids' => $requestedObjectiveIds,
            'blocked_reasons' => $blockedReasons,
            'required_operator_acknowledgements' => $operatorAcknowledgedMetadataOnlyReview ? [] : ['metadata_only_ok_review'],
            'rollback_mode' => self::ROLLBACK_MODE,
            'runtime_activation_statement' => 'Scoped runtime guard inspection only. Default selection remains legacy-first; no planner default migration, action creation, ownership migration, lifecycle sync, dedupe/status mutation, payload mutation, route change, metadata write, execution parent rewrite, or job dispatch is performed.',
            'config' => [
                'scoped_runtime_enabled' => $flagEnabled,
                'allowed_scope' => $allowedScope !== null,
                'metadata_only_ok_review_acknowledged' => $operatorAcknowledgedMetadataOnlyReview,
            ],
            'allowed_scope_status' => $this->allowedScopeStatus($workspace, $requestedObjectiveIds, $allowedScope),
            'phase_3t_status' => $this->stringValue($phase3t['rollout_readiness_status'] ?? null) ?? AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE,
            'phase_3u_eligibility' => $this->stringValue($phase3u['rollout_eligibility'] ?? null) ?? AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_BLOCKED,
            'phase_3t_report' => $phase3t,
            'phase_3u_plan' => $phase3u,
        ];
    }

    /**
     * @param  array<int,string>  $requestedObjectiveIds
     * @param  array<string,mixed>|null  $allowedScope
     * @param  Collection<int,array<string,mixed>>  $phase3tRows
     * @param  array<int,string>  $phase3tObjectiveIds
     * @param  array<string,mixed>  $phase3t
     * @param  array<string,mixed>  $phase3u
     * @param  array<int,string>  $phase3uObjectiveIds
     * @return array<int,string>
     */
    private function blockedReasons(
        bool $flagEnabled,
        ?string $workspace,
        array $requestedObjectiveIds,
        ?array $allowedScope,
        bool $operatorAcknowledgedMetadataOnlyReview,
        array $phase3t,
        Collection $phase3tRows,
        array $phase3tObjectiveIds,
        array $phase3u,
        array $phase3uObjectiveIds,
    ): array {
        $blocked = [];

        if (! $flagEnabled) {
            $blocked[] = 'scoped_runtime_feature_flag_disabled';
        }

        if ($workspace === null) {
            $blocked[] = 'scoped_runtime_requires_explicit_workspace_scope';
        }

        if ($requestedObjectiveIds === []) {
            $blocked[] = 'scoped_runtime_requires_explicit_objective_scope';
        }

        if ($allowedScope === null) {
            $blocked[] = 'workspace_objective_scope_not_explicitly_allowed';
        }

        if (! $operatorAcknowledgedMetadataOnlyReview) {
            $blocked[] = 'metadata_only_ok_review_not_acknowledged';
        }

        if (($this->stringValue($phase3t['rollout_readiness_status'] ?? null)) !== AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION) {
            $blocked[] = 'phase_3t_status_not_ready_for_scoped_expansion:'.($this->stringValue($phase3t['rollout_readiness_status'] ?? null) ?? 'missing');
        }

        if (($this->stringValue($phase3u['rollout_eligibility'] ?? null)) !== AgenticPlannerDefaultSelectionScopedRolloutPlanService::ELIGIBILITY_ELIGIBLE) {
            $blocked[] = 'phase_3u_plan_not_eligible:'.($this->stringValue($phase3u['rollout_eligibility'] ?? null) ?? 'missing');
        }

        if ($workspace !== null && $this->stringValue($phase3t['workspace_id'] ?? null) !== $workspace) {
            $blocked[] = 'phase_3t_workspace_scope_mismatch:'.($this->stringValue($phase3t['workspace_id'] ?? null) ?? 'missing');
        }

        if ($workspace !== null && $this->stringValue($phase3u['workspace_id'] ?? null) !== $workspace) {
            $blocked[] = 'phase_3u_workspace_scope_mismatch:'.($this->stringValue($phase3u['workspace_id'] ?? null) ?? 'missing');
        }

        if ($requestedObjectiveIds !== [] && $this->sortedStrings($phase3tObjectiveIds) !== $this->sortedStrings($requestedObjectiveIds)) {
            $blocked[] = 'phase_3t_objective_scope_mismatch';
        }

        if ($requestedObjectiveIds !== [] && $this->sortedStrings($phase3uObjectiveIds) !== $this->sortedStrings($requestedObjectiveIds)) {
            $blocked[] = 'phase_3u_objective_scope_mismatch';
        }

        if ($phase3tRows->isEmpty()) {
            $blocked[] = 'phase_3t_returned_no_objective_rows';
        }

        if ($phase3tRows->contains(fn (array $row): bool => ! array_key_exists('duplicate_open_action_risk_count', $row) || (int) $row['duplicate_open_action_risk_count'] !== 0)) {
            $blocked[] = 'duplicate_open_action_risk_is_not_zero';
        }

        if (! (bool) data_get($phase3u, 'duplicate_risk_confirmation.confirmed', false)) {
            $blocked[] = 'phase_3u_duplicate_risk_not_confirmed_zero';
        }

        if ($phase3tRows->contains(fn (array $row): bool => ! (bool) ($row['canonical_legacy_order_exact_match'] ?? false))) {
            $blocked[] = 'phase_3t_order_parity_not_confirmed';
        }

        if (! (bool) data_get($phase3u, 'order_parity_confirmation.confirmed', false)) {
            $blocked[] = 'phase_3u_order_parity_not_confirmed';
        }

        if ($phase3tRows->contains(fn (array $row): bool => ($row['phase_3i_continuity_status'] ?? null) !== 'no_blockers')) {
            $blocked[] = 'phase_3i_continuity_blockers_present';
        }

        if ($phase3tRows->contains(fn (array $row): bool => ($row['phase_3j_lifecycle_status'] ?? null) !== 'no_ambiguity_or_conflict')) {
            $blocked[] = 'phase_3j_lifecycle_blockers_present';
        }

        return collect($blocked)->unique()->values()->all();
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @return array<string,mixed>|null
     */
    private function allowedScope(?string $workspace, array $objectiveIds): ?array
    {
        if ($workspace === null || $objectiveIds === []) {
            return null;
        }

        return collect((array) config('mos.agentic_planner.default_selection.allowed_scopes', []))
            ->map(fn (mixed $scope): array => is_array($scope) ? $scope : [])
            ->first(function (array $scope) use ($workspace, $objectiveIds): bool {
                return $this->stringValue($scope['workspace_id'] ?? null) === $workspace
                    && $this->sortedStrings((array) ($scope['objective_ids'] ?? [])) === $this->sortedStrings($objectiveIds);
            });
    }

    /**
     * @param  array<int,string>  $objectiveIds
     * @param  array<string,mixed>|null  $allowedScope
     * @return array<string,mixed>
     */
    private function allowedScopeStatus(?string $workspace, array $objectiveIds, ?array $allowedScope): array
    {
        return [
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'explicitly_allowed' => $allowedScope !== null,
            'statement' => $allowedScope !== null
                ? 'Requested workspace/objective scope exactly matches operator-approved config.'
                : 'Requested workspace/objective scope is not exactly allowed in config.',
        ];
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
