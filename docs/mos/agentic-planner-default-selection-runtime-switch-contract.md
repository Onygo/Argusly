# Agentic Planner Default Selection Runtime Switch Contract

Phase 3X is a design-only contract for a future, separately flagged scoped runtime switch for MOS Agentic Planner default selection.

It does not switch runtime behavior. It does not add or enable an active runtime switch. Planner selection remains legacy-first.

## Contract Mode

The proposed future mode is `scoped_runtime_switch_contract_only`.

Any later runtime switch must be introduced behind a separate disabled-by-default flag named `MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_SWITCH_ENABLED`. Phase 3X only names that requirement; it does not register, enable, or consume the flag.

## Required Scope

Future switching must require an exact operator-approved workspace id plus a complete objective id allowlist.

Wildcards, global defaults, omitted objectives, inferred scope, percentage rollout, and global/default planner migration are forbidden.

## Read Sources

The Phase 3X contract report composes the Phase 3V scoped runtime guard decision and reads Phase 3W planner-path diagnostics when available.

The contract is `contract_ready` only when:

- Phase 3V guard allows the requested scope.
- Phase 3W diagnostics exist.
- Phase 3W confirms the guard was called and allowed.
- Phase 3W confirms the selected planner remains `legacy`.

All other states are `contract_blocked`.

## Ownership Contract

- Legacy `AgenticMarketingOpportunity` ownership remains rollback authority.
- `AgenticMarketingAction.opportunity_id` must not be rewritten.
- Historical execution parents must not be rewritten.

## Action Creation Contract

- No canonical action creation may occur unless a future switch flag is enabled and the guard is allowed.
- Canonical-created actions must be explicitly distinguishable from legacy-created actions.
- No duplicate open legacy actions may exist.

## Lifecycle Contract

- No lifecycle sync may occur until lifecycle ambiguity/conflict remains zero.
- No status mutation may occur as part of this contract phase.

## Audit Contract

Future runtime switching must persist explicit audit fields before any real switch. The audit must include:

- Guard decision.
- Phase 3T status.
- Phase 3U eligibility.
- Phase 3W diagnostic summary.
- Operator acknowledgement.
- Rollback mode.
- Selected planner.
- Selected action ownership mode.

## Rollback Contract

- Disabling the future switch flag must return behavior to legacy selection without migration.
- Rollback must not require rewriting historical actions.
- Rollback must not mutate dedupe hashes or statuses.

## Dedupe Contract

- Duplicate open legacy action risk must be zero.
- Canonical-created candidates must not collide with legacy dedupe signatures.

## Payload Contract

- No payload rewrite may occur during the contract phase.
- Future switch payload additions must be additive and namespaced.

## Dispatch Contract

- No job dispatch may occur during the contract phase.
- Future switched actions must not dispatch jobs until explicitly approved by existing execution gates.

## Forbidden Mutations

Phase 3X explicitly forbids:

- Creating `AgenticMarketingAction` rows.
- Changing `AgenticMarketingAction.opportunity_id`.
- Changing action status.
- Changing dedupe hashes.
- Changing payloads.
- Syncing lifecycle state.
- Rewriting execution parents.
- Dispatching jobs.
- Changing routes or approvals.
- Enabling global/default planner migration.
- Percentage rollout.

## Inspection Command

Use:

```bash
php artisan mos:inspect-agentic-planner-default-selection-runtime-switch-contract \
  --workspace=WORKSPACE_ID \
  --objectives=OBJECTIVE_ID \
  --limit=1 \
  --ack-metadata-only-review
```

Supported inputs:

- `--workspace=`
- `--objectives=`
- `--site=`
- `--detector=`
- `--limit=`
- `--ack-metadata-only-review`

The command returns a contract report only. It does not alter planner selection or production data.
