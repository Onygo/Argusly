# Agentic Planner Default Selection Guarded Activation Design

Phase 4A defines the guarded activation design for a future MOS Agentic Planner default-selection runtime switch. It is report-only and still non-switching.

The inspection service is `AgenticPlannerDefaultSelectionGuardedActivationDesignService`, exposed by:

```bash
php artisan mos:inspect-agentic-planner-default-selection-guarded-activation-design \
  --workspace=WORKSPACE_ID \
  --objectives=OBJECTIVE_ID_A,OBJECTIVE_ID_B \
  --limit=10 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract
```

## Composition

Phase 4A composes or reads the existing chain:

- Phase 3T readiness
- Phase 3U scoped rollout plan
- Phase 3V scoped runtime guard
- Phase 3W planner-path diagnostics
- Phase 3X runtime switch contract
- Phase 3Y switch decision and matching audit snapshot
- Phase 3Z runtime switch consumption diagnostics

It returns an activation design report only. After Phase 4B it also reports the disabled activation-flag contract and safe empty-scope diagnostic availability. It does not switch planner output, create actions, migrate ownership, sync lifecycle, mutate payload/status/dedupe data, rewrite execution parents, write audits, dispatch jobs, change routes or approvals, enable global migration, or perform percentage rollout.

## Activation Candidate Gates

`activation_candidate` is `yes` only when all required gates pass:

- exact workspace/objective scope
- Phase 3T `ready_for_scoped_expansion`
- Phase 3U `eligible`
- Phase 3V guard allowed
- Phase 3W selected planner remains `legacy`
- Phase 3X `contract_ready`
- Phase 3Y `switch_ready`
- matching Phase 3Y audit snapshot exists
- Phase 3Z `switch_ready_consumed`
- Phase 4B activation flag contract is defined, disabled and non-consuming
- operator acknowledgements present
- duplicate risk zero
- order parity confirmed
- lifecycle/continuity blockers absent

Even when the report says `activation_candidate: yes`, Phase 4A/4B still keeps:

- `selected_planner_current: legacy`
- `selected_planner_after_phase_4a: legacy`
- `selected_planner_after_phase_4b: legacy`
- `runtime_behavior_changed: false`
- `activation_flag_enabled: false`
- `activation_flag_consumed_for_switching: false`

## Empty Candidate Observability

Phase 4B implements the future-safe design: when the planner receives an exact workspace/objective scope but no legacy candidate chunk exists, it records an in-process empty-scope diagnostic. The diagnostic is not written to audits or payloads and does not call the runtime switch service for the empty scope.

The Phase 4A report now includes `safe_empty_scope_diagnostic_available: true`.

## Activation Flag Contract

Phase 4B defines `mos.agentic_planner.default_selection.scoped_runtime_activation_enabled`, default `false`.

The flag is report-only in Phase 4B. It is not consumed by planner runtime to switch planner output. There is no global activation flag, no percentage rollout and no wildcard scope.

## Rollback Design

Rollback remains legacy-first:

- disabling a future activation flag returns to legacy selection
- no data migration required
- no historical action rewrite
- no dedupe/status mutation
- canonical metadata remains additive only
- legacy `AgenticMarketingOpportunity` ownership remains rollback authority

## Recommended Next Phase

Phase 4B should introduce no runtime switching by default. It should first add the future safe empty-scope diagnostic and a disabled activation flag contract, then prove that disabling that flag returns every inspected scope to legacy selection without data mutation.
