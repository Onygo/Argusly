# MOS Opportunity Provider Contract

Phase: MOS Core 2B opportunity provider bridge and competitor signal promotion.

This contract prepares legacy opportunity-like systems for consolidation into the canonical MOS Opportunity Core without migrating records, changing screens or automatically persisting new `Opportunity` rows.

## Canonical Target

`App\Models\Opportunity` remains the canonical opportunity object. `App\Models\OpportunitySignal` remains the canonical bridge from observed signals into opportunities.

Opportunity providers emit inspection payloads only. Persistence must stay explicit in a migration or promotion service.

## Provider Contract

Opportunity-compatible providers implement `App\Services\Mos\Contracts\MosOpportunityProvider`, which extends the existing `MosProvider` contract so providers remain registered through `MosProviderRegistry`.

Providers expose:

- Provider key and label.
- Source model or source type.
- Supported opportunity types.
- Supported lifecycle states.
- Whether canonical opportunity payloads can be emitted safely.
- Whether signal payloads can be emitted.
- Read-only status.
- Migration readiness.
- Classification and risk level.
- Missing-field reporting for a specific legacy record.

The contract does not execute migrations, save canonical opportunities or update legacy records.

For Agentic planner Phase 3R, providers and canonical rows supply selection context only. The guarded command must resolve canonical candidates back to legacy `AgenticMarketingOpportunity` rows before any action is created or reused.

## Canonical Candidate Payload

`App\Services\Mos\Opportunity\CanonicalOpportunityCandidate` is a lightweight DTO for normalized candidates. It supports title, description, type, source, source model, source id, priority, confidence, impact, effort, business value, evidence, recommended actions, lifecycle status, workspace or organization context, related references, dedupe key, missing fields and unsupported conversion reasons.

`canPersistCanonically()` is intentionally conservative. It returns true only when the adapter reports no missing fields and no unsupported conversion reasons.

## Phase 2A Providers

| Provider | Source model | Classification | Readiness | Canonical payload | Signal payload | Risk |
| --- | --- | --- | --- | --- | --- | --- |
| `legacy-content-opportunities` | `ContentOpportunity` | consolidation candidate | high-value records with existing canonical links | Yes | Yes | High |
| `legacy-agentic-marketing-opportunities` | `AgenticMarketingOpportunity` | consolidation candidate | canonical link exists, Phase 3A audit confirms execution state still blocked | Yes | Yes | High |
| `legacy-competitor-content-opportunities` | `CompetitorContentOpportunity` | source evidence candidate | signal-first recommended | Yes | Yes | Medium |
| `legacy-faq-opportunity-audits` | `FaqOpportunityAudit` | consolidation candidate | admin workflow context missing | Yes, with missing-field reporting | Yes | Medium |
| `legacy-programmatic-opportunities` | `ProgrammaticOpportunity` | provider candidate | specialized expansion state should remain separate | Yes | No | Medium |
| `legacy-link-opportunities` | `LinkOpportunity` | projection | tactical projection, not strategic opportunity | No | No | Medium |

## Diagnostics

Use `php artisan mos:providers` to inspect the existing MOS provider registry plus opportunity provider readiness. The command reports provider key, legacy model, classification, readiness, canonical payload support, signal support and risk level.

## Competitor Signal Promotion

`CompetitorContentOpportunity` is signal-first evidence, not a second strategic opportunity system. Phase 2B promotes those records into `OpportunitySignal` through `App\Services\OpportunityIntelligence\CompetitorContentOpportunitySignalPromotionService`.

Use:

```bash
php artisan mos:promote-competitor-opportunity-signals
```

The command is dry-run by default. `--apply` persists canonical signals. `--workspace=`, `--site=`, `--source-id=` and `--limit=` provide safe filters.

Promotion preserves the legacy source model/id, workspace and site context, competitor reference, topic, attackable angle, query intent, funnel stage, recommended format, priority, impact, confidence, effort, competitor evidence, Argusly coverage and normalized payload. The canonical signal uses `competitor_intelligence` as its source and stores action hints in metadata.

Dedupe is based on workspace, source type, source model and source id. This makes the promotion idempotent while preserving the legacy record as the evidence source. Records missing workspace, site, competitor or topic/title context are skipped with explicit reasons.

Validate promoted signals with:

```bash
php artisan mos:validate-competitor-opportunity-signals
```

This command is read-only and reports promoted competitor signal counts, eligibility for canonical opportunity intelligence, linked versus unclustered signals, incomplete signals, duplicate signals and stale source references. It accepts `--workspace=`, `--site=`, `--source-id=` and `--limit=`. Because `OpportunityIntelligenceEngine` does not expose a dry-run mode, this command does not run a fake dry-run or create opportunities.

Promoted competitor signals are considered eligible when they have an existing workspace, existing site, competitor source model/id, dedupe hash, topic or evidence title, competitor/entity context, non-empty evidence payload, valid canonical category and `competitor_intelligence` source. Invalid signals must be reported and fixed at the source; validators and commands should not infer or repair missing context.

