# Agentic Planner Experiment

Phase 3M adds a guarded planner experiment contract for comparison only. It can compare canonical-linked planner candidates against the current legacy `AgenticMarketingActionPlanner` candidate order, but it does not select canonical candidates for production planning.

## Boundary

Phase 3M is default-off and read-only. It does not enable canonical planner selection by default, create canonical recommended actions, create `AgenticMarketingAction` rows from canonical `Opportunity`, change execution parent ids, change routes, sync lifecycle state, rewrite historical payloads or backfill records.

Phase 3R can consume the canonical-linked order only after Phase 3Q returns `preview_safe` for an explicit objective and limit. The Phase 3M comparison contract remains read-only by itself and does not authorize canonical action ownership.

The default production planner remains legacy because execution identity, action dedupe, lifecycle ownership and route continuity still rely on `AgenticMarketingOpportunity`. The canonical path is limited to comparison reports and in-memory dry-run DTOs.

## Feature Flag

Config key:

```php
features.mos_agentic_planner_canonical_experiment
```

Environment variable:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false
```

The default is `false`. The comparison command remains read-only even when manually invoked; the flag is an explicit guard for any future scoped experiment path.

## Comparison Contract

`AgenticCanonicalPlannerExperimentService` accepts an `AgenticMarketingObjective` and reports:

- current legacy planner candidate order from open legacy Agentic rows;
- canonical-linked candidate order for Phase 3L ready rows only;
- excluded rows with readiness statuses and blocked reasons;
- priority order differences;
- action signature equivalence;
- duplicate action risk;
- expected no-op rows;
- readiness status per row;
- recommendation: `keep legacy`, `safe for scoped dry-run` or `blocked`.

The service does not create actions. The optional `AgenticCanonicalPlannerDryRunAdapter` returns DTOs only and does not call `AgenticMarketingAction::createOrReuseOpen`, write run items, write audit logs, dispatch jobs, mutate action statuses or alter planner selection.

## Command

Use:

```bash
php artisan mos:compare-agentic-planner-candidates
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--status=`
- `--detector=`
- `--limit=`

The command reports inspected objectives, legacy candidate count, canonical-ready candidate count, blocked candidate count, priority order differences, duplicate risk count, signature blocker count, continuity blocker count, lifecycle blocker count, sample legacy order, sample canonical experiment order, excluded row samples and recommendation.

## Required Readiness Gates

A row can enter the canonical experiment order only when Phase 3L reports `planner_candidate_ready_for_guarded_experiment`.

That requires exactly one safe canonical bridge, safe Phase 3H action signatures, no Phase 3I canonical-parent-only continuity blockers, no Phase 3J lifecycle ambiguity or conflict, and no duplicate open legacy action risk.

Rows that are `legacy_only`, `canonical_context_available`, `metadata_ready_only` or `planner_candidate_blocked` remain excluded and are reported with blocked reasons.

## Future Apply Blockers

A future apply phase remains blocked by any unresolved duplicate action risk, signature mismatch, continuity blocker, lifecycle ambiguity, canonical recommended-action ownership ambiguity, route dependency on legacy ids or inability to prove that default legacy planner output remains unchanged outside a scoped dry run.

Phase 3M also blocks historical rewrites: previous payloads, action parents, run items, audit logs and lifecycle states must remain untouched.

## Rollback Strategy

Rollback is disabling `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT`. Because Phase 3M writes no production data, there is no data rollback. Existing legacy planner behaviour remains the operational fallback.

## Phase 3N Prerequisites

Phase 3N should require scoped dry-run evidence, stable candidate ordering under real objective filters, zero duplicate open-action risk, signature equivalence for all action types, continuity coverage for execution reads, lifecycle ownership rules for canonical rows and explicit proof that no canonical opportunity creates Agentic actions unless a later apply phase defines and guards that write path.

## Phase 3N Apply Experiment

Phase 3N introduces `php artisan mos:apply-agentic-planner-canonical-experiment` behind `features.mos_agentic_planner_canonical_apply_experiment` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=false`.

The command is dry-run by default and requires `--objective=` plus `--limit=`. Apply also requires `--apply` and the feature flag. Eligible rows must be Phase 3L `planner_candidate_ready_for_guarded_experiment` rows with Phase 3M signature equivalence, no duplicate open legacy action risk, no Phase 3I continuity blockers and no Phase 3J lifecycle ambiguity or conflict.

Apply resolves the canonical experiment selection back to the legacy `AgenticMarketingOpportunity` and calls the existing `AgenticMarketingActionPlanner::planForOpportunity`. Created or reused actions remain legacy-owned; canonical opportunity ids are stored only in `payload.planner_experiment`.

Rollback is disabling `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT`. Existing experiment metadata can be ignored because execution still binds legacy Agentic opportunity ids.

## Phase 3O Apply Experiment Audit

Phase 3O audits Phase 3N-created or reused actions with `payload.planner_experiment.version=agentic-planner-canonical-apply:v1`. The audit reports clean, metadata-only, stale-link, missing-parent, missing-canonical, bridge-mismatch, signature, readiness, continuity, lifecycle and duplicate-risk statuses.

This phase still does not migrate the default planner. Any Phase 3P shadow rollout remains blocked until Phase 3O reports no ownership, bridge, signature, duplicate, continuity or lifecycle risk for the selected scope.

## Phase 3P Shadow Rollout

Phase 3P wraps the Phase 3M comparison in `AgenticCanonicalPlannerShadowService` and adds `mos:shadow-agentic-planner-candidates`. The shadow report keeps `legacy_order` and `shadow_canonical_order` separate, adds Phase 3O audit risk, and uses recommendations `keep legacy`, `continue shadow` or `blocked`.

The new default-flow hook is diagnostic only behind `features.mos_agentic_planner_canonical_shadow`. It computes the shadow report before normal action planning and does not persist the report into run results, run items, action payloads or audit logs.

## Phase 3Q Default-Selection Preview

Phase 3Q adds `AgenticCanonicalPlannerDefaultSelectionPreviewService` and `mos:preview-agentic-planner-default-selection` behind `features.mos_agentic_planner_canonical_default_selection_preview` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_PREVIEW=false`.

It is stricter than Phase 3M/3P because it evaluates possible default selection. `preview_safe` requires Phase 3P `continue shadow`, clean Phase 3O audit status, Phase 3L-ready canonical proposed rows, Phase 3H signature equivalence, no Phase 3I continuity blockers, no Phase 3J lifecycle ambiguity/conflict, no duplicate action risk, full canonical coverage of the selected legacy scope and exact legacy/canonical order match.

The default planner remains legacy. Phase 3Q writes no reports, run items, actions, audit logs, payload metadata, lifecycle sync or canonical recommended actions.

## Phase 3S Default-Selection Experiment Audit

Phase 3S audits Phase 3R-created or reused actions with `payload.default_selection_experiment.version=agentic-planner-canonical-default-selection:v1`. It checks whether Phase 3Q/3P/3O/3L/3H/3I/3J signals still support the same narrow scope and whether each action remains legacy-owned.

Phase 3S is required before any Phase 3T broader scoped rollout. Any preview, shadow, Phase 3O, readiness, signature, continuity, lifecycle, duplicate or ownership risk keeps canonical default selection blocked. `metadata_only_ok` remains traceability only.

## Phase 3T Readiness

Phase 3T adds broader scoped readiness diagnostics without changing planner output. It reports whether the inspected scope should keep legacy, continue a single-objective experiment, become eligible for limited multi-objective Phase 3U or stay blocked.

No Phase 3T status approves canonical recommended actions, canonical execution parents, lifecycle sync or historical payload rewrites.
