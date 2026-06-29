# MOS Agentic Planner Default Selection Scoped Telemetry Validation

Phase 4C validates the MOS Agentic Planner default-selection readiness and activation telemetry chain against one explicit real workspace/objective scope.

It does not activate planner switching. Planner output remains legacy, `runtime_behavior_changed` remains `false`, and the Phase 4B activation flag remains report-only and non-consuming.

## Command

```bash
php artisan mos:inspect-agentic-planner-default-selection-scoped-telemetry-validation \
  --workspace=WORKSPACE_ID \
  --objectives=OBJECTIVE_ID \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --require-real-scope
```

Optional filters:

- `--site=`
- `--detector=`

## Real Scope Requirement

Phase 4C does not infer scope. A real scope is detected only when:

- the workspace id is explicit and exists in the database
- every requested objective id exists in that workspace
- the requested objective ids exactly match the validation scope
- no wildcard, global, percentage, or inferred scope is used

When `--require-real-scope` is passed, telemetry is blocked unless the workspace and objective records exist.

## Validation Chain

The validation service composes the existing telemetry reports:

- Phase 3T rollout readiness
- Phase 3U scoped rollout plan
- Phase 3V scoped runtime guard
- Phase 3W planner-path diagnostics
- Phase 3X runtime switch contract
- Phase 3Y switch decision and matching audit snapshot
- Phase 3Z runtime switch consumption diagnostics
- Phase 4A guarded activation design
- Phase 4B safe empty-scope diagnostics and disabled activation flag contract

The report includes the workspace id, objective ids, real-scope status, objective-record status, legacy candidate count, empty-scope diagnostic status, Phase 3T through 4B status summary, activation flag state, audit snapshot presence, telemetry completeness, blocked reasons, and a pre-activation acceptance checklist.

## Non-Activation Contract

Phase 4C is validation-only. It must not:

- replace legacy planner output with canonical planner output
- create `AgenticMarketingAction` rows
- change `AgenticMarketingAction.opportunity_id`
- migrate ownership
- sync lifecycle state
- mutate payload, status, or dedupe fields
- rewrite execution parents
- write planner runtime audits
- dispatch jobs
- change routes or approvals
- enable global/default migration
- enable percentage rollout
- accept wildcard or inferred scope

Rollback remains legacy-first.