The promotion service does not create `Opportunity` records. Canonical opportunity creation or refresh happens later through `OpportunityIntelligenceEngine` and its normal signal clustering path. The canonical engine consumes promoted competitor signals through `OpportunitySignal`, links them through `opportunity_signal_links` and records contributing competitor source ids in opportunity metadata. `ContentOpportunity`, `AgenticMarketingOpportunity` and competitor intelligence screens remain legacy consumers until their own migration phases.

Before `ContentOpportunity` consolidation, keep these blockers visible: content opportunity UI and brief flows still depend on `content_opportunities`, run metrics still reference legacy candidates and execution consumers need stable canonical opportunity links.

## ContentOpportunity canonical links

Phase 2C introduces `App\Services\Mos\Opportunity\ContentOpportunityCanonicalLinkService` as an additive bridge layer. It does not make the provider mutable; `ContentOpportunityProvider` remains a read-only adapter that returns `CanonicalOpportunityCandidate` payloads. The link service is the only Phase 2C write path and it must be called with apply intent before it creates or links canonical `Opportunity` rows.

The link service:

- validates `CanonicalOpportunityCandidate::canPersistCanonically()`;
- requires workspace context, title/type/status, a stable dedupe key and evidence or reasoning;
- finds existing canonical opportunities by `content_opportunity_id` first;
- finds existing unbridged canonical opportunities by a source-scoped dedupe hash second;
- links unbridged dedupe hits by writing `opportunities.content_opportunity_id`;
- reports duplicates when a dedupe hit already points at another content opportunity;
- preserves source model/id, source signals, recommended actions, workspace/site context, scores, legacy status and legacy expected impact in canonical fields, evidence, source signal summary and metadata.

The dry-run command is:

```bash
php artisan mos:link-content-opportunities
```

Use `--apply` to persist, with optional `--workspace=`, `--site=`, `--source-id=`, `--status=`, `--min-priority=` and `--limit=` filters. Dry run is always the default.

## ContentOpportunity canonical dual-read

Phase 2D adds a read layer beside the provider contract: `App\Services\Mos\Opportunity\ContentOpportunityCanonicalReadService`.

This service is not a provider adapter and does not create candidates. It accepts a legacy `ContentOpportunity`, reads a linked canonical `Opportunity` through `opportunities.content_opportunity_id`, and returns `ContentOpportunityCanonicalReadModel` with normalized fields, legacy/canonical ids and field provenance.

Dual-read consumers now:

- Campaign cluster planning snapshots.
- Agentic shared marketing context.

Consumers remaining legacy:

- Brief creation.
- Lifecycle/status transitions.
- Content opportunity generation and run metrics.
- Growth orchestration and autopilot queues.
- Recommended-action mapping.

Fallback rules:

- Legacy row selection stays unchanged and uses legacy workspace/site/status filters.
- Canonical values can enrich title, scores, recommended actions and evidence when present.
- Legacy values remain authoritative for content-specific type, lifecycle status and downstream `content_opportunity_id`.
- Missing canonical values fall back to legacy fields without failing the consumer.

Provenance rules:

- Important fields expose `canonical` or `legacy` provenance.
- Lifecycle fields are marked `legacy` in Phase 2D even when a linked canonical row exists.
- Consumers may include provenance in context/snapshots, but must not mutate records based on provenance.

## AgenticMarketingOpportunity Phase 3A contract notes

Phase 3A audits `AgenticMarketingOpportunity` consumers without changing the provider contract or adding persistence. The full consumer map lives in `docs/mos/agentic-marketing-opportunity-consumer-audit.md`.

`AgenticMarketingOpportunityProvider` remains a read-only adapter:

- It may normalize legacy Agentic rows into `CanonicalOpportunityCandidate` for inspection.
- It may report that signal payloads are conceptually possible.
- It must not create `Opportunity`, `OpportunitySignal`, actions, execution pipelines or growth assets.
- It must not call Agentic detectors, action planners or execution services.

Provider contract implications from Phase 3A:

- Most Agentic detector outputs are signal-like and should be mapped to `OpportunitySignal` before canonical `Opportunity` creation.
- Some materialized outputs, especially campaign-cluster and content-network work, may need both `OpportunitySignal` and canonical `Opportunity` only after a source-scoped dedupe policy exists.
- Canonical `Opportunity` should own strategic identity, score, evidence, recommendation and high-level lifecycle only after lifecycle mapping is explicit.
- Agentic objectives, actions, action runs, run items, audit logs, execution pipelines, assets, approvals, feedback and rollback snapshots remain specialized execution state.
- `opportunities.agentic_marketing_opportunity_id` is a bridge for traceability, not an execution parent replacement in Phase 3A.

Required before any Agentic writer phase:

- detector-by-detector output classification: `OpportunitySignal`, canonical `Opportunity`, both or execution-only;
- required workspace/site/content/objective context for each output class;
- source-scoped canonical dedupe and duplicate-action prevention;
- lifecycle map for `open`, `dismissed` and `completed`;
- dry-run diagnostics for existing Agentic rows and bridge eligibility;
- proof that autonomous workflow, campaign-cluster materialization, growth assets and programmatic detection cannot create duplicate work.

