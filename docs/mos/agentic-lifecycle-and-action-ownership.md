# Agentic Lifecycle And Action Ownership

Phase 3J adds read-only diagnostics for `AgenticMarketingOpportunity` lifecycle mapping and future canonical action ownership planning.

## Boundary

Phase 3J does not change execution ownership, planner selection, action creation, routes, approvals, execution pipelines, execution assets, feedback, audit logs or rollback snapshots. It does not create canonical recommended actions and does not sync lifecycle status.

Phase 3R does not make canonical lifecycle authoritative. Lifecycle ambiguity or conflict blocks the default-selection experiment, and canonical recommended actions remain blocked.

`AgenticMarketingOpportunity` remains the execution FK authority. Canonical opportunity ids are additive future metadata candidates only.

## Lifecycle Mapping

`AgenticOpportunityLifecycleMap` maps current Agentic statuses to candidate canonical statuses:

| Agentic status | Candidate canonical status | Sync safe? | Reason |
| --- | --- | --- | --- |
| `open` | `open`, `reviewing` | No | Agentic `open` means executable input, not just reviewable strategic state. |
| `dismissed` | `dismissed` | No | Existing actions or pipelines can still exist after dismissal. |
| `completed` | `actioned`, `resolved` | No | Completion can mean action-level completion, pipeline-level completion or no longer open. |

Unknown statuses are reported as unmapped. No status is reverse-safe or sync-safe in Phase 3J.

## Inspection

`AgenticOpportunityLifecycleInspectionService` inspects one legacy Agentic opportunity and an optional linked canonical `Opportunity`. It reports safe bridge identity, legacy and canonical statuses, candidate mapped statuses, alignment, conflicts, action/pipeline/run counts, lifecycle ambiguity flags, blocked reasons and a future migration path.

Use:

```bash
php artisan mos:inspect-agentic-lifecycle-map
```

The command supports `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=`. It is read-only and reports inspected count, linked canonical count, legacy-only count, aligned/conflict/unmapped/blocked counts, status breakdown, blocked reason samples and route or execution dependency samples.

## Canonical Action Ownership Planning

`AgenticOpportunityCanonicalActionOwnershipPlanner` describes a future canonical action owner candidate for linked Agentic opportunities. It composes:

- Phase 3H action signatures from `AgenticOpportunityActionSignatureService`;
- Phase 3I execution continuity from `AgenticOpportunityExecutionContinuityService`;
- Phase 3J lifecycle ambiguity from `AgenticOpportunityLifecycleInspectionService`.

Use:

```bash
php artisan mos:plan-agentic-canonical-action-ownership
```

The command is read-only and reports inspected count, linked canonical count, legacy-only count, canonical ownership candidate count, blocked count, open legacy action count, duplicate risk count, signature samples, proposed metadata samples, fallback route samples and blocked reason samples.

## Blockers

Canonical action ownership remains blocked when:

- no safe canonical bridge exists;
- duplicate canonical bridges exist;
- Phase 3H action signatures are blocked;
- Phase 3I reports canonical-parent-only lookup gaps;
- lifecycle status is ambiguous or conflicting;
- open legacy actions already exist and a canonical action would duplicate them.

Canonical recommended actions remain blocked because current action planning, execution FKs, routes, approvals, pipeline state, generated assets, feedback, audit logs and rollback snapshots still depend on legacy Agentic ids.

## Future Writer Prerequisites

Before any writer phase, the project needs:

- a single-valued lifecycle mapping with explicit reverse rules;
- one safe canonical bridge per migrated Agentic row;
- no unresolved Phase 3H signature blockers;
- no Phase 3I parent lookup gaps for the migration scope;
- duplicate-action prevention for existing open Agentic actions;
- a payload metadata contract for future rows only;
- route, approval, feedback, audit and rollback compatibility tests.

## Rollback Strategy

Phase 3J has no runtime rollback because it does not write production data. If a later writer is introduced, rollback must preserve the legacy `AgenticMarketingOpportunity` id as execution authority, keep additive canonical metadata removable, and never rewrite historical action runs, pipelines, assets, approvals, feedback, audit logs or rollback snapshots in place.

