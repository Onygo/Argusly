# Agentic Planner Apply Experiment Audit

Phase 3O adds read-only diagnostics for Phase 3N `payload.planner_experiment` metadata.

It inspects `AgenticMarketingAction` rows where `payload.planner_experiment.version` is `agentic-planner-canonical-apply:v1`. It does not change default planner behaviour, create canonical recommended actions, change `AgenticMarketingAction.opportunity_id`, change action statuses, sync lifecycle, rewrite execution rows, delete actions or remove metadata.

## Commands

```bash
php artisan mos:audit-agentic-planner-apply-experiment
php artisan mos:plan-agentic-planner-apply-experiment-rollback
```

Both commands accept `--workspace=`, `--objective=`, `--site=`, `--detector=`, `--status=`, `--action-status=` and `--limit=`.

`--status=` filters Phase 3O audit status. `--action-status=` filters the current `AgenticMarketingAction.status`.

## Audit Statuses

- `clean`: metadata, bridge, signature, readiness, continuity, lifecycle and duplicate checks remain safe.
- `metadata_only_ok`: action ownership is safe, but Phase 3J/3L still only supports metadata traceability. This is acceptable for Phase 3N rows and is not planner migration approval.
- `stale_canonical_link`: the metadata canonical row is soft-deleted or no longer has a bridge.
- `missing_legacy_parent`: the action no longer resolves to the legacy Agentic opportunity recorded in metadata.
- `missing_canonical_context`: the metadata canonical id is missing or no longer resolves.
- `bridge_mismatch`: the canonical bridge points to a different legacy Agentic opportunity.
- `signature_mismatch`: the stored Phase 3M/3H source signature no longer matches current canonical context.
- `readiness_regressed`: Phase 3L readiness no longer passes after discounting the audited action's own expected legacy rows.
- `duplicate_risk`: another open legacy action now creates duplicate risk.
- `lifecycle_risk`: Phase 3J lifecycle is ambiguous or conflicting beyond metadata-only tolerance.
- `continuity_risk`: Phase 3I continuity now has execution dependencies beyond the audited action's own expected rows.

## Rollback Boundary

Phase 3O intentionally does not add a metadata removal writer. The safe rollback remains operational: keep `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=false` and ignore `payload.planner_experiment` at runtime.

The rollback plan command reports which action ids could tolerate metadata-only removal, but it does not remove `payload.planner_experiment`. A future writer would need a separate phase, a feature flag, `--apply`, required `--objective`, required `--limit`, metadata-only removal and proof that it does not alter status, `opportunity_id`, dedupe hashes, run items, audit logs, execution pipelines or approvals.

## Phase 3R Default-Selection Experiment Audit Boundary

Phase 3R writes `payload.default_selection_experiment`, not `payload.planner_experiment`. Phase 3O remains the audit source for the earlier Phase 3N metadata, and Phase 3R treats only `clean` and `metadata_only_ok` Phase 3O rows as acceptable. Any Phase 3O risky status blocks Phase 3R apply.

## Phase 3P Blockers

Before any default planner shadow rollout, all Phase 3N rows in scope must have no missing legacy parents, missing canonical context, bridge mismatches, signature mismatches, continuity risk, duplicate risk or lifecycle conflict. `metadata_only_ok` rows still prove traceability only; they do not make canonical opportunities action owners.

## Phase 3P Shadow Diagnostics

Phase 3P consumes this audit through `AgenticCanonicalPlannerShadowService` and `mos:shadow-agentic-planner-candidates`. Audit statuses `clean` and `metadata_only_ok` count as shadow-clean; every other Phase 3O status counts as risky and blocks `continue shadow`.

Shadow mode does not write audit logs or rollback snapshots. It only reports whether already-applied Phase 3N metadata remains safe enough to keep observing canonical-linked selection beside the legacy planner.

## Phase 3Q Default-Selection Preview

Phase 3Q consumes the same audit as a stricter default-selection preview gate. Any Phase 3O status other than `clean` or `metadata_only_ok` becomes `audit_risk` and blocks `preview_safe`.

`metadata_only_ok` is still accepted only as traceability for legacy-owned rows. It does not approve canonical action ownership, does not allow default planner migration and does not change rollback: keep canonical apply/default flags off and let the legacy planner remain authoritative.

## Phase 3S Relationship

Phase 3S does not replace Phase 3O. It audits the later Phase 3R metadata path, `payload.default_selection_experiment`, and treats Phase 3O as one regression input. Phase 3S accepts only Phase 3O `clean` or `metadata_only_ok`; any other Phase 3O status becomes `phase_3o_audit_risk`.

The Phase 3S rollback planner is also read-only. It reports the metadata path that would be involved, but no writer removes `payload.default_selection_experiment` in this phase.

## Phase 3T Readiness

Phase 3T consumes Phase 3O for older Phase 3N `payload.planner_experiment` rows. Only `clean` and `metadata_only_ok` are acceptable; any other Phase 3O status reports `blocked_by_phase_3o`.

`metadata_only_ok` remains traceability only and is reported separately. It does not approve canonical action ownership or default planner migration.
