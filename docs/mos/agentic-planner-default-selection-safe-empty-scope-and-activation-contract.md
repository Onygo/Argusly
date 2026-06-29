# Agentic Planner Default Selection Safe Empty Scope and Activation Contract

Phase 4B adds observability for empty legacy candidate scopes and defines a disabled activation-flag contract for a future MOS Agentic Planner default-selection activation. It does not activate runtime switching.

## Safe Empty-Scope Diagnostic

When the planner receives an exact workspace/objective scope and no legacy candidate chunk exists, Phase 4B records an in-process diagnostic at the existing runtime-switch consumption diagnostics key.

The diagnostic includes:

- `empty_scope_diagnostic_recorded: true`
- `workspace_id`
- `objective_ids`
- `legacy_candidate_count: 0`
- `selected_planner_remains: legacy`
- `runtime_behavior_changed: false`
- `activation_blocked_reason: no_legacy_candidate_scope`

This diagnostic is in-process only. It does not call the runtime switch service for the empty scope, create `AgenticMarketingAction` rows, write runtime switch audit rows, mutate payloads, statuses, dedupe fields or lifecycle state, rewrite execution parents, change approvals or routes, or dispatch jobs.

## Disabled Activation-Flag Contract

Phase 4B defines:

```php
mos.agentic_planner.default_selection.scoped_runtime_activation_enabled = false
```

The flag is disabled by default and report-only in Phase 4B. It is not consumed by planner runtime to switch planner output.

Forbidden activation shapes remain forbidden:

- no global activation flag
- no percentage rollout
- no wildcard scope
- no inferred scope
- exact workspace/objective scope only

## Phase 4A Report Extension

The guarded activation design report now includes:

- `safe_empty_scope_diagnostic_available`
- `activation_flag_defined`
- `activation_flag_enabled`
- `activation_flag_consumed_for_switching`
- `selected_planner_after_phase_4b`

`selected_planner_after_phase_4b` remains `legacy`, and `runtime_behavior_changed` remains `false`.

## Rollback

Rollback remains legacy-first. Disabling the future activation flag leaves default selection on the legacy planner and requires no data migration, action ownership migration, lifecycle sync, payload/status/dedupe mutation, execution-parent rewrite or audit cleanup.
