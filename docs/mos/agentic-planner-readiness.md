# Agentic Planner Readiness

Phase 3L adds guarded planner-readiness diagnostics only. It answers whether `AgenticMarketingActionPlanner` could eventually use canonical-linked Agentic opportunity context for selection, without changing how the planner selects or creates work today.

## Boundary

Phase 3L is read-only. It does not change `AgenticMarketingActionPlanner`, create canonical recommended actions, create `AgenticMarketingAction` rows from canonical `Opportunity`, alter action dedupe, move execution parents, change routes, sync lifecycle state, rewrite payloads or backfill historical rows.

Phase 3R requires every canonical proposed candidate to be `planner_candidate_ready_for_guarded_experiment`. `metadata_ready_only`, `canonical_context_available`, `legacy_only` and blocked rows remain apply blockers.

The current planner still reads open `AgenticMarketingOpportunity` rows ordered by legacy `priority_score` and creates/reuses `AgenticMarketingAction` rows with the legacy Agentic opportunity id as parent.

## Inspection Service

`AgenticPlannerReadinessInspectionService` inspects one `AgenticMarketingOpportunity` and reports:

- legacy and linked canonical opportunity ids;
- objective, workspace, site, detector and Agentic type;
- legacy and canonical priority score with provenance;
- current planner eligibility and legacy rank inputs;
- canonical candidate selection fields;
- Phase 3H signature status;
- Phase 3I continuity status;
- Phase 3J lifecycle/action ownership status;
- Phase 3K future-row metadata availability;
- open legacy action count and duplicate action risk;
- readiness status and blocked reasons.

Readiness statuses:

| Status | Meaning |
| --- | --- |
| `legacy_only` | No linked canonical context exists for the inspected Agentic row. |
| `canonical_context_available` | Exactly one safe bridge exists, but trace metadata is not fully available and planner migration remains blocked. |
| `metadata_ready_only` | Phase 3K can provide future-row trace metadata, but planner readiness blockers remain. Metadata is not a planner migration signal. |
| `planner_candidate_blocked` | A hard blocker exists, such as duplicate bridges, signature blockers, continuity blockers, lifecycle ambiguity/conflict or duplicate action risk. |
| `planner_candidate_ready_for_guarded_experiment` | Exactly one safe bridge exists and Phase 3H, 3I, 3J and duplicate-action checks are clear for the inspected scope. |

## Command

Use:

