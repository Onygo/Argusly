# Agentic Planner Default-Selection Preview

Phase 3Q adds a guarded default-selection preview for canonical-linked Agentic planner candidates. It prepares a safety contract for a possible Phase 3R scoped default-selection experiment, but it does not activate canonical selection.

The feature flag is:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_PREVIEW=false
```

Config key:

```php
features.mos_agentic_planner_canonical_default_selection_preview
```

The default is `false`.

## Boundary

Phase 3Q is read-only. `AgenticMarketingActionPlanner` still selects open `AgenticMarketingOpportunity` rows, orders them by legacy priority, and creates or reuses `AgenticMarketingAction` rows with the legacy Agentic opportunity id as parent.

Phase 3Q does not create extra `AgenticMarketingAction` rows, create canonical recommended actions, change `AgenticMarketingAction.opportunity_id`, change statuses, change dedupe hashes, sync lifecycle, rewrite payloads, persist shadow reports, migrate execution parents, dispatch jobs or write audit logs by default.

Phase 3R is the first scoped phase that may use this preview as an apply gate. It remains command-only and requires `features.mos_agentic_planner_canonical_default_selection_experiment`; Phase 3Q `preview_safe` is necessary but not sufficient without the Phase 3R flag, explicit objective, explicit limit and canonical-to-legacy resolution.

When both `features.mos_agentic_planner_canonical_shadow` and `features.mos_agentic_planner_canonical_default_selection_preview` are enabled, the default planner may compute in-process diagnostics at `mos.agentic_planner_canonical_default_selection_preview.last_diagnostics`. The diagnostics are not written to run results, run items, action payloads or audit logs, and errors are caught while legacy planning continues.

## Service

`AgenticCanonicalPlannerDefaultSelectionPreviewService` accepts an `AgenticMarketingObjective`, optional site and detector filters, and a limit.

The service composes:

- legacy planner candidate order;
- Phase 3P shadow result;
- Phase 3O audit status;
- Phase 3L readiness;
- Phase 3H signature equivalence;
- Phase 3I continuity status;
- Phase 3J lifecycle status;
- duplicate open legacy action risk.

The report includes objective and workspace ids, legacy candidate order, canonical proposed default order, exact order match, order differences, canonical-only candidates, legacy-only candidates, blocked candidates, excluded reasons, apply safety and a default-selection preview status.

## Statuses

- `keep_legacy`: no useful scoped preview exists.
- `preview_safe`: every required gate passed and the canonical proposed order would not change the selected legacy output.
- `preview_blocked`: a readiness or generic preview gate blocks eligibility.
- `insufficient_canonical_coverage`: canonical proposed candidates do not cover the selected legacy scope.
- `shadow_regressed`: Phase 3P no longer recommends `continue shadow`, or canonical order/scope would change legacy output.
- `audit_risk`: Phase 3O found risky rows.
- `duplicate_risk`: duplicate open legacy action risk exists.
- `continuity_risk`: Phase 3I continuity has blockers.
- `lifecycle_risk`: Phase 3J lifecycle is ambiguous or conflicting.
- `signature_risk`: Phase 3H signatures do not match.

## Safety Gates

`preview_safe` requires all of the following:

- Phase 3P recommendation is `continue shadow`;
- no Phase 3O risky rows exist;
- all canonical proposed candidates are Phase 3L `planner_candidate_ready_for_guarded_experiment`;
- Phase 3H signatures match;
- Phase 3I continuity has no canonical-parent-only blockers;
- Phase 3J lifecycle has no ambiguity or conflict;
- no duplicate open legacy action risk exists;
- canonical coverage is sufficient for the selected legacy scope;
- canonical proposed order exactly matches legacy candidate order.

`metadata_only_ok` is accepted only as traceability. It never approves canonical opportunities as action owners and does not permit default planner migration.

## Command

Use:

```bash
php artisan mos:preview-agentic-planner-default-selection --objective=<id> --limit=<n>
```

Options:

- `--workspace=`
- `--objective=` required
- `--site=`
- `--detector=`
- `--limit=` required

The command is read-only. It reports objective id, legacy candidate count, canonical proposed count, coverage percentage, exact order match count, order difference count, blocked candidate count, Phase 3O risky count, readiness regression count, duplicate risk count, continuity risk count, lifecycle risk count, signature risk count, preview-safe count, preview-blocked count, sample legacy order, sample canonical proposed order, sample differences, excluded samples and a recommendation.

Recommendations are:

- `keep legacy`;
- `eligible for Phase 3R scoped default experiment`;
- `blocked`.

## Phase 3R Eligibility

A scope is eligible for Phase 3R only when Phase 3Q returns `preview_safe`, the scope is intentionally narrow, operators have reviewed sample orders and excluded rows, and rollback remains flag-first.

## Rollback

Rollback is config-first:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_PREVIEW=false
```

Because Phase 3Q writes no preview snapshots and does not persist diagnostics into planner payloads, rollback requires no data migration. If the default-flow diagnostic was enabled, disabling either the Phase 3P shadow flag or the Phase 3Q preview flag stops the in-process preview.

## Remaining Blockers

Canonical planner selection cannot become default until a later phase proves scoped default selection under feature flag, rollback, monitoring, exact output expectations, action ownership rules, run item compatibility, audit behavior, execution continuity, lifecycle ambiguity handling and duplicate-action prevention.

## Phase 3S Follow-Up

After Phase 3R applies metadata, Phase 3S re-runs the preview boundary against persisted actions. `preview_safe` must still hold for the objective/scope and selected canonical-linked row; otherwise the action is reported as `preview_regressed`.

Phase 3S does not make Phase 3Q a writer. It audits `payload.default_selection_experiment` only, keeps the default planner legacy outside the explicit Phase 3R command and leaves canonical ids as metadata.

## Phase 3T Readiness

Phase 3T uses Phase 3Q `preview_safe` as a mandatory objective-level gate for controlled multi-objective readiness. Any preview status other than `preview_safe` reports `blocked_by_preview`.

Preview safety is still not ownership approval. Phase 3T reports coverage and order parity but does not create actions, write metadata, dispatch jobs or enable global canonical default selection.