## AgenticMarketingOpportunity Phase 3J lifecycle and action ownership

Phase 3J adds diagnostics beside the provider contract; it does not make the provider mutable. `AgenticOpportunityLifecycleMap`, `AgenticOpportunityLifecycleInspectionService` and `AgenticOpportunityCanonicalActionOwnershipPlanner` map lifecycle candidates and describe future canonical action ownership without changing action creation, planner selection or execution parentage.

No Agentic status is sync-safe yet. `open` can mean executable input, `dismissed` can coexist with actions or pipelines, and `completed` can mean action completion, pipeline completion or simply no longer open. Canonical recommended actions remain blocked until Phase 3H signatures, Phase 3I continuity and Phase 3J lifecycle ambiguity are all clear for a scoped migration.

## AgenticMarketingOpportunity Phase 3K execution metadata

Phase 3K still does not make the provider a writer. It adds `AgenticExecutionCanonicalMetadataResolver` and guarded future-row integration behind `features.mos_agentic_execution_canonical_metadata_writer`.

The metadata writer never creates canonical opportunities or recommended actions. It only copies safe linked canonical ids into additive execution metadata for rows created after the flag is enabled. Historical backfill is intentionally unsupported.

## AgenticMarketingOpportunity Phase 3L planner readiness

Phase 3L still does not make the provider a planner source or writer. `AgenticPlannerReadinessInspectionService` and `mos:inspect-agentic-planner-readiness` inspect linked canonical context, Phase 3H signatures, Phase 3I continuity, Phase 3J lifecycle ambiguity, Phase 3K metadata availability and duplicate action risk.

Readiness statuses are diagnostic: `legacy_only`, `canonical_context_available`, `metadata_ready_only`, `planner_candidate_blocked` and `planner_candidate_ready_for_guarded_experiment`. The current planner keeps using legacy Agentic opportunities, and provider output must not be used to create canonical recommended actions or Agentic actions from canonical `Opportunity` rows.

## AgenticMarketingOpportunity Phase 3B mapping contract

Phase 3B satisfies the detector-output classification prerequisite without changing provider mutability. `AgenticMarketingOpportunityProvider` remains a read-only provider adapter. The new Agentic detector mapping service sits beside the provider contract and returns diagnostics-only preview DTOs.

Read-only mapping components:

- `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService`
- `AgenticCanonicalSignalPreview`
- `AgenticCanonicalOpportunityPreview`
- `AgenticCanonicalMappingResult`
- `php artisan mos:map-agentic-detector-outputs`

Provider contract implications:

- Providers still must not create `Opportunity` or `OpportunitySignal` rows.
- The mapping service may preview canonical signal and opportunity candidate payloads from `DetectedOpportunity` and existing `AgenticMarketingOpportunity` rows.
- The diagnostics command may inspect existing Agentic rows and deterministic samples, but must not run live detectors.
- Missing context is reported through `missing_context` and `blocked_reasons`; it is not inferred from unrelated rows.
- `signal_only` outputs are future `OpportunitySignal` inputs, not direct canonical opportunity writers.
- `signal_and_opportunity` outputs are eligible for both previews only when stable cluster/campaign context and source-scoped dedupe exist.
- Unknown detectors are `blocked` until explicitly classified.

Phase 3B detector classifications:

| Detector/output | Classification |
| --- | --- |
| `refresh_lifecycle` | `signal_only` |
| `internal_links` | `signal_only` |
| `localization_gaps` | `signal_only` |
| `structured_answer_gaps` | `signal_only` |
| `seo_indexability` | `signal_only` |
| `ai_visibility_gaps` | `signal_only` |
| `llm_tracking_ai_visibility` | `signal_only` |
| `content_network_gaps` | `signal_and_opportunity` |
| `campaign_cluster_action_materializer` | `signal_and_opportunity` |

The dedupe contract is versioned as `agentic-detector-output:v1` and source scoped by workspace, objective, detector, Agentic type, site, content, locale, normalized topic/title and stable payload identity. Timestamp-style fields and score refreshes do not change the dedupe key. The detailed contract is `docs/mos/agentic-detector-canonical-mapping.md`.

## AgenticMarketingOpportunity Phase 3C bridge eligibility

Phase 3C keeps providers and mapping services read-only and adds diagnostics beside them:

- `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityService`
- `AgenticOpportunityBridgeEligibilityResult`
- `php artisan mos:inspect-agentic-opportunity-bridges`

The bridge service accepts an existing `AgenticMarketingOpportunity`, reuses the Phase 3B mapping result, and reports whether the row is `signal_ready`, `canonical_link_ready`, `signal_and_canonical_ready`, `execution_blocked`, `missing_context`, `duplicate_risk` or `blocked`.

Provider contract implications:

- Existing providers still must not create or update canonical `Opportunity` rows.
- Phase 3C does not create `OpportunitySignal` rows.
- `opportunities.agentic_marketing_opportunity_id` remains passive; it is inspected but not backfilled.
- Missing workspace, objective, type, title or dedupe context blocks all migration readiness.
- Duplicate canonical bridge links and source-scoped dedupe matches block canonical linking.
- Open Agentic actions, execution pipelines, growth assets and programmatic opportunity references are execution-state dependencies. They do not block future signal promotion, but they block canonical writer phases until legacy id continuity is planned.

