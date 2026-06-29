# Agentic Planner Apply Experiment

Phase 3N adds a scoped, guarded apply experiment for canonical-linked Agentic planner candidates.

It allows Phase 3L-ready canonical context to influence candidate selection only inside an explicit command. It still creates or reuses normal legacy-owned `AgenticMarketingAction` rows against legacy `AgenticMarketingOpportunity` ids through the existing `AgenticMarketingActionPlanner` path.

## Boundary

Phase 3N does not create canonical recommended actions, create actions with canonical `Opportunity` ids, change execution parent ids, change routes, sync lifecycle state, rewrite historical payloads, backfill records, alter execution pipelines, bypass action dedupe, bypass approval gates or remove the legacy planner fallback.

Phase 3R follows the same ownership boundary for default selection: selected canonical rows must resolve back to legacy `AgenticMarketingOpportunity` rows, and the existing planner remains the only writer of `AgenticMarketingAction` rows.

Default app flows continue to use:

- open legacy `AgenticMarketingOpportunity` queries;
- legacy `priority_score` ordering;
- legacy `opportunity_id` action parents;
- `AgenticMarketingAction::createOrReuseOpen` dedupe;
- existing approval and execution routes.

## Feature Flag

Config key:

```php
features.mos_agentic_planner_canonical_apply_experiment
```

Environment variable:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=false
```

The default is `false`. Dry-run works without the flag. Apply requires the flag and explicit `--apply`.

## Command

Use:

```bash
php artisan mos:apply-agentic-planner-canonical-experiment --objective=<id> --limit=<n>
```

Options:

- `--objective=` is required.
- `--limit=` is required and must be positive.
- `--workspace=` optionally narrows the objective.
- `--site=` optionally narrows the objective.
- `--detector=` limits inspected Agentic rows by detector key.
- `--apply` writes through the existing legacy planner path.

Without `--apply`, the command is dry-run and writes nothing.

## Apply Gates

Apply requires:

- `features.mos_agentic_planner_canonical_apply_experiment=true`;
- explicit `--apply`;
- required objective and limit filters;
- Phase 3L status `planner_candidate_ready_for_guarded_experiment`;
- no duplicate open legacy action risk;
- matching Phase 3M signature equivalence;
- no Phase 3I continuity blockers;
- no Phase 3J lifecycle ambiguity or conflict.

Rows without canonical context are skipped. Rows with duplicate risk, signature blockers, continuity blockers or lifecycle blockers are reported as blocked.

## Apply Behaviour

For each eligible candidate, Phase 3N resolves the selected row back to its legacy `AgenticMarketingOpportunity` and calls `AgenticMarketingActionPlanner::planForOpportunity`.

The planner still creates or reuses `AgenticMarketingAction` rows with:

- legacy `opportunity_id`;
- existing `action_type`;
- existing status semantics;
- existing dedupe hashes;
- existing approval policy output;
- existing execution routes.

After the legacy planner returns action ids, Phase 3N stores additive metadata in `payload.planner_experiment`. The metadata write is intentionally not used for selection, dedupe, approval or execution parent lookup.

## Payload Metadata

Metadata shape:

```yaml
planner_experiment:
  version: agentic-planner-canonical-apply:v1
  canonical_opportunity_id: <canonical Opportunity id>
  legacy_agentic_marketing_opportunity_id: <legacy AgenticMarketingOpportunity id>
  objective_id: <AgenticMarketingObjective id>
  workspace_id: <workspace id>
  selection_source: canonical_experiment
  phase_3m_signature: <canonical-equivalent signature>
  phase_3l_readiness_status: planner_candidate_ready_for_guarded_experiment
  applied_at: <ISO-8601 timestamp>
  applied_by: command
```

Canonical ids are trace metadata only. They are not action parents.

## Reporting

The command reports inspected objectives, legacy candidate count, canonical experiment candidate count, eligible apply candidate count, skipped candidate count, created action count, reused action count, blocked count, blocker samples, created/reused/planned action ids, legacy opportunity ids, linked canonical opportunity ids, source signatures and rollback notes.

The output explicitly states that this is an experiment and that execution remains legacy-owned.

## Rollback

Rollback is config-first:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=false
```

Disabling the flag stops further writes. Existing `payload.planner_experiment` metadata can be ignored because runtime execution continues to use legacy `AgenticMarketingOpportunity` ids. No lifecycle sync, parent migration, historical rewrite or canonical recommended-action cleanup is required.

## Blockers Before Default Migration

Before any default planner migration, the project still needs proof that canonical selection is safe across normal app flows, lifecycle ownership is single-valued, execution routes can resolve without legacy-only assumptions, duplicate prevention remains stable with historical actions, approval gates and rollback snapshots remain compatible, and canonical recommended actions have a separate ownership contract.

## Phase 3O Audit And Rollback Planning

Phase 3O adds `mos:audit-agentic-planner-apply-experiment` and `mos:plan-agentic-planner-apply-experiment-rollback`. Both commands inspect existing `payload.planner_experiment` metadata only and remain read-only.

The audit verifies that Phase 3N actions still point at legacy `AgenticMarketingOpportunity` ids, that canonical bridges still point back to the same legacy row, that Phase 3H signatures still match, and that Phase 3L/3I/3J risks have not regressed. `metadata_only_ok` means trace metadata is tolerable, not that canonical action ownership is approved.

No rollback writer is implemented in Phase 3O. Rollback remains disabling the apply flag and ignoring `payload.planner_experiment`; metadata removal needs a separate guarded writer phase.

## Phase 3P Shadow Diagnostics

Phase 3P does not expand Phase 3N apply. It only compares canonical-linked selection beside normal legacy planner flows using `features.mos_agentic_planner_canonical_shadow` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_SHADOW=false`.

Default planner output remains legacy-owned. Phase 3P does not create new actions, does not add or remove `payload.planner_experiment`, does not change statuses or dedupe hashes, and does not promote canonical opportunities to execution parents. Rollback remains disabling the shadow flag; no data rollback is required.

## Phase 3Q Default-Selection Preview

Phase 3Q does not expand apply mode. It only previews whether a later Phase 3R default-selection experiment could be safe for a tightly scoped objective. Existing Phase 3N metadata remains trace context only, and `metadata_only_ok` is not canonical action ownership approval.

The preview requires Phase 3P `continue shadow`, no Phase 3O risky rows, Phase 3L-ready canonical candidates, matching Phase 3H signatures, no Phase 3I/3J blockers, no duplicate open action risk, sufficient canonical coverage and exact order match. If any gate fails, default planner selection stays legacy.

## Phase 3S Boundary

Phase 3S audits Phase 3R `payload.default_selection_experiment` metadata separately from Phase 3N `payload.planner_experiment`. It verifies the Phase 3R action still belongs to the legacy `AgenticMarketingOpportunity` and that canonical ids remain metadata only.

The default planner remains legacy outside the explicit Phase 3R command. Phase 3S does not migrate default selection, create canonical recommended actions, sync lifecycle, change execution parents or remove metadata.

## Phase 3T Readiness

Phase 3T does not expand Phase 3N or Phase 3R apply. It only inspects whether an explicit multi-objective scope has clean enough Phase 3N/3R metadata, preview, shadow, readiness, signature, continuity, lifecycle, duplicate-risk, coverage and order evidence.

Global default planner migration remains blocked even when Phase 3T reports `ready_for_scoped_expansion`.
