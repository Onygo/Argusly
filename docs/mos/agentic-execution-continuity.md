# Agentic Execution Continuity

Phase 3I defines read-only diagnostics for Agentic execution parent/reference continuity. It prepares future canonical reference work without changing execution ownership.

## Boundary

Phase 3I does not change execution foreign keys, execution routes, pipeline parents, action execution, approval gates, autonomous workflow selection or historical execution records.

Phase 3R keeps this continuity boundary. Even when canonical ordering is selected inside the guarded command, the resolved action parent remains `AgenticMarketingOpportunity`; any Phase 3I blocker prevents apply.

The legacy `AgenticMarketingOpportunity` id remains authoritative for:

- `AgenticMarketingAction.opportunity_id`;
- `AgenticActionRun.opportunity_id`;
- `AgenticMarketingExecutionPipeline.opportunity_id`;
- `AgenticMarketingExecutionAsset.opportunity_id`;
- execution routes using `{opportunity}`;
- generated brief/draft source refs;
- pipeline rollback snapshots.

Approval, feedback and execution audit rows remain pipeline-local through `pipeline_id`.

## Service

`AgenticOpportunityExecutionContinuityService` inspects one legacy Agentic opportunity and reports:

- safe linked canonical opportunity id, when exactly one bridge exists;
- objective, workspace, site and content context;
- actions, action runs, pipelines, assets, approvals, feedback and audit counts;
- generated brief/draft/content and rollback references;
- execution generator payload requirements and missing fields;
- canonical field availability and missing canonical fields;
- legacy-only execution dependencies;
- safe additive metadata targets;
- blocked reasons and recommended migration path.

The service is non-mutating.

## Command

Use:

```bash
php artisan mos:inspect-agentic-execution-continuity
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

The command reports inspected opportunities, linked canonical count, legacy-only count, actions, action runs, execution pipelines, execution assets, approvals, feedback, audit logs, safe additive metadata candidates, blocked count, blocked reasons and route/parent dependency samples.

## Additive Metadata Candidates

Canonical ids may be added only to future metadata after compatibility is proven:

- `agentic_marketing_execution_pipelines.input.canonical_opportunity_id`;
- `agentic_marketing_execution_assets.payload.canonical_opportunity_context`;
- `agentic_action_runs.input_snapshot.canonical_opportunity_id`;
- `generated_briefs.client_refs.canonical_opportunity_id`;
- `generated_drafts.meta.canonical_opportunity_id`.

Existing payloads, assets, action runs, approvals, feedback, audit logs and rollback snapshots must not be rewritten.

## Blockers

Parent migration remains blocked when:

- no safe canonical bridge exists;
- multiple canonical opportunities point at one legacy Agentic row;
- canonical workspace context mismatches legacy objective workspace;
- execution rows would be missed by canonical-parent-only lookup;
- execution generator payload fields are missing;
- rollback snapshots preserve legacy opportunity ids;
- lifecycle mapping and canonical action ownership are not designed.

## Future Options

1. Keep execution legacy-owned and use canonical context only in diagnostics/read-only displays.
2. Add canonical ids as metadata for newly-created execution rows after payload compatibility tests pass.
3. Add a guarded dual-reference writer for future rows.
4. Design an explicit parent migration only after routes, approvals, rollback, generated assets, action runs and lifecycle semantics have a rollback plan.

## Phase 3J Interaction

Phase 3J consumes this continuity report when planning future canonical action ownership. Any `canonical_parent_only_lookup_would_miss_*` blocker keeps canonical action ownership blocked, because a canonical-parent-only planner would miss existing legacy actions, action runs, pipelines or assets.

Canonical opportunity ids may still be proposed as additive metadata for future action payloads, but they remain diagnostic-only until lifecycle mapping is single-valued and execution parent continuity has a tested rollback path.

## Phase 3K Additive Metadata Writer

Phase 3K implements the additive metadata option for future rows behind `features.mos_agentic_execution_canonical_metadata_writer`. It writes only `canonical_opportunity_context` into new pipeline input, new asset payloads and new action-run input snapshots, plus scalar canonical ids in newly generated brief `client_refs` and draft `meta`.

Phase 3K does not backfill old rows and does not change any parent lookup. Phase 3I blockers still prevent metadata when the target scope would be unsafe, and historical rollback snapshots remain untouched.

## Phase 3L Planner Readiness

Phase 3L reuses this continuity report to decide whether a future planner experiment would miss existing execution state. Any `canonical_parent_only_lookup_would_miss_*` reason blocks planner readiness for the inspected Agentic row.

The Phase 3L command is read-only and does not change parent lookups. It reports continuity blocker counts separately so a row with canonical context or Phase 3K metadata cannot be mistaken for a row whose execution parent migration is safe.

## Phase 3M Planner Experiment

Phase 3M consumes Phase 3I continuity blockers through Phase 3L readiness. `mos:compare-agentic-planner-candidates` excludes any row where canonical-parent-only lookup would miss existing actions, action runs, pipelines, assets or related execution state.

The dry-run adapter returns in-memory DTOs only. It does not write run items, audit logs, jobs, action statuses or execution parent ids. A future Phase 3N apply path remains blocked until continuity reads and rollback behaviour are proven under scoped dry runs.

## Phase 3N Apply Experiment

Phase 3N keeps execution continuity legacy-owned. `mos:apply-agentic-planner-canonical-experiment` blocks any row where Phase 3I reports canonical-parent-only lookup blockers, then calls the existing planner against the legacy Agentic opportunity.

Created or reused actions, run items, audit logs and future execution routes continue to reference `AgenticMarketingOpportunity` ids. Canonical `Opportunity` ids appear only in `payload.planner_experiment` and must not be used for execution parent lookup.

## Phase 3O Continuity Audit

Phase 3O rechecks Phase 3I continuity for Phase 3N actions. It treats the audited action and its own action-run snapshot as expected legacy-owned rows, then reports `continuity_risk` when additional actions, action runs, pipelines, assets or rollback snapshots would make canonical-parent-only lookup unsafe.

No execution parent ids, routes, run items, audit logs, approvals, feedback, execution pipelines or historical rollback snapshots are rewritten.

## Phase 3P Shadow Diagnostics

Phase 3P consumes Phase 3I continuity blockers as shadow safety signals. Any canonical-parent-only lookup risk is reported as `continuity_risk_count` and blocks the recommendation from becoming `continue shadow`.

The shadow rollout does not migrate execution parents, routes, pipelines, assets, approvals, feedback, action runs or rollback snapshots. Legacy `AgenticMarketingOpportunity` ids remain execution authority.

## Phase 3Q Default-Selection Preview

Phase 3Q promotes Phase 3I continuity into a default-selection preview gate. Any `canonical_parent_only_lookup_would_miss_*` risk blocks `preview_safe` with `continuity_risk`.

## Phase 3S Continuity Audit

Phase 3S re-checks Phase 3I continuity for actions that already carry Phase 3R default-selection metadata. If canonical-parent-only lookup blockers appear after apply, the row is reported as `continuity_risk`.

The audit remains read-only. It does not migrate execution parents, run items, pipelines, assets, approvals, feedback or audit logs from legacy Agentic opportunity ids to canonical opportunity ids.

The preview does not migrate execution parents, dispatch execution jobs, rewrite routes, create run items, write audit logs or update payloads. Canonical ids remain diagnostics and trace metadata only.

## Phase 3T Rollout Readiness

Phase 3T reports `blocked_by_continuity` when Phase 3I continuity has blockers for any inspected objective. A scope cannot be ready for Phase 3U while canonical-parent-only lookups would miss actions, runs, pipelines, assets, approvals, feedback, audit logs or rollback evidence.

Readiness inspection does not migrate execution parents or backfill execution metadata.