## AgenticMarketingOpportunity Phase 3H action dedupe contract

Phase 3H keeps `AgenticMarketingOpportunityProvider` read-only and adds action-dedupe diagnostics outside the provider contract:

- `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionSignatureService`
- `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionDedupeInspectionService`
- `php artisan mos:inspect-agentic-action-dedupe`

Provider contract implications:

- Providers still must not create or update Agentic actions.
- Canonical providers must not create canonical recommended actions for linked Agentic rows by default.
- Canonical-linked Agentic action signatures require workspace id, objective id, legacy Agentic opportunity id, detector key, Agentic type and action type.
- Missing required context is reported as blocked; it is not inferred.
- Linked canonical `Opportunity` rows resolve through `opportunities.agentic_marketing_opportunity_id` so the canonical and legacy sources share one canonical-equivalent signature.
- The default Agentic planner remains the only default action creation path and keeps `agentic_marketing_opportunities.id` as execution identity.

Use the command with `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=` to inspect bounded sets of existing Agentic rows. See `docs/mos/agentic-opportunity-bridge-eligibility.md` for the full diagnostic contract.

## Guardrails

- Do not create a second opportunity persistence flow.
- Do not migrate legacy rows in provider adapters.
- Do not delete legacy tables while consumers still depend on them.
- Do not call legacy engines from adapters.
- Keep adapters read-only and deterministic.
- Treat missing context as a blocker, not as inferred data.
- Keep promotion services explicit, dry-run capable and idempotent.
- Keep Phase 2D dual-read services read-only; lifecycle and brief creation stay legacy until Phase 2E blockers are resolved.

## ContentOpportunity lifecycle diagnostics and brief handoff planning

Phase 2E keeps providers read-only and does not add provider persistence. The new lifecycle and handoff services sit beside the existing content opportunity bridge:

- `ContentOpportunityLifecycleMap` defines the explicit legacy-to-canonical lifecycle map and safe reverse map.
- `mos:compare-content-opportunity-lifecycle` compares linked rows and reports aligned records, conflicts, unmapped statuses and missing canonical links.
- `ContentOpportunityBriefHandoffPlanner` builds a dry-run payload for a future canonical brief action.
- `mos:plan-content-opportunity-brief-handoff` reports safe and blocked future handoffs.

These services do not replace `ContentOpportunityProvider`, do not update `Opportunity`, do not update `ContentOpportunity`, do not create `Brief` records and do not alter route/controller/UI behaviour.

Provider contract implications:

- Lifecycle authority is still legacy in Phase 2E.
- Canonical statuses `reviewing`, `approved`, `actioned` and `resolved` cannot be safely projected back to the legacy content opportunity lifecycle yet.
- Brief handoff requires a linked canonical opportunity, target site context, title, primary keyword and source evidence before it can be considered safe.
- Missing context must be reported, not inferred.

## ContentOpportunity recommended action dedupe

Phase 2F adds canonical-aware source signatures for linked content opportunity recommended actions without changing the provider contract. Providers remain read-only; action projection still flows through the existing `RecommendedActionMapper` and `RecommendedActionEngine`.

`ContentOpportunityRecommendedActionSignature` defines the shared signature strategy for linked `ContentOpportunity` and canonical `Opportunity` records. For linked content opportunities the canonical-equivalent signature includes the legacy id, canonical id when linked, workspace/site context, normalized action type, bridge source model/source id and dedupe key. Legacy `prepare_content_opportunity` and canonical `review_opportunity` actions normalize to `content_opportunity_review` for signature purposes.

Use:

```bash
php artisan mos:dedupe-content-opportunity-actions
```

The command is dry-run diagnostics by default and reports duplicate counts, safe candidate counts and skipped reasons. It supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.

`--apply` was intentionally diagnostics-only in Phase 2F. Phase 2M adds a safe metadata-only writer through `ContentOpportunityRecommendedActionRepairService`. The existing nullable `recommended_actions.metadata` JSON column stores `canonical_equivalence` repair metadata while `source_signature`, visible source links, status, CTA fields and lifecycle state remain unchanged.

Phase 2M repair metadata contains the canonical-equivalent signature, legacy content opportunity id, canonical opportunity id, duplicate group id, duplicate role (`primary` or `duplicate`), repair status, repair timestamp, repair actor and reason. Primary selection is deterministic: open action first, then oldest action, then legacy source during the transition, then id.

With `--apply`, `php artisan mos:dedupe-content-opportunity-actions` annotates duplicate groups only. It never hard deletes actions, dismisses or hides actions, rewrites source signatures, changes lifecycle ownership, creates briefs, changes routes or moves growth/autopilot execution. Rollback is metadata-only by removing `metadata.canonical_equivalence` or restoring the previous JSON value.

Remaining blockers before moving lifecycle or brief ownership:

- Canonical brief action ownership and CTA/source-link semantics.
- Growth/autopilot execution semantics for canonical opportunities.

