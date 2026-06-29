# Agentic Execution Canonical Metadata

Phase 3K adds a guarded additive metadata writer for future Agentic execution rows only.

## Boundary

`AgenticMarketingOpportunity` remains the execution foreign-key authority. Phase 3K does not repoint execution FKs, change routes, change planner selection, sync lifecycle state, create canonical recommended actions, create a second execution pipeline, update approvals or mutate rollback snapshots.

Phase 3R adds a separate `payload.default_selection_experiment` metadata block on legacy-owned actions. It is selection trace context only and does not replace Phase 3K execution metadata or execution lookups.

Historical rows are never backfilled. Existing actions, action runs, pipelines, assets, generated briefs, generated drafts, approvals, feedback, audit logs and rollback snapshots remain untouched.

## Feature Flag

The writer is default-off:

```php
features.mos_agentic_execution_canonical_metadata_writer
```

Environment:

```bash
ARGUSLY_FEATURE_MOS_AGENTIC_EXECUTION_CANONICAL_METADATA_WRITER=false
```

When disabled, normal future execution rows are created with the existing payload shape.

## Metadata Shape

When the resolver reports safe context, future rows may receive `canonical_opportunity_context` with canonical opportunity id, legacy Agentic opportunity id, objective id, workspace id, site id, detector key, Agentic type/status, source-scoped dedupe key, bridge source, metadata version, resolved timestamp and resolver class.

The metadata version is `agentic-execution-canonical-context:v1`. Existing `opportunity_id` fields keep the legacy Agentic id.

## Resolver Rules

`AgenticExecutionCanonicalMetadataResolver` returns metadata only when exactly one safe bridge exists, workspace and organization context match, required metadata fields are present, Phase 3I continuity is safe for the target, and Phase 3J does not report lifecycle conflict or execution-scope lifecycle ambiguity.

The resolver does not infer missing workspace, site or objective context. Duplicate bridges, workspace mismatch, incomplete target fields and unsafe continuity return blocked reasons and no metadata.

## Future-Row Targets

With the feature flag enabled, metadata is added only during normal creation of:

- `agentic_marketing_execution_pipelines.input.canonical_opportunity_context`;
- `agentic_marketing_execution_assets.payload.canonical_opportunity_context`;
- `agentic_action_runs.input_snapshot.canonical_opportunity_context`;
- `briefs.client_refs.canonical_opportunity_id`;
- `drafts.meta.canonical_opportunity_id`.

New assets copy the context from the new pipeline input. This avoids resolving against a just-created pipeline as if it were historical state.

## Commands

Read-only diagnostics:

```bash
php artisan mos:plan-agentic-execution-canonical-metadata
```

Configuration check:

```bash
php artisan mos:write-agentic-execution-canonical-metadata
```

The write command does not backfill. It only reports whether the feature flag is enabled and explains that writes happen inside future execution creation flows.

## Why Backfill Is Forbidden

Phase 3I showed that execution rows, generated assets, approvals, feedback, audit logs and rollback snapshots still resolve through legacy Agentic ids and pipeline-local chains. Backfilling canonical metadata into old rows would blur historical evidence without changing the actual parent model.

## Rollback Strategy

Rollback is config-first: disable `ARGUSLY_FEATURE_MOS_AGENTIC_EXECUTION_CANONICAL_METADATA_WRITER`. Newly-created rows stop receiving canonical context. Existing rows that already have additive metadata can keep it because execution still uses legacy ids.

## Remaining Blockers

Before planner selection or parent migration, the project still needs canonical action ownership to become unblocked, lifecycle sync rules with rollback semantics, route and policy support for any non-legacy parent, approval/feedback/audit/rollback compatibility tests, and duplicate-action prevention across legacy and canonical owners.

## Phase 3L Planner Readiness

Phase 3L uses Phase 3K metadata availability as traceability evidence only. Metadata can produce `metadata_ready_only`, but it never promotes a row to planner-ready by itself.

Planner readiness still requires exactly one safe canonical bridge, safe Phase 3H signatures, no Phase 3I canonical-parent-only lookup blockers, no Phase 3J lifecycle ambiguity/conflict and no duplicate open legacy action risk. Disabling `ARGUSLY_FEATURE_MOS_AGENTIC_EXECUTION_CANONICAL_METADATA_WRITER` remains the rollback path for metadata writes; planner selection remains legacy-owned regardless of that flag.

## Phase 3M Planner Experiment

Phase 3M uses Phase 3K metadata availability as comparison context only. `AgenticCanonicalPlannerExperimentService` may report whether a linked row is safe for scoped dry-run, but default planner behaviour remains legacy and no canonical metadata is used as an execution parent.

The experiment flag is separate from the metadata writer: `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false`. Rollback is disabling that flag. Future Phase 3N work must prove that metadata, signatures, lifecycle and continuity are all safe before any apply path can create planner output.

## Phase 3N Planner Apply Metadata

Phase 3N writes a separate action payload metadata block, `planner_experiment`, only after the existing legacy planner creates or reuses an `AgenticMarketingAction`.

This metadata uses version `agentic-planner-canonical-apply:v1` and contains canonical opportunity id, legacy Agentic opportunity id, objective id, workspace id, selection source, Phase 3M signature, Phase 3L readiness status, timestamp and command actor. It is not execution canonical metadata, does not change execution parent ids and does not require lifecycle sync.

Rollback is disabling `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT`. Runtime flows can ignore `payload.planner_experiment` because execution remains legacy-owned.

## Phase 3O Apply Metadata Audit

Phase 3O treats `payload.planner_experiment` as separate from execution canonical metadata. The audit checks whether that metadata still points to an existing canonical row, a matching legacy Agentic parent and a current Phase 3H signature.

No metadata removal writer is included. The supported rollback boundary is flag-off plus runtime ignore; removing `payload.planner_experiment` would require a later metadata-only writer with its own feature flag and required objective/limit scope.

## Phase 3P Shadow Diagnostics

Phase 3P uses canonical metadata as trace context only. Shadow diagnostics may read Phase 3K/3N metadata, but they do not write metadata, update payloads, backfill historical rows or make canonical ids execution parents.

The default-flow hook keeps its report in-process only. It is not stored on `AgenticMarketingRun.result`, `AgenticMarketingRunItem.result`, `AgenticMarketingAction.payload` or MOS storage.

## Phase 3Q Default-Selection Preview

Phase 3Q may read Phase 3K/3N metadata as traceability evidence, but it does not write metadata or use canonical ids as execution parents. The preview report stays in memory for default-flow diagnostics and command output only.

`metadata_only_ok` and `metadata_ready_only` remain non-ownership signals. They can explain traceability, but they cannot make default planner selection canonical and cannot approve canonical action ownership.

## Phase 3S Metadata Audit Boundary

Phase 3S audits `payload.default_selection_experiment` metadata written by Phase 3R. It does not alter Phase 3K execution metadata and does not remove `payload.planner_experiment`.

Rollback planning names only `payload.default_selection_experiment` as the metadata path that would be considered. No metadata removal writer is implemented in Phase 3S; operational rollback remains disabling the Phase 3R flag and ignoring the metadata.

## Phase 3T Rollout Readiness

Phase 3T counts existing `payload.default_selection_experiment` and `payload.planner_experiment` metadata in the requested scope. Those counts are diagnostics only and do not authorize metadata rewrites, metadata removal or canonical execution parentage.

`metadata_only_ok` rows are sampled separately because they prove traceability, not action ownership.