```bash
php artisan mos:inspect-agentic-planner-readiness
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

The command reports inspected count, readiness status counts, duplicate action risk count, lifecycle ambiguity count, continuity blocker count, signature blocker count, priority-difference samples, blocked reason samples and readiness samples.

## Phase Interactions

Phase 3H signatures must be safe before a row can be ready. Missing workspace, detector, Agentic type, action type or duplicate bridge context blocks readiness.

Phase 3I continuity must report no canonical-parent-only lookup blockers for the inspected scope. Existing actions, action runs, pipelines or assets that would be missed by canonical-parent-only lookup block planner readiness.

Phase 3J lifecycle must not be ambiguous or conflicting. Candidate-only lifecycle states remain diagnostics until a later phase defines single-valued lifecycle ownership.

Phase 3K metadata improves traceability for future rows only. A row can be `metadata_ready_only` while planner migration remains blocked.

## Future Guarded Experiment Prerequisites

A future guarded planner experiment needs:

- exactly one safe canonical bridge per selected Agentic row;
- safe Phase 3H canonical-equivalent action signatures;
- no Phase 3I canonical-parent-only lookup blockers;
- no Phase 3J lifecycle ambiguity or conflict;
- no open legacy action that would be duplicated;
- explicit feature flag, rollback and observability;
- proof that legacy planner output is unchanged outside the experiment scope.

## Rollback Strategy

Phase 3L has no data rollback because it writes no production data. If a future experiment is added, rollback should disable the experiment flag and return selection to legacy `AgenticMarketingOpportunity` priority ordering. Additive Phase 3K metadata can remain because execution parentage stays legacy-owned.

## Phase 3M Planner Experiment

Phase 3M adds `AgenticCanonicalPlannerExperimentService`, `AgenticCanonicalPlannerDryRunAdapter` and `php artisan mos:compare-agentic-planner-candidates`. The experiment consumes Phase 3L readiness and compares legacy planner order with canonical-linked ready-row order only.

The feature flag is `features.mos_agentic_planner_canonical_experiment`, backed by `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false`. Default production planning remains legacy. Rows must still pass every Phase 3L gate: one safe bridge, safe Phase 3H signatures, no Phase 3I continuity blockers, no Phase 3J lifecycle ambiguity or conflict and no duplicate open legacy action risk.

Phase 3M does not create actions or canonical recommended actions. Future Phase 3N work needs scoped dry-run proof, stable ordering, zero blockers and a rollback plan before any apply phase can be considered.

## Phase 3N Apply Experiment

Phase 3N consumes Phase 3L readiness as an apply gate. Only `planner_candidate_ready_for_guarded_experiment` rows can be selected by `mos:apply-agentic-planner-canonical-experiment`, and only when Phase 3M signature equivalence is present.

Rows that are legacy-only or lack canonical context are skipped. Rows with duplicate open legacy action risk, Phase 3H signature blockers, Phase 3I continuity blockers or Phase 3J lifecycle ambiguity/conflict are blocked before the legacy planner is called.

The apply command remains scoped by required `--objective=` and `--limit=` filters. Apply requires `--apply` plus `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=true`; dry-run writes nothing.

## Phase 3O Readiness Recheck

Phase 3O re-runs Phase 3L readiness around already-applied experiment metadata. The audit discounts the audited action's own expected legacy action and action-run rows, then flags readiness regression only when other gates no longer pass.

`metadata_only_ok` is an explicit Phase 3O status for rows where trace metadata remains safe but Phase 3J/3L still do not permit default planner migration or canonical action ownership.

## Phase 3P Shadow Readiness

Phase 3P still requires Phase 3L status `planner_candidate_ready_for_guarded_experiment` before a row can appear in the shadow canonical order. Rows with `legacy_only`, `canonical_context_available`, `metadata_ready_only` or `planner_candidate_blocked` remain excluded and are counted as skipped or blocked diagnostics.

Readiness regressions found by Phase 3O block shadow safety. The shadow service reports the blocker but does not repair bridges, sync lifecycle, create actions or rewrite historical payloads.

## Phase 3Q Default-Selection Preview

Phase 3Q treats Phase 3L readiness as a hard default-selection preview gate. Every canonical proposed candidate must still be `planner_candidate_ready_for_guarded_experiment`; `legacy_only`, `canonical_context_available`, `metadata_ready_only` and `planner_candidate_blocked` rows block or exclude the preview.

`metadata_ready_only` and Phase 3O `metadata_only_ok` are traceability states only. They do not approve canonical action ownership and do not allow the default planner to stop using legacy Agentic opportunity ids.

## Phase 3S Readiness Regression Check

Phase 3S reuses Phase 3L readiness as a persisted-action regression check for Phase 3R metadata. A default-selection experiment action is `readiness_regressed` when the linked legacy/canonical context no longer passes for the audited action.

This still does not make canonical rows action owners. Readiness remains a gate for diagnostics and scoped experiments only; the default planner remains legacy outside the explicit Phase 3R command.

## Phase 3T Readiness

Phase 3T requires every canonical candidate in scope to be Phase 3L `planner_candidate_ready_for_guarded_experiment`. `metadata_ready_only` is not enough for rollout readiness and is reported as `blocked_by_readiness` when it appears in the proposed canonical order.

Readiness remains a diagnostic gate. It does not create canonical actions, sync lifecycle state or migrate `AgenticMarketingAction.opportunity_id`.
