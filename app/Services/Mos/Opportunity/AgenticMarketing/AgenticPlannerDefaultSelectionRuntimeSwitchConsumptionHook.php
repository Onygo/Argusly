<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;
use Illuminate\Support\Collection;
use Throwable;

class AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook
{
    public const DIAGNOSTICS_KEY = 'mos.agentic_planner_default_selection_runtime_switch_consumption.last_diagnostics';

    public const STATUS_SWITCH_FLAG_DISABLED = 'switch_flag_disabled';

    public const STATUS_SWITCH_BLOCKED_CONSUMED = 'switch_blocked_consumed';

    public const STATUS_SWITCH_READY_AUDIT_MISSING = 'switch_ready_audit_snapshot_missing';

    public const STATUS_SWITCH_READY_CONSUMED = 'switch_ready_consumed';

    public const STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED = 'empty_scope_diagnostic_recorded';

    /**
     * @param  Collection<int,AgenticMarketingOpportunity>  $legacyCandidates
     * @return array<string,mixed>
     */
    public function consumeObjectiveLegacyCandidates(AgenticMarketingObjective $objective, Collection $legacyCandidates): array
    {
        $requestedScope = $this->requestedScope($objective, $legacyCandidates);
        $diagnostics = $this->baseDiagnostics($requestedScope);

        if ((int) $requestedScope['legacy_candidate_count'] === 0) {
            return $this->storeDiagnostics(array_replace($diagnostics, [
                'phase' => '4B',
                'empty_scope_diagnostic_recorded' => true,
                'workspace_id' => $requestedScope['workspace_id'],
                'objective_ids' => $requestedScope['objective_ids'],
                'legacy_candidate_count' => 0,
                'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
                'runtime_behavior_changed' => false,
                'activation_blocked_reason' => 'no_legacy_candidate_scope',
                'blocked_reasons' => ['no_legacy_candidate_scope'],
                'consumption_status' => self::STATUS_EMPTY_SCOPE_DIAGNOSTIC_RECORDED,
                'activation_flag_defined' => array_key_exists(
                    'scoped_runtime_activation_enabled',
                    (array) config('mos.agentic_planner.default_selection', [])
                ),
                'activation_flag_enabled' => (bool) config('mos.agentic_planner.default_selection.scoped_runtime_activation_enabled', false),
                'activation_flag_consumed_for_switching' => false,
            ]));
        }

        if (! (bool) config('mos.agentic_planner.default_selection.scoped_runtime_switch_enabled', false)) {
            $diagnostics['consumption_status'] = self::STATUS_SWITCH_FLAG_DISABLED;
            $diagnostics['blocked_reasons'] = ['scoped_runtime_switch_feature_flag_disabled'];

            return $this->storeDiagnostics($diagnostics);
        }

        try {
            $decision = app(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::class)->decide([
                'workspace' => $requestedScope['workspace_id'],
                'objectives' => $requestedScope['objective_ids'],
                'site' => $requestedScope['site_id'],
                'detector' => $requestedScope['detector'],
                'limit' => $requestedScope['limit'],
            ]);

            $switchDecision = $this->stringValue($decision['switch_decision'] ?? null)
                ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED;
            $blockedReasons = array_values((array) ($decision['blocked_reasons'] ?? []));
            $auditSnapshotPresent = false;

            if ($switchDecision === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY) {
                $auditSnapshotPresent = $this->matchingPreSwitchAuditSnapshotExists($requestedScope['workspace_id'], $requestedScope['objective_ids']);

                if (! $auditSnapshotPresent) {
                    $blockedReasons[] = 'pre_switch_audit_snapshot_missing';
                }
            }

            $diagnostics = array_replace($diagnostics, [
                'ok' => true,
                'switch_service_called' => true,
                'switch_decision' => $switchDecision,
                'pre_switch_audit_snapshot_present' => $auditSnapshotPresent,
                'blocked_reasons' => collect($blockedReasons)->unique()->values()->all(),
                'consumption_status' => match (true) {
                    $switchDecision === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY && $auditSnapshotPresent => self::STATUS_SWITCH_READY_CONSUMED,
                    $switchDecision === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_READY => self::STATUS_SWITCH_READY_AUDIT_MISSING,
                    default => self::STATUS_SWITCH_BLOCKED_CONSUMED,
                },
                'switch_report' => $decision,
            ]);
        } catch (Throwable $exception) {
            $diagnostics = array_replace($diagnostics, [
                'ok' => false,
                'switch_service_called' => true,
                'switch_decision' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
                'blocked_reasons' => ['runtime_switch_service_exception'],
                'consumption_status' => self::STATUS_SWITCH_BLOCKED_CONSUMED,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        return $this->storeDiagnostics($diagnostics);
    }

    /**
     * @param  Collection<int,AgenticMarketingOpportunity>  $legacyCandidates
     * @return array<string,mixed>
     */
    private function requestedScope(AgenticMarketingObjective $objective, Collection $legacyCandidates): array
    {
        $firstCandidate = $legacyCandidates->first();

        return [
            'workspace_id' => (string) $objective->workspace_id,
            'objective_ids' => [(string) $objective->id],
            'site_id' => $objective->client_site_id ? (string) $objective->client_site_id : null,
            'detector' => $firstCandidate ? $this->stringValue(data_get($firstCandidate->payload, 'detector')) : null,
            'limit' => $legacyCandidates->isEmpty() ? 0 : max(1, $legacyCandidates->count()),
            'legacy_candidate_count' => $legacyCandidates->count(),
            'legacy_candidate_ids' => $legacyCandidates
                ->map(fn (AgenticMarketingOpportunity $candidate): string => (string) $candidate->id)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $requestedScope
     * @return array<string,mixed>
     */
    private function baseDiagnostics(array $requestedScope): array
    {
        return [
            'phase' => '3Z',
            'ok' => true,
            'consumption_hook_called' => true,
            'switch_service_called' => false,
            'switch_decision' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
            'pre_switch_audit_snapshot_present' => false,
            'blocked_reasons' => [],
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'selected_action_ownership_mode' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
            'runtime_behavior_changed' => false,
            'planner_output_changed' => false,
            'canonical_planner_output_selected' => false,
            'empty_scope_diagnostic_recorded' => false,
            'activation_flag_consumed_for_switching' => false,
            'requested_scope' => $requestedScope,
            'consumption_status' => self::STATUS_SWITCH_BLOCKED_CONSUMED,
        ];
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
                return $this->sortedStrings((array) $audit->objective_ids) === $expectedObjectiveIds;
            });
    }

    /**
     * @param  array<string,mixed>  $diagnostics
     * @return array<string,mixed>
     */
    private function storeDiagnostics(array $diagnostics): array
    {
        app()->instance(self::DIAGNOSTICS_KEY, $diagnostics);

        return $diagnostics;
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