## ContentOpportunity growth and autopilot handoff planning

Phase 2H adds diagnostics beside the provider contract. Providers remain read-only adapters and do not create growth assets, queue items or programmatic expansion rows.

`ContentOpportunityGrowthHandoffPlanner` reports:

- legacy content opportunity id;
- linked canonical opportunity id or duplicate canonical links;
- existing `GrowthAsset` references for legacy and canonical sources;
- existing `ProgrammaticOpportunity` references for legacy and canonical sources;
- existing `GrowthAutopilotQueueItem` references for legacy and canonical sources;
- duplicate execution risks;
- missing fields;
- future canonical reference strategy.

Use:

```bash
php artisan mos:plan-content-opportunity-growth-handoff
```

The command is read-only and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.

Provider contract implications:

- `GrowthAsset` role `content_opportunity` remains a legacy role and should not be rewritten by provider adapters.
- Canonical `Opportunity` may become the primary growth source later, but the legacy `content_opportunity_id` must remain available for traceability.
- Programmatic opportunity providers must not flatten specialized programmatic state into canonical opportunity payloads.
- Autopilot migration must dedupe through canonical-equivalent recommended action signatures before any queue writer moves to canonical sources.
- Missing links and dual legacy/canonical references are diagnostics, not repair actions, in Phase 2H.

## ContentOpportunity canonical brief action planning

Phase 2I keeps providers read-only and keeps production brief creation on `AppContentOpportunityController::createBrief`. The planning service sits beside the provider contract and does not create briefs or update lifecycle state.

`ContentOpportunityCanonicalBriefActionPlanner` / `ContentOpportunityBriefHandoffPlanner` reports:

- canonical opportunity id;
- legacy content opportunity id;
- workspace id;
- client site id;
- proposed action type;
- proposed Phase 2F-compatible source signature;
- proposed CTA label and route;
- proposed legacy source link;
- proposed brief title;
- primary keyword, audience, funnel stage and intent;
- source evidence and recommended actions;
- legacy fields required by the current brief creation path;
- missing fields, safety status and reason.

Use:

```bash
php artisan mos:plan-content-opportunity-brief-actions
```

The command is read-only and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.

Provider contract implications:

- Provider adapters still do not create canonical opportunities or briefs.
- Canonical `Opportunity` becomes the future brief action owner only after a controlled writer is added.
- Legacy `content_opportunity_id` must remain traceable in future brief metadata/client refs.
- CTA/source-link migration is semantic only in Phase 2I; visible production CTA behaviour is unchanged.
- Missing site, title, primary keyword, source evidence or canonical link blocks migration readiness and must be reported rather than inferred.

Remaining blockers before moving production brief creation:

- Safe duplicate recommended-action repair strategy.
- Route/UI migration for visible CTAs.
- Lifecycle ownership migration.
- Growth/autopilot writer readiness and the documented `GrowthProgramCoreTest` failure.

## ContentOpportunity canonical brief guarded writer

Phase 2J adds `ContentOpportunityCanonicalBriefWriter` and `mos:create-canonical-content-opportunity-brief`. This is a guarded writer boundary, not a provider adapter and not a production route migration.

The writer contract requires:

- linked canonical `Opportunity`;
- linked legacy `ContentOpportunity`;
- target `ClientSite`;
- mode `single` or `chained`;
- optional operator context;
- explicit dry-run or apply intent.

The writer creates legacy-compatible `Brief` records only on apply. It stores `client_refs.content_opportunity_id`, `client_refs.canonical_opportunity_id`, `client_refs.mode`, a deterministic `client_refs.source_signature` and the existing nested legacy `content_opportunity` reference.

Provider contract implications:

- Providers still must not create briefs or mutate opportunity lifecycle state.
- Canonical brief creation is available only through the guarded writer/command path.
- Dry-run is the default command behaviour and must remain safe for production inspection.
- Apply blocks missing canonical links, missing site/title/keyword/evidence, link mismatches and duplicate brief risk.
- Default apply does not update `ContentOpportunity.status` or `Opportunity.status`; lifecycle ownership remains a later phase.

## ContentOpportunity visible route migration boundary

Phase 2K adds an application-controller boundary over the guarded writer without changing provider responsibilities.

- Feature flag: `features.mos_canonical_content_opportunity_brief_writer`, backed by `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_BRIEF_WRITER`, default `false`.
- Route owner remains `AppContentOpportunityController::createBrief`; providers are still read/model contract surfaces only.
- With the flag disabled, the route uses only the legacy brief path.
- With the flag enabled, the route may call `ContentOpportunityCanonicalBriefWriter` for linked and dry-run-safe records.
- Unlinked or unsafe records fall back to the legacy route behavior. Duplicate canonical brief risk reuses the existing brief rather than creating another.
- The route preserves existing redirects and flash messages.
- The route may mark the legacy `ContentOpportunity` planned to preserve visible legacy behavior, but it must not update canonical `Opportunity.status` in this phase.

This keeps provider contracts stable while allowing a reversible route rollout. Canonical lifecycle ownership, recommended-action repair and growth/autopilot writers remain outside the Phase 2K boundary.

