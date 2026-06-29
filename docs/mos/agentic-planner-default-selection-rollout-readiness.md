# Agentic Planner Default Selection Rollout Readiness

Phase 3T determines whether the Phase 3R scoped default-selection experiment can expand from one explicit objective to a controlled multi-objective scope.

It is readiness-only. It does not activate the agentic planner by default, enable global default planner migration, create canonical recommended actions, create `AgenticMarketingAction` rows with canonical `Opportunity` parents, change `AgenticMarketingAction.opportunity_id`, change action statuses, change dedupe hashes, sync lifecycle state, rewrite payloads, migrate execution parents, change routes or dispatch jobs.

## Boundary

Phase 3T composes diagnostics from Phase 3Q, 3P, 3S, 3O, 3L, 3H, 3I and 3J for an explicit workspace and objective scope. Canonical ids remain metadata and selection context only. Actions remain legacy-owned by `AgenticMarketingOpportunity`.

The command is:

```bash
php artisan mos:inspect-agentic-planner-default-selection-rollout-readiness --workspace=... --objectives=... --limit=...
```

Optional filters are `--site=`, `--detector=` and `--include-metadata-only-ok`.

## Readiness Statuses

- `ready_for_scoped_expansion`: every objective in scope passes all gates and is eligible for limited multi-objective Phase 3U planning.
- `keep_single_objective_scope`: no risky row exists, but the inspected scope has no canonical candidates ready for expansion.
- `blocked_by_phase_3s`: existing Phase 3R `payload.default_selection_experiment` rows include a risky audit status.
- `blocked_by_preview`: Phase 3Q does not return `preview_safe`, or required Phase 3Q `apply_safety` diagnostics are missing.
- `blocked_by_shadow`: Phase 3P does not recommend `continue shadow`.
- `blocked_by_phase_3o`: existing Phase 3N `payload.planner_experiment` rows include a risky audit status.
- `blocked_by_readiness`: a canonical candidate is not Phase 3L `planner_candidate_ready_for_guarded_experiment`.
- `blocked_by_signature`: Phase 3H signatures do not match.
- `blocked_by_continuity`: Phase 3I reports continuity blockers.
- `blocked_by_lifecycle`: Phase 3J reports lifecycle ambiguity or conflict.
- `blocked_by_duplicate_risk`: duplicate open legacy action risk is non-zero.
- `insufficient_canonical_coverage`: canonical coverage is not sufficient for each objective.
- `order_mismatch`: canonical proposed order does not exactly match legacy order.
- `no_candidate_scope`: no valid workspace/objective or objective-group scope was inspected.

## Required Gates

Phase 3T reports `ready_for_scoped_expansion` only when every objective in the scope has Phase 3Q `preview_safe`, Phase 3P `continue shadow`, Phase 3S clean or operator-accepted `metadata_only_ok` rows, Phase 3O clean or `metadata_only_ok` rows, Phase 3L planner-ready candidates, matching Phase 3H signatures, no Phase 3I continuity blockers, no Phase 3J lifecycle ambiguity or conflict, zero duplicate open action risk, sufficient canonical coverage and exact order parity between legacy and canonical proposed order.

Readiness is fail-closed: missing Phase 3Q `apply_safety` evidence for shadow, Phase 3O risk, Phase 3L readiness regression, Phase 3H signature risk, Phase 3I continuity risk, Phase 3J lifecycle risk, duplicate risk, canonical coverage or exact order parity is reported as `blocked_by_preview`, not as ready.

`metadata_only_ok` remains traceability only. It is counted and can be sampled with `--include-metadata-only-ok`, but it does not approve canonical action ownership.

## Why Global Migration Remains Blocked

Phase 3T answers only whether a limited multi-objective readiness envelope exists. It does not define global routing, canonical action ownership, execution parent migration, lifecycle sync or rollback semantics for canonical-owned actions. Canonical recommended actions therefore remain blocked.

## Rollback

Phase 3T has no runtime rollback because it writes no production data. If readiness later feeds Phase 3U, rollback must remain flag-first and must preserve legacy `AgenticMarketingOpportunity` ownership for actions and execution records.

## Phase 3U Prerequisites

Phase 3U may only be considered after Phase 3T reports `ready_for_scoped_expansion` for an explicit workspace/objective scope, operators review `metadata_only_ok` rows separately, sampled canonical and legacy order is identical, and rollback continues to ignore additive metadata without rewriting history.
