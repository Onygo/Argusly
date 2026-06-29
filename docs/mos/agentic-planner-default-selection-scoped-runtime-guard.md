# Agentic Planner Default Selection Scoped Runtime Guard

Phase 3V introduces a guarded runtime design layer for MOS Agentic planner default-selection eligibility.

The guard is disabled by default through `mos.agentic_planner.default_selection.scoped_runtime_enabled = false`. It has no global rollout flag, no percentage rollout and no inferred scope. Operators must configure exact workspace/objective scopes in `mos.agentic_planner.default_selection.allowed_scopes`, and the guard still blocks unless the requested workspace and objective set exactly match one configured scope.

Config-cache behavior is fail-closed: the cached Laravel config is authoritative at runtime, so missing or stale `scoped_runtime_enabled`/`allowed_scopes` values keep the guard blocked until config is deliberately refreshed. No environment-only change can bypass the exact scoped config checks.

Phase 3V is scoped only. It is not global rollout, does not change default planner behavior, does not enable canonical default selection and does not create canonical `AgenticMarketingAction` rows. It does not change `AgenticMarketingAction.opportunity_id`, migrate ownership, rewrite execution parents, sync lifecycle state, mutate metadata, payloads, statuses, dedupe hashes or routes, or dispatch jobs.

The guard composes fresh Phase 3T readiness and fresh Phase 3U scoped rollout planning. Both remain mandatory gates. Phase 3T must return `ready_for_scoped_expansion`, Phase 3U must return `eligible`, and both returned scopes must exactly match the requested workspace/objectives.

Runtime eligibility also requires an explicit metadata-only review acknowledgement, duplicate risk of zero, confirmed order parity, and absent lifecycle and continuity blockers. Even when all checks pass, rollback remains `legacy_first`, meaning legacy `AgenticMarketingOpportunity` action ownership stays authoritative and canonical ids remain metadata/selection context only.

Inspect with:

```bash
php artisan mos:inspect-agentic-planner-default-selection-scoped-runtime-guard --workspace=WORKSPACE_ID --objectives=OBJECTIVE_ID_A,OBJECTIVE_ID_B --limit=1 --ack-metadata-only-review
```

The command reports config flag status, allowed scope status, Phase 3T status, Phase 3U eligibility, final guard decision, blocked reasons, rollback mode and an explicit statement that runtime use would still remain legacy-first.
