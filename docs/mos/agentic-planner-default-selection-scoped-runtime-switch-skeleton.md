# Agentic Planner Default Selection Scoped Runtime Switch Skeleton

Phase 3Y registers the disabled scoped runtime switch skeleton for MOS Agentic Planner default selection. It is a decision and audit contract only; it does not switch planner behavior.

## Configuration

The Phase 3Y switch flag is `mos.agentic_planner.default_selection.scoped_runtime_switch_enabled`, backed by `MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_SWITCH_ENABLED=false`.

The switch uses only exact scopes in `mos.agentic_planner.default_selection.switch_allowed_scopes`:

```php
[
    'workspace_id' => 'workspace-id',
    'objective_ids' => ['objective-id-a', 'objective-id-b'],
    'runtime_switch_contract_acknowledged' => false,
]
```

There is no global switch, no percentage rollout, no inferred scope and no wildcard scope. Phase 3Y also requires the separate Phase 3V scoped runtime guard flag, `mos.agentic_planner.default_selection.scoped_runtime_enabled`, to be enabled.

## Decision Gates

`AgenticPlannerDefaultSelectionScopedRuntimeSwitchService` returns a switch decision only. It reports `switch_blocked` unless every gate passes:

- Phase 3Y switch flag is enabled.
- Phase 3V scoped runtime guard flag is enabled.
- The requested workspace/objective set exactly matches `switch_allowed_scopes`.
- Phase 3X reports `contract_ready`.
- Phase 3V guard is allowed.
- Phase 3W diagnostics confirm the selected planner remains `legacy`.
- The runtime switch contract acknowledgement is present.

When every gate passes, the decision may be `switch_ready`, but the reported `selected_planner` remains `legacy` and the reported selected action ownership mode remains `legacy_owned`.

## Audit Contract

Phase 3Y adds `agentic_planner_default_selection_runtime_switch_audits` for future switch audit records. Planner runtime does not write this table.

The inspection command is read-only by default:

```bash
php artisan mos:inspect-agentic-planner-default-selection-scoped-runtime-switch \
  --workspace=... \
  --objectives=... \
  --limit=1
```

Passing `--write-audit-snapshot` writes exactly one snapshot row for the command result. The row includes workspace id, objective ids, Phase 3T/3U/3V/3W/3X status, switch and guard flag states, decision, blocked reasons, operator acknowledgements, rollback mode, selected planner, selected action ownership mode, payload namespace/version and `created_at`.

## Non-Goals

Phase 3Y does not create `AgenticMarketingAction` rows, change `AgenticMarketingAction.opportunity_id`, migrate ownership, sync lifecycle, mutate payload/status/dedupe fields, rewrite execution parents, dispatch jobs, change routes or approvals, enable a global/default planner migration or perform percentage rollout.