## ContentOpportunity canonical lifecycle sync

Phase 2L keeps provider adapters read-only and places lifecycle synchronization in a dedicated service/command boundary.

`ContentOpportunityCanonicalLifecycleSyncService` accepts a legacy `ContentOpportunity`, a linked canonical `Opportunity`, an explicit direction, dry-run/apply intent and optional actor context. It uses `ContentOpportunityLifecycleMap`, validates linked pair integrity and returns a structured result. It does not infer links, repair duplicates or force canonical-only states into legacy statuses.

Use:

```bash
php artisan mos:sync-content-opportunity-lifecycle
php artisan mos:sync-content-opportunity-lifecycle --apply --direction=canonical-to-legacy
```

Provider contract implications:

- Providers still do not update `ContentOpportunity.status` or `Opportunity.status`.
- Legacy status remains the content opportunity UI authority in this phase.
- Canonical status can be synchronized for safe linked rows only by explicit command apply.
- Reverse sync is safe only for canonical `open`, `planned`, `dismissed` and `archived`.
- Canonical `reviewing`, `approved`, `actioned` and `resolved` must be reported as blocked canonical-only states.
- `features.mos_canonical_content_opportunity_lifecycle_sync` defaults to `false`; app-flow sync is not enabled in Phase 2L.

## ContentOpportunityRun canonical references

Phase 2G adds run-level canonical reference reporting outside the provider contract. Providers remain read-only adapters; this phase does not create canonical candidates, persist opportunities or move lifecycle ownership.

`ContentOpportunityRunCanonicalReferenceService` accepts a legacy `ContentOpportunityRun`, reads its associated `ContentOpportunity` rows and finds linked canonical `Opportunity` rows by `opportunities.content_opportunity_id`. It reports linked and unlinked candidate counts, canonical ids grouped by legacy and canonical status, missing links, missing context and duplicate link risks.

Use:

```bash
php artisan mos:inspect-content-opportunity-run-links
```

The command is read-only by default and supports `--workspace=`, `--site=`, `--run-id=`, `--status=`, `--limit=` and optional `--write-summary`.

Storage rule:

- The existing `content_opportunity_runs.result` JSON field can store `canonical_reference_summary` only when `--write-summary` is explicitly used.
- The summary is additive metadata and never replaces `result.opportunity_ids`, `candidates_count`, `created_count` or `refreshed_count`.
- No migration is added in Phase 2G. If canonical run references need indexed querying later, add dedicated storage then.

Provider contract implications:

- Run metrics remain legacy-owned while the content opportunity engine exists.
- Canonical references can be surfaced in diagnostics and future additive run annotations.
- Missing links or duplicate links must be reported, not repaired by this service.

Remaining blockers before moving lifecycle or brief ownership:

- Safe repair strategy for already-created duplicate action rows.
- Canonical brief action ownership and CTA/source-link semantics.
- Growth/autopilot execution semantics for canonical opportunities.

## ContentOpportunity canonical growth and autopilot writers

Phase 2N adds guarded writer services outside provider adapters. Providers remain read-only normalization surfaces and must not create growth assets, queue items, recommended actions or programmatic lifecycle transitions.

Writer services:

- `ContentOpportunityCanonicalGrowthAssetWriter` accepts a legacy `ContentOpportunity`, linked canonical `Opportunity` and explicit target `GrowthProgram`. It dry-runs by default, blocks mismatched links/workspaces, blocks same-program legacy/canonical duplicate risks and creates only additive canonical `GrowthAsset` references on flagged apply.
- `ContentOpportunityCanonicalAutopilotQueueWriter` accepts a legacy `ContentOpportunity` and linked canonical `Opportunity`. It derives the canonical recommended action payload, computes the canonical-equivalent queue signature, blocks duplicate queue work and uses the existing `GrowthAutopilotQueueBuilder` upsert path on flagged apply.

Commands:

```bash
php artisan mos:write-content-opportunity-growth-assets
php artisan mos:write-content-opportunity-autopilot-queue
```

Both commands are dry-run-first and support `--apply`, `--workspace=`, `--site=`, `--source-id=`, `--opportunity-id=`, `--growth-program=` and `--limit=`.

Provider contract implications:

- Provider adapters still do not mutate `ContentOpportunity`, `Opportunity`, `GrowthAsset`, `GrowthAutopilotQueueItem`, `RecommendedAction` or `ProgrammaticOpportunity`.
- Legacy growth assets remain valid traceability records.
- Canonical writer metadata must preserve legacy `content_opportunity_id` and canonical `opportunity_id`.
- Duplicate prevention relies on linked source references and canonical-equivalent recommended action signatures, not provider output.
- Feature flags default off: `features.mos_canonical_content_opportunity_growth_writer` and `features.mos_canonical_content_opportunity_autopilot_writer`.

Remaining blockers:

- Default app-flow growth/autopilot ownership is not migrated.
- Programmatic opportunities need a future source-reference strategy before lifecycle migration.
- Visible action CTA/source-link ownership remains separate from provider contracts.

