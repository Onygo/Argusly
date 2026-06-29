# Agentic Planner Shadow Rollout

Phase 3P adds default-flow shadow diagnostics for canonical-linked Agentic planner selection.

The feature flag is:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_SHADOW=false
```

Config key:

```php
features.mos_agentic_planner_canonical_shadow
```

The default is `false`.

## Boundary

Phase 3P compares the normal legacy planner candidates with the Phase 3M canonical experiment order, Phase 3L readiness and Phase 3O audit status. It does not change `AgenticMarketingActionPlanner` output, create canonical recommended actions, create extra `AgenticMarketingAction` rows, change `AgenticMarketingAction.opportunity_id`, change statuses, change dedupe hashes, sync lifecycle, rewrite payloads, dispatch jobs or migrate execution ownership.

Phase 3R still requires the Phase 3P recommendation to be `continue shadow`. Shadow diagnostics are not action ownership approval; they only allow the later scoped command to continue evaluating the Phase 3Q gate.

When the flag is enabled, `AgenticMarketingActionPlanner` computes the shadow report before action planning and stores only the last in-process diagnostic at `mos.agentic_planner_canonical_shadow.last_diagnostics`. The report is not written to run results, run items, action payloads or audit logs. Shadow errors are caught and exposed in that same in-process diagnostic, while legacy planning continues.

## Command

Use:

```bash
php artisan mos:shadow-agentic-planner-candidates
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--detector=`
- `--limit=`

The command is read-only. It reports inspected objectives, legacy candidate count, shadow canonical candidate count, exact order match count, priority order difference count, skipped legacy-only count, blocked canonical candidate count, readiness regression count, Phase 3O clean/risky counts, duplicate risk count, continuity risk count, lifecycle risk count, signature mismatch count, shadow-safe objective count, blocked objective count, sample legacy order, sample shadow order, sample differences and recommendation.

## Shadow-Safe

An objective is shadow-safe when canonical shadow candidates exist and the report has no blocked canonical candidates, Phase 3O risky rows, readiness regressions, duplicate risks, continuity risks, lifecycle risks or signature mismatches.

`metadata_only_ok` Phase 3O rows count as clean for shadow diagnostics because they prove metadata traceability only. They are not planner migration approval and they do not make canonical opportunities action owners.

Recommendations:

- `keep legacy`: no useful canonical shadow candidates are available.
- `continue shadow`: canonical candidates exist and no blockers are present.
- `blocked`: at least one readiness, audit, duplicate, continuity, lifecycle or signature blocker exists.

## Command-Only Versus Default-Flow Mode

The command is the preferred rollout tool because it is fully read-only and easy to scope by workspace, objective, site and detector.

Default-flow shadow mode is intentionally diagnostic only. It computes beside normal planning but does not persist the report, does not alter the returned run result, and does not affect the action candidates created or reused by the legacy planner.

## Blockers Before Guarded Default Selection

Before any guarded default planner selection can be considered, the selected scope must show:

- stable legacy and canonical order comparisons;
- zero Phase 3O risky rows;
- zero readiness regressions;
- zero duplicate action risk;
- zero signature mismatches;
- zero Phase 3I continuity risk;
- zero Phase 3J lifecycle ambiguity or conflict;
- explicit ownership rules for canonical recommended actions;
- proof that routes, approvals, run items, audit logs, execution pipelines, assets, feedback and rollback snapshots remain compatible.

## Rollback

Rollback is config-first:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_SHADOW=false
```

Because Phase 3P writes no shadow snapshots and does not persist diagnostics into planner payloads, rollback requires no data migration. Existing Phase 3N `payload.planner_experiment` metadata remains operationally ignored by default flows.

## Phase 3Q Default-Selection Preview

Phase 3Q builds on this shadow report through `AgenticCanonicalPlannerDefaultSelectionPreviewService` and `mos:preview-agentic-planner-default-selection`. It remains preview-only: default planner output stays legacy, and no reports, actions, run items, audit logs, payloads or lifecycle changes are written.

`preview_safe` requires Phase 3P to recommend `continue shadow`, no Phase 3O risky rows, Phase 3L-ready canonical proposed candidates, matching Phase 3H signatures, no Phase 3I continuity blockers, no Phase 3J lifecycle ambiguity/conflict, no duplicate open legacy action risk, sufficient canonical coverage and exact legacy/canonical order match. `metadata_only_ok` remains traceability only, never canonical action ownership approval.

## Phase 3S Default-Selection Audit

Phase 3S uses the Phase 3P recommendation as a persisted-action regression check. A Phase 3R action is `shadow_regressed` if Phase 3P no longer recommends `continue shadow` for the audited objective/scope.

This remains diagnostic. Shadow safety is not action ownership approval, and Phase 3S does not broaden planner selection or create canonical recommended actions.

## Phase 3T Readiness

Phase 3T requires Phase 3P to keep recommending `continue shadow` for every inspected objective. If the shadow recommendation changes, the rollout readiness status is `blocked_by_shadow`.

Shadow diagnostics remain read-only trace evidence. They do not approve canonical recommended actions, canonical action ownership or global planner migration.