## Phase 3K Interaction

Phase 3K uses the Phase 3J lifecycle and action-ownership diagnostics to decide whether additive execution metadata is safe. Lifecycle conflicts, unmapped statuses and execution-scope lifecycle ambiguity block metadata. Planner/action ownership migration remains blocked even when metadata is written, because the canonical id is trace context only.

## Phase 3L Planner Readiness

Phase 3L composes Phase 3J lifecycle/action ownership diagnostics into `AgenticPlannerReadinessInspectionService`. A row is planner-ready only when lifecycle ambiguity and status conflict are absent for the inspected scope.

The readiness command does not create canonical recommended actions and does not sync lifecycle state. It can report `metadata_ready_only` when Phase 3K trace metadata is safe but Phase 3J still prevents planner migration. A future guarded planner experiment must keep rollback config-first and preserve the legacy Agentic opportunity id as execution authority.

## Phase 3M Planner Experiment

Phase 3M introduces a default-off comparison path behind `features.mos_agentic_planner_canonical_experiment` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false`. It compares legacy planner candidates with canonical-linked Phase 3L-ready candidates, but it does not sync lifecycle or transfer action ownership.

Any lifecycle ambiguity, status conflict or duplicate open legacy action keeps the row out of the canonical experiment order and blocks a future apply phase. Phase 3N must define single-valued lifecycle ownership and rollback behaviour before canonical rows can own planner output.

## Phase 3N Apply Experiment

Phase 3N does not let canonical rows own planner output. It only lets a scoped command select Phase 3L-ready canonical-linked rows, then plan actions through the legacy Agentic opportunity.

Lifecycle ambiguity or conflict blocks apply. No lifecycle state is synced, and no historical action, run, pipeline, approval, feedback, audit or rollback row is rewritten. Rollback is disabling `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT`; additive payload metadata can be ignored.

## Phase 3O Lifecycle Audit

Phase 3O reports whether Phase 3J lifecycle context has become ambiguous or conflicting for actions with Phase 3N metadata. `metadata_only_ok` remains acceptable when lifecycle evidence is still trace-only, but any status conflict or ambiguity beyond the audited action's expected legacy rows is reported as `lifecycle_risk`.

## Phase 3P Shadow Diagnostics

Phase 3P reads lifecycle ambiguity and status conflict as shadow blockers. It does not sync lifecycle, does not create canonical recommended actions, and does not move action ownership from `AgenticMarketingOpportunity` to canonical `Opportunity`.

Any future guarded default selection must first define single-valued lifecycle ownership and prove that canonical action ownership does not conflict with existing legacy actions, execution pipelines or approvals.

Action ownership remains legacy. Canonical rows still cannot own Agentic planner output.

## Phase 3Q Default-Selection Preview

Phase 3Q treats lifecycle ambiguity or conflict as `lifecycle_risk` and blocks `preview_safe`. Canonical proposed candidates must pass Phase 3J through Phase 3L readiness before they can be considered for a future Phase 3R scoped default-selection experiment.

The preview does not sync lifecycle, create canonical recommended actions or move action ownership. `metadata_only_ok` remains traceability only and does not approve canonical opportunities as action owners.

## Phase 3S Lifecycle And Ownership Audit

Phase 3S reports `lifecycle_risk` when Phase 3J lifecycle/action ownership becomes ambiguous or conflicting for a Phase 3R action. It reports `ownership_risk` when the action no longer points to the same legacy `AgenticMarketingOpportunity` recorded in `payload.default_selection_experiment`.

Phase 3S does not sync lifecycle or migrate ownership. Canonical recommended actions remain blocked until a later phase defines explicit ownership, execution parent and rollback rules.

## Phase 3T Rollout Readiness

Phase 3T reports `blocked_by_lifecycle` when Phase 3J finds lifecycle ambiguity or status conflict in any objective scope. It also keeps canonical recommended actions blocked because action ownership, lifecycle authority and execution-parent rollback are still undefined.

`metadata_only_ok` is traceability only. It does not override lifecycle ambiguity and does not approve canonical action ownership.
