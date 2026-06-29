# Agentic Planner Default Selection Runtime Switch Consumption Hook

Phase 3Z adds the planner-path consumption point for the Phase 3Y scoped runtime switch decision.

The hook is `AgenticPlannerDefaultSelectionRuntimeSwitchConsumptionHook`. It is called by `AgenticMarketingActionPlanner` only after the legacy objective opportunity candidate set has been loaded. The hook may call `AgenticPlannerDefaultSelectionScopedRuntimeSwitchService` when `mos.agentic_planner.default_selection.scoped_runtime_switch_enabled` is true.

Phase 3Z is still non-switching. It does not allow canonical planner output to replace legacy output, does not create canonical actions, does not migrate action ownership, does not sync lifecycle state, does not mutate payload/status/dedupe fields, does not rewrite execution parents, and does not dispatch jobs. The planner continues to return legacy output.

Current placement intentionally means an objective with no open legacy candidate chunk records no Phase 3Z consumption diagnostic. The hook is only a candidate-path diagnostic consumer in Phase 3Z; absence of legacy candidates still returns the existing empty legacy planner summary and does not resolve the switch service.

## Runtime Behaviour

- When the switch flag is false, the hook records an in-process diagnostic and does not resolve or call the switch service.
- When the switch flag is true, the hook calls the switch service and consumes the decision as a diagnostic only.
- When the switch decision is `switch_blocked`, selected planner output remains legacy.
- When the switch decision is `switch_ready`, selected planner output still remains legacy in Phase 3Z.
- The reported selected planner is always `legacy`.
- The reported selected action ownership mode is always `legacy_owned`.
- `runtime_behavior_changed` is always `false`.

## Pre-Switch Audit Snapshot

Before Phase 3Z reports `switch_ready_consumed`, it verifies that at least one matching Phase 3Y runtime switch audit snapshot already exists for the workspace and objective scope. The planner runtime only reads this snapshot. It does not create audit rows and does not require database mutation for the snapshot check.

If the switch service reports `switch_ready` but no matching snapshot is found, the hook records `pre_switch_audit_snapshot_missing` and reports `switch_ready_audit_snapshot_missing` instead of `switch_ready_consumed`.

## Diagnostics

Diagnostics are in-process only at:

`mos.agentic_planner_default_selection_runtime_switch_consumption.last_diagnostics`

The diagnostic payload includes:

- `consumption_hook_called`
- `switch_service_called`
- `switch_decision`
- `pre_switch_audit_snapshot_present`
- `blocked_reasons`
- `selected_planner_remains`
- `selected_action_ownership_mode`
- `runtime_behavior_changed`
- `consumption_status`

Phase 3Z proves that the default planner path can safely consume the scoped runtime switch decision. It intentionally does not activate runtime switching.
