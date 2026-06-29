# MOS Agentic Planner Default Selection Activation Handoff

Phase 4H composes the Phase 4G dry-run pre-activation rehearsal into a final human/operator handoff packet for one exact workspace/objective scope.

Phase 4H is operator handoff only. It does not activate planner switching, consume activation flags, create runtime feature flags, add percentage rollout, perform global/default migration, infer wildcard scope, replace legacy planner output, create or mutate `AgenticMarketingAction` rows, migrate ownership, sync lifecycle, mutate payload/status/dedupe/parent/approval/execution records, write runtime audit rows, dispatch jobs, rewrite historical records, change routes/approvals, or perform rollback.

## Command

```bash
php artisan mos:handoff-agentic-planner-default-selection-activation \
  --workspace=<workspace-id> \
  --objectives=<objective-id-a,objective-id-b> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --ack-activation-handoff \
  --require-real-scope
```

Optional exact-scope filters:

- `--site=<client-site-id>`
- `--detector=<detector-key>`

CI mode:

```bash
php artisan mos:handoff-agentic-planner-default-selection-activation \
  --workspace=<workspace-id> \
  --objectives=<objective-id> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --ack-activation-handoff \
  --require-real-scope \
  --ci
```

Without `--ci`, `handoff_blocked` is review output and exits `0`. With `--ci`, `handoff_blocked` exits non-zero. `handoff_ready` exits `0`.

The command prints that Phase 4H is operator handoff only and does not change runtime behavior.

## Ready Rule

The handoff is `handoff_ready` only when:

- the scope is explicit and real: workspace id plus objective ids, with `--require-real-scope`;
- Phase 4G reports `rehearsal_ready`;
- `--ack-activation-handoff` is present;
- the Phase 4F package checksum from Phase 4G is present and preserved unchanged;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- dry-run activation plan summary shows no activation and no activation flag consumption;
- rollback rehearsal summary remains legacy-first;
- non-activation confirmations show no planner switching, runtime feature flag creation, activation flag consumption, wildcard inference, percentage rollout, migration, runtime mutation, audit write, route/approval change, historical rewrite, rollback execution, or job dispatch.

Otherwise the handoff is `handoff_blocked` and includes blocked reasons plus remediation guidance. Missing `--ack-activation-handoff` always blocks with `activation_handoff_acknowledgement_missing`.

## Handoff Fields

Phase 4H includes:

- exact scope summary;
- Phase 4G rehearsal status;
- Phase 4F package checksum;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- dry-run activation plan summary;
- rollback rehearsal summary;
- legacy-first confirmation;
- operator handoff acknowledgement status;
- blocked reasons;
- remediation guidance;
- operator handoff checklist;
- non-activation confirmations;
- full composed Phase 4G rehearsal report.

## Operator Checklist

Operators should review the packet and confirm:

- exact real scope is the intended workspace/objective allowlist;
- Phase 4G is `rehearsal_ready`;
- Phase 4F package checksum matches the reviewed package;
- selected planner remains `legacy`;
- no runtime behavior changed;
- rollback rehearsal is legacy-first;
- all non-activation confirmations remain clear;
- acknowledgement was recorded as review output only.

Phase 4H stops at handoff. Any future runtime activation must be implemented as a separate, explicit runtime phase with its own controls.