## ContentOpportunity canonical action ownership resolver

Phase 2O adds action ownership resolution outside provider adapters. Providers remain read-only normalization surfaces and must not create recommended actions, switch visible CTAs, update lifecycle status or delete legacy rows.

Resolver contract:

- `ContentOpportunityCanonicalActionOwnershipResolver` accepts a legacy `ContentOpportunity`, optional linked canonical `Opportunity`, optional recommended action rows, optional duplicate repair metadata and feature flag state.
- It returns canonical owner id, legacy source id, primary/duplicate/display recommended action ids, CTA route, source link, ownership status, blocked reasons and fallback route.
- It performs no writes.

Mapper contract:

- `RecommendedActionMapper` remains the only recommended-action projection mapper.
- With `features.mos_canonical_content_opportunity_action_ownership=false`, legacy content opportunity action output remains on the legacy CTA route and emits no ownership metadata.
- With the flag enabled and resolver status `canonical-active`, the legacy action may use the canonical `app.opportunities.show` CTA while preserving legacy traceability in `metadata.canonical_action_ownership`.
- Action source signatures continue to use `ContentOpportunityRecommendedActionSignature`.

Command:

```bash
php artisan mos:inspect-content-opportunity-action-ownership
```

The command is read-only and reports ownership status, duplicate metadata status, proposed CTA route, fallback route and blockers. It supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.

Provider contract implications:

- Providers do not own CTA/source-link migration.
- Canonical action ownership is a resolver/mapper concern guarded by a default-off feature flag.
- Duplicate repair metadata is respected for display/action diagnostics, but duplicate actions are not removed or status-mutated.
- Rollback is flag-only because legacy routes and legacy recommended actions remain intact.

## AgenticMarketingOpportunity guarded bridge writer

Phase 3D adds a writer outside provider adapters: `AgenticOpportunityBridgeWriter`.

Provider contract implications:

- `AgenticMarketingOpportunityProvider` remains read-only.
- Agentic detector mapping remains read-only.
- The writer is invoked only by `php artisan mos:link-agentic-opportunities`; no default app flow calls it.
- Dry-run is allowed with `features.mos_agentic_marketing_opportunity_bridge_writer=false`.
- Apply requires both explicit `--apply` intent and `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER=true`.
- The writer may create a canonical `Opportunity` and set `opportunities.agentic_marketing_opportunity_id`.
- The writer must not update legacy Agentic opportunities, Agentic actions, action runs, execution pipelines, growth/programmatic execution state or `OpportunitySignal` rows.

Rollback:

- Disable the feature flag and stop invoking the command.
- If no downstream canonical consumers were enabled, bridge values created by this writer may be cleared selectively.
- Legacy Agentic rows and execution records must not be deleted as rollback.

## AgenticMarketingOpportunity signal validation

Phase 3F adds diagnostics outside provider adapters:

- `AgenticOpportunitySignalValidationService`;
- `php artisan mos:validate-agentic-opportunity-signals`.

Provider contract implications:

- `AgenticMarketingOpportunityProvider` remains read-only and still does not create `Opportunity`, `OpportunitySignal`, recommended actions or execution state.
- Signal validation reads Phase 3E promoted `OpportunitySignal` rows and reports eligibility, linked/unlinked status, stale source risk, duplicate signal risk and completeness.
- Validation does not call detectors, does not call action planning, does not invoke bridge writing and does not run the canonical opportunity engine.
- Canonical opportunity creation remains owned by the existing `OpportunityIntelligenceEngine`.
- The engine may preserve Agentic legacy ids in opportunity metadata after its normal clustering path links promoted signals; this is traceability, not provider-owned persistence.

Remaining blockers:

- Provider adapters should not gain Agentic canonical writers.
- Dual-read must define selection and action-dedupe semantics before any default app flow reads canonical-linked Agentic opportunities as execution inputs.

## AgenticMarketingOpportunity canonical read model

Phase 3G adds read-only dual-read support outside provider adapters:

- `AgenticOpportunityCanonicalReadService`;
- `AgenticOpportunityCanonicalReadModel`;
- `php artisan mos:inspect-agentic-canonical-read-model`.

Provider contract implications:

- `AgenticMarketingOpportunityProvider` remains a read-only normalization provider and does not own dual-read selection, action planning, lifecycle updates or execution references.
- The Phase 3G read model may prefer linked canonical strategic fields for selected displays, but legacy Agentic ids, status, type, action-planning context and execution state remain authoritative.
- Canonical id is additive metadata and must not replace legacy route ids or queue/action payload ids.
- Field provenance is required for migrated consumers so rollback and fallback behavior stay visible.

Consumers that mutate, dispatch, approve, select autonomous work or materialize actions must remain on legacy Agentic rows until a later provider/ownership contract defines selection order and duplicate prevention.

## AgenticMarketingOpportunity execution continuity diagnostics

Phase 3I adds read-only execution continuity diagnostics outside provider adapters:

- `AgenticOpportunityExecutionContinuityService`;
- `php artisan mos:inspect-agentic-execution-continuity`.

Provider contract implications:

