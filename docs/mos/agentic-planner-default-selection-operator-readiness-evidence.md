# MOS Agentic Planner Default Selection Operator Readiness Evidence

Phase 4D presents operator-facing readiness evidence for MOS Agentic Planner default selection. It composes the Phase 4C scoped telemetry validation output for one explicit workspace/objective scope and renders the result as evidence for review.

Phase 4D is evidence-only. It does not activate planner switching, does not consume the activation flag for switching, does not replace legacy planner output with canonical planner output, and does not create or mutate runtime records. Planner output remains legacy and `runtime_behavior_changed=false`.

## Command

```bash
php artisan mos:inspect-agentic-planner-default-selection-operator-readiness-evidence \
  --workspace=<workspace-id> \
  --objectives=<objective-id-a,objective-id-b> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --require-real-scope
```

Optional filters:

- `--site=<client-site-id>`
- `--detector=<detector-key>`

CI mode:

```bash
php artisan mos:inspect-agentic-planner-default-selection-operator-readiness-evidence \
  --workspace=<workspace-id> \
  --objectives=<objective-id> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --require-real-scope \
  --ci
```

Without `--ci`, blocked evidence is still a successful report command and exits `0`. With `--ci`, the command exits `0` only when the final evidence status is `evidence_ready`; it exits non-zero when the final evidence status is `evidence_blocked`.

## Evidence Contents

The report includes:

- workspace id and objective ids;
- real scope status;
- `telemetry_complete`;
- Phase 3T through Phase 4C chain summary;
- blocked reasons;
- audit snapshot status;
- activation flag state;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- non-activation checklist;
- rollback checklist;
- operator approval checklist;
- final evidence status: `evidence_ready` or `evidence_blocked`.

The operator approval checklist confirms:

- explicit workspace/objective reviewed;
- Phase 4C telemetry complete;
- audit snapshot reviewed;
- `metadata_only_ok` reviewed;
- duplicate risk zero;
- order parity confirmed;
- lifecycle/continuity blockers absent;
- activation flag still non-consuming;
- rollback path confirmed legacy-first;
- no action, ownership, payload, status, dedupe, lifecycle or job mutation observed.

## Non-Activation Contract

Phase 4D must not perform runtime activation. It must not:

- consume an activation flag for switching;
- perform global/default migration;
- perform percentage rollout;
- infer or wildcard scope;
- replace legacy planner output;
- create `AgenticMarketingAction` rows;
- change `AgenticMarketingAction.opportunity_id`;
- migrate ownership;
- sync lifecycle;
- mutate payload, status or dedupe fields;
- rewrite execution parents;
- write runtime audit rows;
- dispatch jobs;
- change routes or approvals.

Rollback remains legacy-first. Additive metadata remains review evidence only and is not required for rollback.
