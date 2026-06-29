# Agentic Planner Default-Selection Experiment

Phase 3R adds a scoped, guarded default-selection experiment for canonical-linked Agentic planner candidates.

It may use the Phase 3Q canonical proposed order only for an explicitly supplied objective and limit, and only when Phase 3Q returns `preview_safe`. It still creates or reuses normal legacy-owned `AgenticMarketingAction` rows through `AgenticMarketingActionPlanner::planForOpportunity`.

## Feature Flag

Default off:

```php
features.mos_agentic_planner_canonical_default_selection_experiment
```

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT=false
```

## Boundary

Phase 3R does not replace the default planner globally, create canonical recommended actions, create `AgenticMarketingAction` rows with canonical `Opportunity` ids, change execution parent ids, change routes, sync lifecycle state, rewrite historical payloads, backfill records, change dedupe hashes or bypass approval gates.

Canonical ids are selection context and payload metadata only. `AgenticMarketingAction.opportunity_id` remains the legacy `AgenticMarketingOpportunity` id because execution routes, action runs, pipelines, assets, approvals, feedback, audit logs and rollback snapshots still resolve through legacy Agentic ownership.

## Required Gates

Apply is blocked unless all are true:

- feature flag enabled;
- `--objective=` is explicitly provided;
- `--limit=` is explicitly provided;
- Phase 3Q status is `preview_safe`;
- Phase 3P recommendation is `continue shadow`;
- Phase 3O audit rows are only `clean` or `metadata_only_ok`;
- every canonical proposed candidate is Phase 3L `planner_candidate_ready_for_guarded_experiment`;
- Phase 3H signatures match;
- Phase 3I continuity has no blockers;
- Phase 3J lifecycle has no ambiguity or conflict;
- duplicate open action risk is zero;
- canonical coverage is sufficient;
- canonical proposed order exactly matches legacy order for the selected scope;
- every canonical selected row resolves back to the expected legacy `AgenticMarketingOpportunity`.

## Command

```bash
php artisan mos:apply-agentic-planner-default-selection-experiment \
  --objective=<agentic-marketing-objective-id> \
  --limit=<n>
```

Dry-run is the default. It runs Phase 3Q, reports selected canonical ids, resolved legacy ids and actions that would be created or reused, and writes nothing.

Apply requires both the feature flag and `--apply`:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT=true
php artisan mos:apply-agentic-planner-default-selection-experiment \
  --objective=<agentic-marketing-objective-id> \
  --limit=<n> \
  --apply
```

Apply calls the existing legacy planner for each resolved legacy opportunity and then writes additive action payload metadata.

## Metadata

Phase 3R adds `payload.default_selection_experiment` after the action has been created or reused:

```yaml
version: agentic-planner-canonical-default-selection:v1
canonical_opportunity_id: <canonical Opportunity id>
legacy_agentic_marketing_opportunity_id: <legacy AgenticMarketingOpportunity id>
objective_id: <objective id>
workspace_id: <workspace id>
selection_source: canonical_default_selection_experiment
phase_3q_preview_status: preview_safe
phase_3p_recommendation: continue_shadow
phase_3m_signature: <signature>
phase_3l_readiness_status: planner_candidate_ready_for_guarded_experiment
applied_at: <ISO-8601 timestamp>
applied_by: command
```

The metadata is additive. Existing `payload.planner_experiment` metadata is preserved. Metadata is not used for dedupe, routing, lifecycle sync or execution lookup, and Phase 3R does not change `opportunity_id`, `status`, `dedupe_hash`, `payload_hash` or `open_dedupe_hash`.

## Rollback

Rollback is flag-first:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT=false
```

Existing metadata can be ignored because actions remain legacy-owned. Phase 3R does not add a metadata removal writer.

## Phase 3S Audit

Phase 3S adds `mos:audit-agentic-planner-default-selection-experiment` and `mos:plan-agentic-planner-default-selection-experiment-rollback` for rows with `payload.default_selection_experiment.version = agentic-planner-canonical-default-selection:v1`.

The audit verifies that Phase 3R actions still resolve to the legacy `AgenticMarketingOpportunity`, the canonical bridge still points back to that same legacy row, Phase 3Q still returns `preview_safe`, Phase 3P still recommends `continue shadow`, Phase 3O remains `clean` or `metadata_only_ok`, Phase 3L/3H/3I/3J have not regressed and duplicate open action risk has not appeared.

Phase 3S is read-only. It does not remove metadata, change default planner behavior, create canonical actions, change `opportunity_id`, status, dedupe hashes, execution parents, lifecycle state, approvals or routes.

## Broader Rollout Blockers

Before any broader default rollout or Phase 3T scoped expansion, Phase 3S must show no risky rows. Canonical action ownership still needs explicit approval for execution parent migration, route binding, lifecycle sync, dedupe semantics, approval gates, rollback snapshots and historical payload policy.

## Phase 3T Readiness

Phase 3T adds `mos:inspect-agentic-planner-default-selection-rollout-readiness` for explicit workspace/objective scopes. It composes Phase 3Q preview, Phase 3P shadow, Phase 3S default-selection audit, Phase 3O apply audit, Phase 3L readiness, Phase 3H signatures, Phase 3I continuity and Phase 3J lifecycle diagnostics.

`ready_for_scoped_expansion` only means the scope may be eligible for a later limited multi-objective Phase 3U. It does not broaden Phase 3R apply, change the default planner, create canonical recommended actions or make canonical `Opportunity` rows action owners.
