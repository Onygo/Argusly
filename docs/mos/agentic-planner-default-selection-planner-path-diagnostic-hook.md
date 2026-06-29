# Agentic Planner Default Selection Planner-Path Diagnostic Hook

Phase 3W adds a disabled-by-default planner-path diagnostic hook for MOS Agentic Planner default selection.

The hook is diagnostic-only. It can call the Phase 3V `AgenticPlannerDefaultSelectionScopedRuntimeGuardService` from the existing `AgenticMarketingActionPlanner` path, but only when `mos.agentic_planner.default_selection.scoped_runtime_enabled` is true.

With the flag disabled, the hook is not resolved and the guard is not called. The default planner path remains the existing legacy-first path that selects open `AgenticMarketingOpportunity` rows by legacy priority.

When the flag is enabled, the hook records in-process diagnostic context only:

- `guard_called`
- `guard_allowed`
- `blocked_reasons`
- `rollback_mode`
- requested workspace/objective/site scope
- `runtime_activation_statement`
- `selected_planner_remains: legacy`

Diagnostics are stored only in the current application container at `mos.agentic_planner_default_selection_scoped_runtime_guard.planner_path.last_diagnostics`. They are not written to planner summaries, run payloads, run item payloads, action payloads, metadata, statuses, dedupe fields, canonical opportunities, lifecycle state, audit rows, or jobs.

Phase 3W does not activate Agentic planner default selection. It does not change planner outcome, selected actions, action ownership, canonical ownership, lifecycle sync, dedupe/status fields, payload metadata, execution parents, routes, approvals, rollback snapshots, or job dispatch.

Even an allowed Phase 3V guard decision does not switch runtime behavior. It only proves that the requested scope passed the guard at inspection time while the selected planner remains legacy.

Rollback is config-first: keep `MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_ENABLED=false` or disable it again. No data migration is required because Phase 3W does not persist diagnostics or mutate production data.

Recommended next phase: use Phase 3W diagnostics to define a separately flagged, explicitly scoped runtime switching design with its own ownership, action creation, lifecycle, audit, rollback, dedupe, payload, and dispatch contracts.
