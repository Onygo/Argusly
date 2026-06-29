# MOS Agentic Planner Default Selection Pre-Activation Rehearsal

Phase 4G composes the Phase 4F CI/review evidence package into a dry-run pre-activation rehearsal for one exact workspace/objective scope.

Phase 4G is rehearsal only. It does not activate planner switching, consume activation flags, add percentage rollout, perform global/default migration, infer wildcard scope, replace legacy planner output, create or mutate `AgenticMarketingAction` rows, migrate ownership, sync lifecycle, mutate payload/status/dedupe/parent/approval/execution records, write runtime audit rows, dispatch jobs, rewrite historical records, change routes/approvals, or introduce runtime feature flags.

## Command

```bash
php artisan mos:rehearse-agentic-planner-default-selection-pre-activation \
  --workspace=<workspace-id> \
  --objectives=<objective-id-a,objective-id-b> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --require-real-scope
```

Optional exact-scope filters:

- `--site=<client-site-id>`
- `--detector=<detector-key>`

CI mode:

```bash
php artisan mos:rehearse-agentic-planner-default-selection-pre-activation \
  --workspace=<workspace-id> \
  --objectives=<objective-id> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --require-real-scope \
  --ci
```

Without `--ci`, `rehearsal_blocked` is review output and exits `0`. With `--ci`, `rehearsal_blocked` exits non-zero. `rehearsal_ready` exits `0`.

The command prints that Phase 4G is dry-run rehearsal only and does not change runtime behavior.

## Ready Rule

The rehearsal is `rehearsal_ready` only when:

- the scope is explicit and real: workspace id plus objective ids, with `--require-real-scope`;
- Phase 4F reports `package_ready`;
- the Phase 4F package checksum is present and preserved unchanged in the rehearsal output;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- the activation plan is marked dry-run only and performs no activation;
- rollback rehearsal proves legacy-first behavior;
- non-activation confirmations show no planner switching, activation flag consumption, wildcard inference, percentage rollout, migration, runtime mutation, audit write, route/approval change, historical rewrite, runtime feature flag creation, or job dispatch.

Otherwise the rehearsal is `rehearsal_blocked` and includes blocked reasons plus remediation guidance.

## Rehearsal Fields

Phase 4G includes:

- exact scope summary;
- Phase 4F package status;
- Phase 4F package checksum;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- rehearsal activation plan with `plan_type=dry_run_only`;
- rollback rehearsal result;
- legacy-first confirmation;
- blocked reasons;
- remediation guidance;
- non-activation confirmations;
- full composed Phase 4F package report.

## Rollback Rehearsal

Rollback rehearsal proves:

- legacy planner output remains authoritative;
- legacy Agentic action ownership remains authoritative;
- disabling any future activation would keep legacy output selected;
- metadata removal is not required for rollback;
- no ownership, lifecycle, payload, status, dedupe, parent, approval, execution, audit, route, historical rewrite, or job changes are needed.

Phase 4G does not perform rollback because it never performs activation. It only proves the rollback expectation before any separate future runtime activation phase can be considered.
