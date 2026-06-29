# Agentic Action Dedupe And Selection

Phase 3H prepares Agentic action planning for canonical-linked `AgenticMarketingOpportunity` rows without changing default planning behaviour.

## Boundary

Phase 3H is read-only. It does not change `AgenticMarketingActionPlanner`, autonomous workflow selection, action statuses, action payloads, execution pipeline parents or canonical recommended-action writes.

Phase 3R still delegates action creation and reuse to `AgenticMarketingActionPlanner` and `AgenticMarketingAction::createOrReuseOpen`. Canonical ids are not added to dedupe inputs; Phase 3H signature mismatch or duplicate open action risk blocks apply.

## Signature Contract

`AgenticOpportunityActionSignatureService` returns a blocked-aware signature result for:

- legacy `AgenticMarketingOpportunity`;
- linked canonical `Opportunity`;
- existing `AgenticMarketingAction`;
- future canonical action candidates.

The signature version is `mos-agentic-action:v1`. Required context is workspace id, objective id, legacy Agentic opportunity id, detector key, Agentic type and action type. Optional context includes linked canonical opportunity id, content id, client site id, Phase 3B source-scoped dedupe key and normalized title/topic.

Linked legacy and canonical sources representing the same work resolve to the same signature. Different objectives and different action types remain distinct. Timestamp and score refreshes do not change the signature. Missing required context returns blocked reasons and no signature.

## Inspection Command

Use:

```bash
php artisan mos:inspect-agentic-action-dedupe
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

The command reports inspected opportunities, linked canonical count, legacy-only count, open action count, duplicate action risk count, safe canonical-equivalent candidates, blocked count, signature samples and blocked reasons.

## Future Selection Policy

Recommended future policy:

- preserve current legacy query order until execution continuity is ready;
- enrich canonical-linked rows for display only;
- continue creating `AgenticMarketingAction` rows against legacy Agentic opportunities;
- use canonical priority fields for action selection only after signature dedupe and execution parent continuity are implemented;
- store canonical opportunity ids only as additive future payload metadata;
- keep legacy Agentic opportunity id as execution identity until Phase 3I or later.

## Remaining Blockers

- execution parent/reference continuity is now inspectable through Phase 3I diagnostics, but parent migration remains blocked;
- lifecycle mapping for `open`, `dismissed` and `completed`;
- canonical action ownership metadata and UI source-link semantics;
- guarded planner migration after duplicate risks are inspectable and low.

## Phase 3I Continuity Dependency

`AgenticOpportunityExecutionContinuityService` and `php artisan mos:inspect-agentic-execution-continuity` confirm that action signatures alone are not enough for planner migration. Existing actions, action runs, pipelines, generated assets, approvals, feedback, audit logs and rollback snapshots still resolve through the legacy Agentic opportunity or pipeline chain.

Canonical action ownership may use Phase 3H signatures only after Phase 3I blockers are clear or explicitly accepted. Until then, canonical opportunity ids remain additive metadata candidates for future payloads, not action parents.

## Phase 3J Ownership Planning Dependency

Phase 3J composes this signature contract with lifecycle mapping and execution continuity through `AgenticOpportunityCanonicalActionOwnershipPlanner` and `php artisan mos:plan-agentic-canonical-action-ownership`.

The planner is read-only. It proposes canonical action owner candidates and future payload metadata, but blocks ownership when a safe canonical bridge is missing, duplicate bridges exist, signatures are blocked, Phase 3I reports canonical-parent-only lookup gaps, lifecycle status is ambiguous or a canonical action would duplicate open legacy Agentic actions.

## Phase 3K Metadata Boundary

Phase 3K may add canonical opportunity trace metadata to future execution rows, but it does not use that metadata for action dedupe or planner selection. `AgenticMarketingActionPlanner` continues to create legacy-owned `AgenticMarketingAction` rows, and canonical recommended actions remain blocked.

## Phase 3L Planner Readiness

Phase 3L adds `AgenticPlannerReadinessInspectionService` and `php artisan mos:inspect-agentic-planner-readiness` as diagnostics only. Planner selection is still unchanged: open legacy `AgenticMarketingOpportunity` rows are ranked by legacy priority and actions are created against the legacy opportunity id.

Phase 3L treats Phase 3H signatures as one readiness gate. A canonical-linked row cannot be a guarded planner candidate while signature context is missing or duplicate bridge context makes the canonical-equivalent signature unsafe. Even when signatures are safe, Phase 3I continuity, Phase 3J lifecycle ambiguity and duplicate open legacy actions can still block readiness.

## Phase 3M Comparison Contract

Phase 3M compares current legacy candidate order with canonical-linked ready-row order behind `features.mos_agentic_planner_canonical_experiment` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false`. The flag defaults off and does not change default planner selection.

