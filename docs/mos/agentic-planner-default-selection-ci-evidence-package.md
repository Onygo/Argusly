# MOS Agentic Planner Default Selection CI Evidence Package

Phase 4F packages Phase 4D operator readiness evidence and Phase 4E operator sign-off output into a deterministic CI/review artifact for one exact workspace/objective scope.

Phase 4F is packaging evidence only. It does not activate planner switching, consume activation flags, add percentage rollout, perform global/default migration, infer wildcard scope, replace legacy planner output, create or mutate `AgenticMarketingAction` rows, migrate ownership, sync lifecycle, mutate payload/status/dedupe/parent/approval/execution records, write runtime audit rows, dispatch jobs, rewrite historical records, or change routes/approvals.

## Command

```bash
php artisan mos:package-agentic-planner-default-selection-readiness-evidence \
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
php artisan mos:package-agentic-planner-default-selection-readiness-evidence \
  --workspace=<workspace-id> \
  --objectives=<objective-id> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --require-real-scope \
  --ci
```

Without `--ci`, a blocked package is review output and exits `0`. With `--ci`, `package_blocked` exits non-zero. `package_ready` exits `0`.

## Ready Rule

The package is `package_ready` only when:

- the scope is explicit and real: workspace id plus objective ids, with `--require-real-scope`;
- Phase 4D final evidence status is exactly `evidence_ready`;
- Phase 4E sign-off readiness is exactly `signoff_ready`;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- rollback remains `legacy_first`;
- non-activation confirmations show no planner switching, activation flag consumption, wildcard inference, percentage rollout, migration, runtime mutation, audit write, route/approval change, historical rewrite, or job dispatch.

Otherwise the package is `package_blocked` and includes blocked reasons plus remediation guidance.

## Package Fields

The Phase 4F package includes:

- workspace id;
- objective ids;
- optional site and detector filters;
- exact scope summary;
- Phase 4D final evidence status;
- Phase 4E sign-off readiness;
- blocked reasons;
- remediation guidance;
- rollback confirmation;
- non-activation confirmations;
- selected planner remains `legacy`;
- `runtime_behavior_changed=false`;
- `generated_at`;
- deterministic SHA-256 package checksum.

The checksum uses a canonical recursive key sort and excludes `generated_at` plus checksum fields, so repeated packages over the same evidence have the same review fingerprint while still carrying a fresh timestamp.

## Rollback

Rollback remains legacy-first:

- legacy planner output remains authoritative;
- legacy Agentic action ownership remains authoritative;
- disabling any future activation leaves legacy output selected;
- metadata is review evidence only;
- metadata is not required for rollback;
- metadata removal is not required for rollback.