- `AgenticMarketingOpportunityProvider` remains read-only and does not own execution parent migration.
- Legacy Agentic opportunity ids remain authoritative for actions, action runs, execution pipelines, generated assets and execution routes.
- Approval, feedback and execution audit rows remain pipeline-local.
- Canonical opportunity ids may be exposed as additive future metadata candidates only; providers must not rewrite existing action payloads, asset payloads, action-run snapshots or rollback snapshots.
- Missing canonical bridges, duplicate canonical bridges, missing execution payload fields and canonical-parent-only lookup gaps are blockers, not provider-repair instructions.

Future provider changes must wait for a separate guarded writer/ownership contract that covers route binding, approval gates, rollback semantics, generated asset compatibility and lifecycle mapping.

## AgenticMarketingOpportunity planner experiment

Phase 3M adds a planner comparison contract outside provider adapters:

- `AgenticCanonicalPlannerExperimentService`;
- `AgenticCanonicalPlannerDryRunAdapter`;
- `php artisan mos:compare-agentic-planner-candidates`.

Provider contract implications:

- `AgenticMarketingOpportunityProvider` remains read-only and does not select planner candidates.
- The default planner still reads open legacy Agentic rows by legacy priority.
- Canonical-linked rows can enter the experiment order only when Phase 3L reports full readiness.
- The experiment does not create canonical `Opportunity` rows, recommended actions, Agentic actions, run items or audit logs.
- The feature flag `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false` defaults off and is rollback for future scoped dry-runs.

Future Phase 3N provider changes must wait until duplicate action risk, signature equivalence, execution continuity and lifecycle ownership are proven for the selected scope.

## AgenticMarketingOpportunity planner apply experiment

Phase 3N remains outside provider ownership:

- `AgenticMarketingOpportunityProvider` stays read-only and does not create actions.
- `mos:apply-agentic-planner-canonical-experiment` is the only Phase 3N apply entrypoint.
- The command selects only Phase 3L-ready canonical-linked rows, then resolves back to the legacy Agentic opportunity.
- Action creation/reuse remains owned by `AgenticMarketingActionPlanner`.
- Canonical `Opportunity` ids are metadata only in `AgenticMarketingAction.payload.planner_experiment`.

The provider contract still forbids canonical recommended-action creation, lifecycle sync, route migration, historical payload rewrites and execution parent migration. Rollback is disabling `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT`.

## AgenticMarketingOpportunity Phase 3O audit boundary

Phase 3O remains outside provider write ownership. The audit and rollback-plan commands inspect `AgenticMarketingAction.payload.planner_experiment` and linked canonical bridges, but they do not ask any MOS provider to create canonical recommended actions, change lifecycle state or move execution parents.

Provider compatibility for a future Phase 3P shadow rollout requires Phase 3O to show no missing parent, missing canonical context, bridge mismatch, signature mismatch, duplicate risk, continuity risk or lifecycle conflict in scope.

## AgenticMarketingOpportunity Phase 3P shadow diagnostics

Phase 3P remains outside provider write ownership. `AgenticCanonicalPlannerShadowService` and `mos:shadow-agentic-planner-candidates` compare legacy candidates, Phase 3M canonical candidates, Phase 3L readiness and Phase 3O audit risk without changing provider output.

The provider contract stays read-only: no canonical recommended actions are created, no legacy payloads are rewritten, no lifecycle sync runs, and `AgenticMarketingOpportunity` remains the execution parent.

## AgenticMarketingOpportunity Phase 3Q default-selection preview

Phase 3Q also remains outside provider write ownership. `AgenticCanonicalPlannerDefaultSelectionPreviewService` composes Phase 3P shadow output, Phase 3O audit status, Phase 3L readiness, Phase 3H signatures, Phase 3I continuity, Phase 3J lifecycle and duplicate risk, but it does not ask MOS providers to select planner rows or write canonical actions.

Provider compatibility for Phase 3R requires Phase 3Q `preview_safe`: sufficient canonical coverage, exact legacy/canonical order match, no audit/signature/continuity/lifecycle/duplicate risk and `metadata_only_ok` treated as traceability only.

## Phase 3S Provider Compatibility

Phase 3S does not expand provider write ownership. It audits Phase 3R actions after apply and confirms that providers still supply canonical context only while `AgenticMarketingAction` rows remain owned by legacy Agentic opportunities.

Before Phase 3T, providers must show no Phase 3S missing legacy parent, missing canonical context, bridge mismatch, preview regression, shadow regression, Phase 3O audit risk, readiness regression, signature mismatch, continuity risk, lifecycle risk, duplicate risk or ownership risk. Canonical recommended actions remain blocked.

## Phase 3T Provider Contract

Phase 3T uses provider output as read-only canonical context for rollout readiness. Providers do not become planner writers, and canonical `Opportunity` rows do not become `AgenticMarketingAction` parents.

Phase 3U prerequisites include explicit scope, clean Phase 3S/3O audits, Phase 3Q preview safety, Phase 3P shadow agreement, Phase 3L readiness, Phase 3H/3I/3J safety, duplicate-risk zero, sufficient coverage and exact order parity.
