# Agentic Planner Default Selection Scoped Rollout Plan

Phase 3U turns Phase 3T readiness output into an operator-facing scoped rollout plan for a limited Agentic planner default-selection expansion.

It is read-only scoped rollout planning only. It does not activate runtime rollout, add feature flags, enable the agentic planner by default, create actions, write metadata, sync lifecycle state, migrate ownership, mutate payloads, mutate statuses, mutate dedupe hashes, change routes, dispatch jobs or rewrite historical execution parents.

## Boundary

The Phase 3U service and command compose Phase 3T:

```bash
php artisan mos:inspect-agentic-planner-default-selection-scoped-rollout-plan --workspace=... --objectives=... --limit=...
```

Optional filters mirror Phase 3T where practical: `--site=`, `--detector=` and `--include-metadata-only-ok`.

Phase 3U only accepts an explicit workspace and explicit objective list. It never infers a global rollout scope and does not treat objective groups as rollout scope. If Phase 3T is blocked, missing, ambiguous, returns `no_candidate_scope`, returns a different workspace or returns a different objective set, Phase 3U fails closed and reports a blocked plan.

## Eligibility

A Phase 3U plan is `eligible` only when Phase 3T reports `ready_for_scoped_expansion` and recommends limited multi-objective Phase 3U planning for the exact explicit workspace/objective scope. The Phase 3T `workspace_id` must match the requested workspace and the inspected objective ids must exactly match the requested objective ids. Any other Phase 3T status, recommendation or returned scope produces a `blocked` plan.

An eligible plan includes:

- workspace id
- inspected objectives
- Phase 3T readiness status
- rollout eligibility
- recommended rollout mode: `scoped_read_only_plan`
- recommended first rollout scope
- objectives included and excluded
- operator checklist
- rollback checklist
- metadata_only_ok review requirement
- order parity confirmation
- duplicate risk confirmation
- canonical coverage confirmation
- explicit runtime activation statement

## Ownership

Legacy `AgenticMarketingOpportunity` action ownership remains authoritative. Canonical ids are metadata and selection context only. `metadata_only_ok` rows require manual operator review and never approve canonical ownership migration.

Phase 3U does not create canonical `AgenticMarketingAction` rows and does not change `AgenticMarketingAction.opportunity_id`.

## Operator Checklist

- review metadata_only_ok rows manually
- confirm sampled canonical and legacy order are identical
- confirm duplicate open legacy action risk is zero
- confirm no lifecycle ambiguity/conflict
- confirm no continuity blockers
- confirm all objectives are still in explicit scoped list
- confirm rollback remains legacy-first

## Rollback Checklist

- disable any future scoped feature flag before runtime rollout
- preserve legacy AgenticMarketingOpportunity action ownership
- ignore additive canonical metadata
- do not rewrite historical execution parents
- do not mutate dedupe hashes
- do not mutate action statuses
- use Phase 3T and Phase 3U reports as audit artifacts only

## What Phase 3U Does Not Do

Phase 3U has no runtime activation and no feature flags yet. It performs no writes, creates no actions, performs no lifecycle sync and starts no ownership migration. It is an audit artifact that tells operators whether a later scoped runtime phase may be designed.

The recommended next phase is a guarded scoped runtime design that introduces an explicit feature flag, still preserves legacy-first rollback and remains blocked until Phase 3T and Phase 3U reports are reviewed.