The comparison reports signature equivalence and duplicate action risk, but it does not write dedupe keys, create `AgenticMarketingAction` rows, create canonical recommended actions or change action parents. Any signature blocker or duplicate open legacy action keeps the recommendation `blocked` for a future apply phase.

## Phase 3N Apply Boundary

Phase 3N may create or reuse actions only by calling the existing legacy `AgenticMarketingActionPlanner` for the legacy `AgenticMarketingOpportunity`. It does not create canonical recommended actions and does not set `AgenticMarketingAction.opportunity_id` to a canonical `Opportunity` id.

The apply experiment requires Phase 3M signature equivalence before planning. Experiment metadata is written after legacy planner dedupe has selected the action, so the canonical id remains trace context and not a dedupe owner. Duplicate open legacy action risk blocks apply.

## Phase 3O Duplicate And Signature Audit

Phase 3O audits whether an applied action's stored Phase 3M/3H source signature still matches current canonical context and whether any additional open legacy action now creates duplicate risk. The audited action itself is expected evidence of Phase 3N apply and is not counted as a duplicate by itself.

Default dedupe remains legacy-owned. Phase 3O does not change `dedupe_hash`, `open_dedupe_hash`, `payload_hash`, action status or action owner ids.

## Phase 3P Shadow Diagnostics

Phase 3P treats duplicate action risk as a hard shadow blocker. The shadow service compares legacy planner action signatures with canonical-linked candidates, reports duplicate risk count and signature mismatch count, and leaves all dedupe hashes untouched.

No `AgenticMarketingAction` rows or canonical recommended actions are created by shadow diagnostics. Default planner selection remains the legacy `AgenticMarketingOpportunity` order until a later guarded phase explicitly changes that contract.

## Phase 3Q Default-Selection Preview

Phase 3Q keeps duplicate action risk as a hard blocker. `preview_safe` requires zero duplicate open legacy action risk and leaves `dedupe_hash`, `open_dedupe_hash`, `payload_hash`, action status and action ownership untouched.

The preview may report canonical proposed default order, but it does not write canonical recommended actions and does not use canonical ids for dedupe or action parentage. `metadata_only_ok` remains traceability, not ownership approval.

## Phase 3S Duplicate And Ownership Audit

Phase 3S audits persisted Phase 3R actions for duplicate open legacy action risk and action ownership drift. `duplicate_risk` means an additional open action now collides with the audited legacy action. `ownership_risk` means `AgenticMarketingAction.opportunity_id` no longer matches the legacy Agentic opportunity id stored in `payload.default_selection_experiment`.

Phase 3S does not change `dedupe_hash`, `payload_hash`, `open_dedupe_hash`, action status or action parent ids. Canonical ids remain metadata and are never added to dedupe inputs.

## Phase 3T Rollout Readiness

Phase 3T reports `blocked_by_duplicate_risk` when duplicate open legacy action risk is non-zero for any objective in the proposed scope. It also reports `order_mismatch` when canonical proposed order would change the legacy default order.

The readiness command does not recalculate or write dedupe hashes, does not create actions and does not let canonical ids participate in action dedupe.
