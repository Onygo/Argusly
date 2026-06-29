# Agentic Planner Default-Selection Experiment Audit

Phase 3S audits Phase 3R-created or reused `AgenticMarketingAction` rows that carry:

```text
payload.default_selection_experiment.version = agentic-planner-canonical-default-selection:v1
```

It is diagnostics only. Phase 3S does not change default planner behavior, create canonical recommended actions, move action ownership, sync lifecycle, rewrite payloads, mutate execution parents, change statuses, change dedupe hashes, remove metadata, delete rows or change routes.

## Commands

```bash
php artisan mos:audit-agentic-planner-default-selection-experiment \
  --workspace=<workspace-id> \
  --objective=<objective-id> \
  --site=<site-id> \
  --detector=<detector-key> \
  --status=<audit-status> \
  --action-status=<action-status> \
  --limit=100
```

```bash
php artisan mos:plan-agentic-planner-default-selection-experiment-rollback \
  --workspace=<workspace-id> \
  --objective=<objective-id> \
  --site=<site-id> \
  --detector=<detector-key> \
  --status=<audit-status> \
  --action-status=<action-status> \
  --limit=100
```

Both commands are read-only. The rollback command reports action ids, legacy opportunity ids, canonical opportunity ids, the metadata path `payload.default_selection_experiment`, whether metadata-only rollback would be safe, unsafe rollback count and a recommendation. It does not remove metadata.

## Audit Fields

For each action, Phase 3S reports the action id, action status, legacy `opportunity_id`, payload legacy id, canonical opportunity id, objective id, workspace id, action type, `applied_at`, legacy/canonical existence, canonical bridge consistency, legacy ownership, Phase 3Q preview safety, Phase 3P shadow recommendation, Phase 3O audit status, Phase 3L readiness, Phase 3H signature, Phase 3I continuity, Phase 3J lifecycle ambiguity, duplicate open action risk, audit status, blocked reasons and warning reasons.

## Status Definitions

- `clean`: the action remains legacy-owned, linked canonical context still resolves, Phase 3Q is still `preview_safe`, Phase 3P still says `continue shadow`, Phase 3O is clean, Phase 3L/3H/3I/3J still pass and no duplicate open action risk exists.
- `metadata_only_ok`: the row is operationally safe, but Phase 3O still reports metadata-only traceability. This is not canonical action ownership approval.
- `missing_legacy_parent`: the action no longer resolves to a legacy `AgenticMarketingOpportunity`.
- `missing_canonical_context`: the canonical opportunity in metadata is missing or soft-deleted.
- `bridge_mismatch`: the canonical bridge no longer points to the same legacy opportunity recorded on the action and metadata.
- `preview_regressed`: Phase 3Q no longer returns `preview_safe` for the objective/scope and selected row.
- `shadow_regressed`: Phase 3P no longer recommends `continue shadow`.
- `phase_3o_audit_risk`: Phase 3O no longer reports only `clean` or `metadata_only_ok`.
- `readiness_regressed`: Phase 3L no longer passes for the audited action.
- `signature_mismatch`: Phase 3H signature no longer matches.
- `continuity_risk`: Phase 3I continuity now has blockers.
- `lifecycle_risk`: Phase 3J lifecycle/action ownership is now ambiguous or conflicting.
- `duplicate_risk`: duplicate open legacy action risk now exists.
- `ownership_risk`: `action.opportunity_id` no longer matches the legacy Agentic opportunity id stored in metadata.

## Rollback Strategy

Rollback remains flag-first:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT=false
```

Existing `payload.default_selection_experiment` metadata can be ignored because Phase 3R actions remain legacy-owned. Phase 3S intentionally does not add a metadata removal writer. A writer would need to be separate, default dry-run, feature-flagged, require `--apply`, require `--objective`, require `--limit`, remove only `payload.default_selection_experiment`, preserve `payload.planner_experiment`, and avoid touching ownership, status, dedupe hashes, run items, audit logs, execution pipelines, approvals or routes.

## Broader Rollout Blockers

Before any Phase 3T broader scoped rollout, Phase 3S must show no missing legacy parents, no missing canonical context, no bridge mismatch, no preview or shadow regression, no Phase 3O risky rows, no Phase 3L readiness regression, no Phase 3H signature mismatch, no Phase 3I continuity risk, no Phase 3J lifecycle ambiguity/conflict, no duplicate risk and no ownership risk. `metadata_only_ok` rows still require operator review because they prove traceability only.

## Phase 3T Readiness

Phase 3T consumes Phase 3S as a hard broader-scope gate. Only `clean` and operator-accepted `metadata_only_ok` Phase 3R rows can be considered; every other status reports `blocked_by_phase_3s`.

Phase 3T remains read-only. It does not remove `payload.default_selection_experiment`, approve canonical action ownership, create canonical recommended actions, migrate execution parents or enable global default planner migration.
