# ContentOpportunity Consumer Audit

Phase 2C prepares selected `ContentOpportunity` rows for canonical MOS linking without moving lifecycle ownership, replacing screens, changing brief creation or deleting `content_opportunities`.

Phase 2D starts safe canonical dual-read for selected read-only consumers. Legacy `ContentOpportunity` rows still own lifecycle state, brief creation, generation, run metrics and destructive actions.

## Consumer Inventory

| Consumer | Location | Classification | Notes for Phase 2C | Phase 2D migration | Phase 2D rationale |
| --- | --- | --- | --- | --- | --- |
| Content opportunity app routes | `routes/app.php` | read-only display, lifecycle update, brief creation | Existing index, run and brief creation routes continue to target `ContentOpportunity` directly. | keep legacy | Routes expose brief creation and status-changing actions, so they remain legacy-owned. |
| Content opportunity controller | `app/Http/Controllers/App/AppContentOpportunityController.php` | read-only display, lifecycle update, brief creation, analytics/reporting | Lists opportunities and runs, computes summary counts, creates briefs and marks legacy opportunities `planned`. Must move carefully in Phase 2D. | keep legacy | Mixed read/write controller; brief creation and `planned` transition must not dual-read yet. |
| Content opportunity run request | `app/Http/Requests/App/RunContentOpportunityEngineRequest.php` | execution | Validates engine run inputs for workspace/site context. No canonical dependency. | keep legacy | Validation for legacy engine execution has no canonical read responsibility. |
| Content opportunity generation job | `app/Jobs/ContentOpportunityEngine/GenerateContentOpportunitiesJob.php` | execution | Queues engine runs and persists legacy candidates through the existing engine. | keep legacy | Generation still creates and refreshes legacy rows. |
| Content opportunity model | `app/Models/ContentOpportunity.php` | lifecycle update, legacy compatibility | Operational record for status, scoring, evidence, payload and context. Remains lifecycle owner in Phase 2C. | keep legacy | Lifecycle ownership intentionally remains on `content_opportunities`. |
| Content opportunity run model | `app/Models/ContentOpportunityRun.php` | analytics/reporting, legacy compatibility | Run ledger and counts remain tied to legacy rows. | keep legacy | Run metrics need a canonical reference strategy before migration. |
| Content opportunity engine | `app/Services/ContentOpportunityEngine/ContentOpportunityEngine.php` | scoring/recommendation, lifecycle update, execution | Creates/refreshes legacy opportunities via workspace/dedupe hash and records run metrics. Not changed by linking. | keep legacy | Engine persists candidates and status/freshness data. |
| Candidate generator and DTO | `app/Services/ContentOpportunityEngine/ContentOpportunityCandidateGenerator.php`, `app/Services/ContentOpportunityEngine/ContentOpportunityCandidate.php` | scoring/recommendation | Produces legacy content opportunity candidate data from content, competitor and company intelligence. | keep legacy | Source generation remains legacy until provider output becomes canonical-owned. |
| Dedupe service | `app/Services/ContentOpportunityEngine/ContentOpportunityDedupe.php` | legacy compatibility | Creates stable legacy dedupe hashes used by the canonical link service. | keep legacy | Dedupe remains necessary for legacy refresh and bridge linking. |
| Input builder | `app/Services/ContentOpportunityEngine/ContentOpportunityInputBuilder.php` | scoring/recommendation, execution | Supplies intelligence inputs for candidate generation. | keep legacy | Feeds legacy generation and is not a canonical consumer. |
| Internal link service | `app/Services/ContentOpportunityEngine/ContentOpportunityInternalLinkService.php` | scoring/recommendation | Produces supporting content and recommended internal links preserved as canonical evidence/references. | keep legacy | Supporting evidence remains produced by the legacy engine. |
| Lifecycle service | `app/Services/ContentOpportunityEngine/ContentOpportunityLifecycleService.php` | lifecycle update | Maintains freshness/status data for legacy records. Not moved in Phase 2C. | keep legacy | Explicit lifecycle-update surface. |
| Scoring engine | `app/Services/ContentOpportunityEngine/ContentOpportunityScoringEngine.php` | scoring/recommendation | Produces priority, confidence, urgency and business value values preserved during linking. | keep legacy | Legacy scoring remains the source for newly generated candidates. |
| Campaign cluster input builder | `app/Services/CampaignClusterEngine/CampaignClusterInputBuilder.php` | campaign planning | Reads content opportunities as planning input. Must remain legacy-compatible. | dual-read now | Read-only snapshot input now uses `ContentOpportunityCanonicalReadService` while selecting rows by legacy status and workspace/site filters. |
| Campaign cluster candidate generator | `app/Services/CampaignClusterEngine/CampaignClusterCandidateGenerator.php` | campaign planning | Stores `content_opportunity_id` on cluster items and embeds source opportunity snapshots. | dual-read now | Uses normalized snapshots for safe fields and records canonical IDs, but keeps legacy content opportunity IDs and subtype semantics. |
| Campaign cluster item model | `app/Models/CampaignClusterItem.php` | campaign planning, legacy compatibility | Belongs to `ContentOpportunity`; do not retarget in Phase 2C. | keep legacy | Relationship remains legacy so materialization and traceability are unchanged. |
| Growth program orchestrator | `app/Services/Growth/GrowthProgramOrchestrator.php` | growth program consumer, lifecycle update | Attaches `ContentOpportunity` as a `GrowthAsset` and refreshes growth metrics. | defer | Growth assets change execution/growth state and need a separate canonical attachment plan. |
| Programmatic opportunity detector | `app/Services/Growth/ProgrammaticOpportunityDetector.php` | growth program consumer, scoring/recommendation | Derives programmatic patterns and scores from content opportunities. | defer | Programmatic expansion state should consume canonical opportunities after growth ownership is clarified. |
| Growth autopilot queue builder | `app/Services/GrowthAutopilot/GrowthAutopilotQueueBuilder.php` | growth program consumer, execution | Uses opportunity-like inputs for queued growth work. Needs canonical transition planning. | defer | Queue creation is execution-facing and can duplicate recommended actions if moved too early. |
| Growth asset model | `app/Models/GrowthAsset.php` | growth program consumer, legacy compatibility | Defines `content_opportunity` asset role. | keep legacy | Asset role remains legacy until growth orchestration migrates. |
| Agentic shared context builder | `app/Services/AgenticMarketing/Orchestration/SharedMarketingContextBuilder.php` | agentic orchestration consumer, read-only display | Builds orchestration context from legacy opportunities. | dual-read now | Read-only context now includes canonical IDs and canonical-safe fields with legacy fallback. |
| Abstract marketing agent | `app/Services/AgenticMarketing/Orchestration/Agents/AbstractMarketingAgent.php` | agentic orchestration consumer | Reads shared context containing content opportunity data. | dual-read now | Agents receive the richer shared context without direct persistence or behavior changes. |
| Recommended action mapper | `app/Services/RecommendedActions/RecommendedActionMapper.php` | scoring/recommendation, execution | Maps `ContentOpportunity` into recommended action records. | canonical-aware now | Phase 2F keeps the same mapper/engine but gives linked legacy/canonical content opportunities one canonical-equivalent source signature to prevent duplicate action rows. |
| MOS content opportunity provider | `app/Services/Mos/Opportunity/Providers/ContentOpportunityProvider.php` | read-only display, legacy compatibility | Converts legacy rows to `CanonicalOpportunityCandidate` payloads only. Phase 2C link service reuses this adapter. | keep legacy | Provider remains a read-only adapter; Phase 2D read service sits beside it. |
| Opportunity model bridge | `app/Models/Opportunity.php` | legacy compatibility | Existing `content_opportunity_id` bridge from canonical opportunity to legacy row. | migrate now | Used as the bridge for `ContentOpportunityCanonicalReadService`; no lifecycle transfer. |
| Content opportunity Blade view | `resources/views/app/content-opportunities/index.blade.php` | read-only display, lifecycle update, brief creation | Primary UI remains legacy-backed. | keep legacy | UI contains status and brief actions; avoid mixed ownership. |
| Competitor intelligence view | `resources/views/app/competitor-intelligence/index.blade.php` | read-only display, scoring/recommendation | Shows competitor opportunities that feed content opportunity generation. Indirect consumer only. | keep legacy | Indirect source surface; not a direct `ContentOpportunity` migration target. |
| Content network controller/job/service | `app/Http/Controllers/App/AppContentChainController.php`, `app/Jobs/ContentNetwork/AnalyzeContentNetworkJob.php`, `app/Services/ContentChain/ChainedContentOpportunityService.php` | execution, scoring/recommendation | Handles link opportunities, not `ContentOpportunity` lifecycle; adjacent legacy opportunity surface. | blocked | Adjacent link opportunity surface is outside the content opportunity canonical bridge. |
| Tests | `tests/Feature/ContentOpportunityEngine`, `tests/Feature/AgenticMarketing`, `tests/Feature/Growth`, `tests/Feature/SignalIntelligence`, `tests/Unit/Mos` | test-only | Guard engine generation, campaign planning, agent orchestration, growth attachment and provider normalization. | migrate now | Phase 2D adds MOS read-service tests plus selected consumer regression coverage. |

## Phase 2C Linking Scope

Safe linking is additive. `ContentOpportunity` remains the operational record for status, freshness, brief creation, campaign planning, growth orchestration and agentic context.

Records are eligible when the MOS provider can create a persistable candidate, workspace context exists, a title/type/status/dedupe key exists, and there is enough reasoning or evidence to make the canonical row auditable. Site context is preserved when present but is not inferred.

Skipped reasons include missing provider support, missing required candidate fields, missing workspace context, missing stable dedupe key, missing title/topic, missing reasoning/evidence, unsupported conversion reasons and duplicate canonical records already linked to another content opportunity.

## Phase 2D Dual-Read Scope

`App\Services\Mos\Opportunity\ContentOpportunityCanonicalReadService` is the canonical-aware read layer for linked content opportunities. It accepts a legacy `ContentOpportunity`, finds a linked canonical `Opportunity` through `opportunities.content_opportunity_id`, returns a normalized read model and never creates, links, updates or deletes data.

Phase 2D dual-reads:

- Campaign cluster planning input snapshots.
- Campaign cluster source signal metadata.
- Agentic shared marketing context.

Phase 2D keeps legacy:

- Brief creation and `planned` transitions.
- Content opportunity generation, scoring and lifecycle refresh.
- Run metrics ownership.
- Content opportunity UI actions.
- Growth program attachments and autopilot queues.
- Recommended action mapping.

Fallback rules:

- Legacy ID is always preserved as `legacy_content_opportunity_id` or downstream `content_opportunity_id`.
- Canonical opportunity ID is included only when a linked `Opportunity` exists.
- Title, priority, confidence, impact, effort, urgency, business value, recommended actions and evidence may prefer canonical values when present.
- Content-specific subtype and lifecycle status stay legacy-owned in Phase 2D because canonical category/status are broader lifecycle concepts.
- Missing canonical values fall back to legacy values field by field.

Provenance rules:

- The read model exposes a `provenance` map for important fields.
- Field provenance is `canonical` only when the value came from linked `Opportunity`.
- Field provenance is `legacy` when no canonical value exists or when Phase 2D intentionally preserves legacy ownership.
- Downstream consumers may pass provenance through diagnostics/context, but must not use provenance to change lifecycle behavior.

## Phase 2E Blockers

- Define canonical lifecycle ownership for status transitions before moving UI actions.
- Define canonical brief creation handoff before changing brief routes/controllers.
- Add a run metrics strategy so `ContentOpportunityRun` can remain an audit ledger without owning candidate lifecycle.
- Design recommended-action dedupe between legacy `ContentOpportunity` and canonical `Opportunity` sources.
- Decide how growth assets and autopilot queues reference canonical opportunities without duplicating execution work.

## Phase 2E Lifecycle And Brief Handoff Design

Phase 2E defines diagnostics and dry-run handoff planning only. Production behaviour remains unchanged: `AppContentOpportunityController` still creates briefs from `ContentOpportunity`, still marks the legacy row `planned`, and the content opportunity UI/routes still read the legacy table.

Current legacy statuses:

- `open`
- `planned`
- `dismissed`
- `archived`

Current canonical `OpportunityStatus` values:

- `open`
- `reviewing`
- `approved`
- `planned`
- `actioned`
- `resolved`
- `dismissed`
- `archived`

Proposed status mapping:

| Legacy `ContentOpportunity.status` | Canonical `Opportunity.status` | Reverse mapping safe in Phase 2E? | Notes |
| --- | --- | --- | --- |
| `open` | `open` | Yes | Legacy row remains authoritative. |
| `planned` | `planned` | Yes | Existing brief creation still writes legacy `planned`. |
| `dismissed` | `dismissed` | Yes | No status transition is migrated yet. |
| `archived` | `archived` | Yes | Archive semantics remain legacy-owned. |
| other value | unmapped | No | Must be reported and resolved before lifecycle migration. |
| n/a | `reviewing`, `approved`, `actioned`, `resolved` | No | Canonical-only lifecycle states are not safe to project back to legacy content opportunity state yet. |

Status conflicts are any linked row where the mapped legacy status differs from the linked canonical status, or where either side has an unmapped value. In Phase 2E the conflict is diagnostic only. `ContentOpportunityLifecycleMap` reports the conflict and explains that `ContentOpportunity` remains authoritative during this phase.

Brief creation flow today:

- `routes/app.php` posts to `AppContentOpportunityController::createBrief`.
- The controller validates `mode`, resolves a `ClientSite`, creates a `Brief` with `source=content_opportunity`, copies legacy opportunity fields into brief title, keywords, audience, funnel stage, intent, key points, CTA, notes and `client_refs`, then updates the legacy opportunity to `planned`.
- Chained mode redirects into content series creation; single mode redirects to the content workspace.

Proposed canonical brief handoff:

- A future phase should initiate brief creation from linked canonical `Opportunity` context while preserving the legacy `content_opportunity_id` as source evidence until the UI is migrated.
- The handoff must carry canonical opportunity id, legacy content opportunity id, target workspace/site, recommended title, evidence, recommended actions and the legacy brief fields still required by the existing flow.
- `ContentOpportunityBriefHandoffPlanner` now simulates that payload without creating a `Brief`, changing legacy status, changing routes or marking canonical opportunities planned.
- `php artisan mos:plan-content-opportunity-brief-handoff` reports whether linked records have enough canonical/site/title/keyword/evidence context to be safe for a later migration.

Read-only diagnostics:

- `php artisan mos:compare-content-opportunity-lifecycle` compares linked legacy/canonical lifecycle values and reports aligned rows, conflicts, unmapped statuses and missing canonical links.
- `php artisan mos:plan-content-opportunity-brief-handoff` reports safe versus blocked future brief handoffs and the missing fields.
- Both commands support workspace, site, source-id, status and limit filters. Neither command updates records.

Unresolved blockers before Phase 2F:

- Decide whether canonical `reviewing`, `approved`, `actioned` and `resolved` should map to new legacy-compatible states, be hidden from legacy UI, or trigger the UI migration first.
- Define `ContentOpportunityRun` canonical reference rules: runs may need canonical opportunity ids in result metadata while remaining an audit ledger.
- Define recommended-action dedupe so linked `ContentOpportunity` and canonical `Opportunity` do not create duplicate review actions.
- Decide growth/autopilot semantics for canonical opportunities before moving queues or growth assets.
- Move brief creation behind a canonical action path only after the dry-run planner shows safe handoff coverage.

## Phase 2F Recommended Action Dedupe

Phase 2F resolves the linked legacy/canonical recommended-action duplication blocker without moving brief creation, lifecycle transitions, UI routes, queues or public APIs.

Current legacy `ContentOpportunity` action mapping:

- `RecommendedActionMapper::contentOpportunity()` maps a legacy row to source group `content_intelligence`, action type `prepare_content_opportunity`, status `open` only while the legacy status is `open`, and a CTA back to the content opportunities screen.
- The legacy action text comes from `ContentOpportunity.angle`, falling back to a generic prepare message.
- Before Phase 2F the action source signature was workspace, source group, source model and legacy source id, so a linked canonical row could still create a second action.

Current canonical `Opportunity` action mapping:

- `RecommendedActionMapper::opportunity()` maps a canonical row to source group `opportunities`, action type `review_opportunity`, and a CTA to the canonical opportunity screen.
- The canonical action text comes from the first `Opportunity.recommended_actions` entry, falling back to a generic approval message.
- Before Phase 2F the canonical source signature used the canonical source model/id, so it did not collide with the legacy content opportunity action.

Source signatures used today:

- Unrelated recommended actions still use the existing model-scoped signature: workspace, source group, source model and source id.
- Linked `ContentOpportunity` and canonical `Opportunity` rows now use `ContentOpportunityRecommendedActionSignature`.
- The canonical-equivalent signature is versioned as `mos-content-opportunity-action:v1` and includes workspace, site, legacy content opportunity id, canonical opportunity id when linked, a normalized action type, bridge source model/source id and the legacy/canonical dedupe key when available.
- The normalized action type is `content_opportunity_review` for the legacy `prepare_content_opportunity` and canonical `review_opportunity` pair, so both records represent one reviewable action.

Duplicate risks:

- Existing databases may already contain one old action row keyed to `ContentOpportunity` and another keyed to linked `Opportunity`.
- Growth autopilot and general action hydration can touch the two source models through different paths.
- Updating an old row into the new shared signature is not safe yet because the current model has no separate canonical-equivalent signature column or duplicate-reference metadata, and changing `source_signature` can affect the unique key and visible source link.

Diagnostics and safe candidates:

- `ContentOpportunityRecommendedActionDedupeService` inspects a legacy row, detects the linked canonical row, computes old and canonical-equivalent signatures, finds existing actions for both source references and reports duplicate counts.
- It also reports safe candidate counts: linked records that do not yet have an action under the shared signature and can be created idempotently by the existing `RecommendedActionEngine`.
- `php artisan mos:dedupe-content-opportunity-actions` is dry-run by default and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.
- `--apply` is accepted but intentionally diagnostics-only in Phase 2F. No action rows are deleted, dismissed, relinked or rewritten.

Legacy consumers remaining legacy:

- Content opportunity UI, routes and controller.
- Brief creation and the legacy `planned` transition.
- Content opportunity engine generation, lifecycle refresh and run metrics.
- Growth assets, programmatic expansion and autopilot execution semantics.

Consumers that can become canonical-aware later:

- Growth/autopilot can consume canonical action references after execution ownership is defined.
- Brief creation can move behind canonical action ownership after the handoff planner is promoted from dry-run.
- Run metrics can store canonical opportunity references after the run ledger strategy is defined.

Remaining blockers before lifecycle/brief migration:

- Add a safe non-destructive action reference strategy if old duplicate action rows need repair.
- Define whether canonical action ownership changes CTAs or source links before any UI migration.
- Define `ContentOpportunityRun` canonical references.
- Define growth/autopilot execution semantics for linked canonical opportunities.

## Phase 2M Recommended Action Duplicate Repair Metadata

Phase 2M adds the missing non-destructive repair surface for duplicate recommended actions that already exist between linked legacy `ContentOpportunity` and canonical `Opportunity` records. It does not delete actions, dismiss actions, change lifecycle ownership, change visible CTAs, or alter content opportunity UI behaviour.

Current `recommended_actions` schema:

- Identity/source columns: `id`, `workspace_id`, `organization_id`, `user_id`, `source_type`, `source_id`, `source_signature`, `source_group`, `action_type`.
- Visible action state columns: `status`, `title`, `summary`, `why_this_matters`, `expected_outcome`, `what_argusly_will_do`, `what_requires_approval`, effort/score/label fields, CTA fields, `visible_at`, `approved_at`, `completed_at`, `dismissed_at`.
- Metadata/timestamps: existing nullable JSON `metadata`, `created_at`, `updated_at`.
- Unique/index surface: unique `source_signature`; indexes on `workspace_id`, `organization_id`, `user_id`, `source_group`, `action_type`, `status`, score/label/visibility fields, `workspace_id/status/priority_score`, `workspace_id/source_group/created_at`, and `source_type/source_id`.

Current source signature usage:

- Existing action projection continues to use `RecommendedActionMapper` and `RecommendedActionEngine`.
- Linked legacy/canonical content opportunities use `ContentOpportunityRecommendedActionSignature` for new shared canonical-equivalent signatures.
- Old duplicates can still exist with one row using the previous legacy `ContentOpportunity` signature and one row using the previous canonical `Opportunity` signature.
- Phase 2M does not rewrite `source_signature`; changing it could collide with the unique key and change idempotency semantics.

Current UI/action consumers:

- `RecommendedActionEngine::forWorkspace()` and dashboard summary read visible open actions by workspace and status.
- App opportunity and content opportunity screens still use their existing routes and source links.
- Growth autopilot, content packages and related queue builders reference recommended actions by id/source signature but are not migrated in this phase.
- Admin MOS provider diagnostics remain separate from recommended action UI; no UI behaviour is changed by default.

Duplicate repair requirements:

- Identify duplicate groups only when a linked legacy content opportunity and canonical opportunity both have recommended action rows.
- Select a primary action deterministically: open status first, then oldest `created_at`, then legacy `ContentOpportunity` source during the transition, then id.
- Annotate primary and duplicate roles without hard deletes, status changes, dismissed timestamps, source relinks, or lifecycle writes.
- Keep `source_signature`, `source_type`, `source_id`, CTA fields and visible status unchanged.

Metadata surface:

- Phase 2M reuses the existing nullable `recommended_actions.metadata` JSON column.
- The repair service writes `metadata.canonical_equivalence` with `canonical_equivalent_signature`, `legacy_content_opportunity_id`, `canonical_opportunity_id`, `duplicate_group_id`, `duplicate_role` (`primary` or `duplicate`), `repair_status` (`annotated`), `repaired_at`, `repair_actor` and `reason`.
- Dry-run proposals report the same group data with `repair_status=suggested` in command output but do not write metadata.

Repair service and command:

- `ContentOpportunityRecommendedActionRepairService` builds on `ContentOpportunityRecommendedActionDedupeService`.
- `php artisan mos:dedupe-content-opportunity-actions` remains dry-run by default and now reports primary action id, duplicate action ids, canonical-equivalent signature, whether it would annotate, annotated count and skipped reasons.
- `--apply` annotates metadata only. It never deletes, hides, dismisses, relinks, rewrites `source_signature`, creates briefs, changes lifecycle state, queues growth work, or changes routes.

Rollback strategy:

- To roll back a repair annotation, remove `metadata.canonical_equivalence` from affected `recommended_actions` rows or restore the previous JSON value from backup.
- Because Phase 2M does not change source signatures, statuses, timestamps other than `updated_at`, source references, CTAs, lifecycle fields or related tables, rollback does not require rebuilding actions or undoing lifecycle state.

Remaining blockers:

- Canonical action ownership and CTA/source-link semantics are still not migrated.
- Growth/autopilot execution semantics for linked canonical opportunities remain separate.
- Brief creation can move only after canonical ownership and route behaviour are explicitly designed.

## Phase 2G ContentOpportunityRun Canonical References

Phase 2G keeps `ContentOpportunityRun` as a legacy audit ledger and adds canonical reference visibility only. Generation, scoring, dedupe, lifecycle refresh, run counts, brief creation, routes and UI totals are unchanged.

Current run ledger fields:

- `status`, `source_type`, `input`, `failure_reason`, `started_at` and `finished_at` describe the run lifecycle and inputs.
- `candidates_count`, `created_count` and `refreshed_count` are legacy metrics written by `ContentOpportunityEngine`.
- `result` is existing JSON metadata. Today it stores legacy `opportunity_ids` and candidate `types`; Phase 2G may add a `canonical_reference_summary` only when explicitly requested.
- `ContentOpportunityRun::opportunities()` relates run rows to generated or refreshed `ContentOpportunity` rows through `content_opportunities.content_opportunity_run_id`.

Current count semantics:

- `candidates_count` is `count($candidates)` from `ContentOpportunityCandidateGenerator`.
- `created_count` increments when the persisted legacy `ContentOpportunity` was recently created.
- `refreshed_count` increments when an existing legacy row was refreshed by workspace/dedupe hash.
- Skipped candidate counts are not stored on the run today.
- `result.opportunity_ids` contains legacy `content_opportunities.id` values, not canonical opportunity ids.

Run details are displayed by `AppContentOpportunityController`, which lists recent `ContentOpportunityRun` rows and summary counts on the content opportunity screen. The UI remains legacy-owned in Phase 2G; any canonical metadata is additive diagnostics only.

Canonical reference strategy:

- `App\Services\Mos\Opportunity\ContentOpportunityRunCanonicalReferenceService` accepts a `ContentOpportunityRun`, reads associated legacy rows and finds linked canonical `Opportunity` rows through `opportunities.content_opportunity_id`.
- The service reports linked and unlinked legacy candidate counts, canonical ids grouped by legacy candidate status and canonical status, missing links, missing source context and duplicate link risks.
- Duplicate link risk means one legacy `ContentOpportunity` has more than one linked canonical `Opportunity`.
- The default reporting path is read-only and does not update runs, candidates, opportunities, briefs, lifecycle status, routes or queues.

Safe metadata:

- The existing `content_opportunity_runs.result` JSON field is safe for optional additive summaries because the engine already writes metadata there.
- `--write-summary` stores `result.canonical_reference_summary` with counts, grouped ids and id samples. It does not replace `result.opportunity_ids`, `candidates_count`, `created_count` or `refreshed_count`.
- If later phases need queryable canonical run references, a migration should add a dedicated projection table or indexed JSON columns. Phase 2G does not add that migration.

Diagnostics:

```bash
php artisan mos:inspect-content-opportunity-run-links
```

The command supports `--workspace=`, `--site=`, `--run-id=`, `--status=`, `--limit=` and optional `--write-summary`. `--status` filters inspected legacy candidate rows by `ContentOpportunity.status`. Default mode is read-only and reports total runs inspected, total legacy opportunities, linked canonical opportunities, linked/unlinked candidates, duplicate link risks, missing context and canonical id samples.

Fields remaining legacy-owned:

- Run lifecycle status and failure information.
- Candidate generation and refreshed/created count calculation.
- `ContentOpportunity.status`, freshness and dedupe lifecycle.
- Brief creation and the `planned` transition.
- Content opportunity UI routes and totals.

Fields that can safely include canonical metadata:

- `result.canonical_reference_summary` only when written by the diagnostics command.
- Downstream diagnostics output and audit reports.
- Future read-only run history annotations, provided existing totals remain unchanged.

Remaining blockers before moving lifecycle/brief ownership:

- Safe non-destructive repair for old duplicate recommended action rows.
- Canonical brief action ownership and CTA/source-link semantics.
- Growth/autopilot execution semantics for linked canonical opportunities.
- A future queryable storage strategy if run history needs first-class canonical ids rather than optional JSON summaries.

## Phase 2H Growth And Autopilot Canonical Handoff

Phase 2H resolves the growth/autopilot semantics blocker without changing production execution behaviour. `ContentOpportunity` remains the source used by existing growth attachments, programmatic detection and autopilot queue hydration.

Current consumers:

- `GrowthProgramOrchestrator::attachContentOpportunity()` links a legacy `ContentOpportunity` as a `GrowthAsset` with role `content_opportunity`, source `content_opportunity_mapping`, and then advances the growth program to at least `qualified`.
- `ProgrammaticOpportunityDetector::detect()` accepts a polymorphic source, including `ContentOpportunity` and canonical `Opportunity`, and writes specialized `ProgrammaticOpportunity` rows keyed by `workspace_id`, `source_type`, `source_id`, `pattern_type` and `base_topic`.
- `GrowthAutopilotQueueBuilder` hydrates recommended actions, adds open content opportunity actions from `ContentOpportunity`, then upserts queue items by a growth-autopilot signature derived from the recommended action source signature.
- `GrowthAsset` is the durable growth-program reference surface. The role `content_opportunity` means the asset is attached to the legacy content opportunity record; role `opportunity` means it is attached to canonical MOS `Opportunity`.

Current execution ownership:

- Growth asset creation and growth-program status advancement remain owned by `GrowthProgramOrchestrator`.
- Programmatic opportunity lifecycle remains owned by `ProgrammaticOpportunity`.
- Autopilot queue creation remains owned by `GrowthAutopilotQueueBuilder` and existing recommended-action hydration.
- Brief creation, content opportunity lifecycle and run metrics are unchanged.

Duplicate execution risks:

- A linked legacy `ContentOpportunity` and canonical `Opportunity` can both be attached as separate growth assets, especially inside the same growth program.
- Programmatic detection can create one programmatic row from the legacy source and another from the canonical source because the source morph columns are part of the unique key.
- Autopilot can queue both legacy and canonical work if recommended action signatures or queue source references diverge.
- Multiple canonical `Opportunity` rows linked to one legacy content opportunity remain a blocker for handoff.

Canonical reference rules for future phases:

- Keep existing `GrowthAsset` rows with role `content_opportunity`; do not rewrite them in place.
- Later canonical growth execution may use canonical `Opportunity` as the primary source, but must preserve the legacy `content_opportunity_id` in metadata or source evidence until the legacy table is retired.
- Before creating a canonical growth asset, check for an existing legacy `content_opportunity` asset for the same linked pair and growth program.
- Before creating a canonical autopilot queue item, reuse the canonical-equivalent recommended action signature and avoid queueing both legacy and canonical sources.
- Programmatic opportunities should consume canonical opportunities later as their source, while keeping pattern type, variables, scores, growth program links and lifecycle state in `programmatic_opportunities`.
- Do not flatten programmatic expansion state into canonical `Opportunity`.

Diagnostics:

```bash
php artisan mos:plan-content-opportunity-growth-handoff
```

The command is read-only and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports linked records, unlinked records, growth assets found, programmatic opportunities found, autopilot queue references found, duplicate execution risks, safe future handoff candidates and skipped records with reasons.

`App\Services\Mos\Opportunity\ContentOpportunityGrowthHandoffPlanner` accepts a legacy `ContentOpportunity`, finds linked canonical opportunities, inspects existing growth assets, programmatic opportunities and autopilot queue items for both legacy and canonical sources, then reports missing fields, duplicate execution risks and a recommended future reference strategy. It never creates growth assets, programmatic opportunities, autopilot queue items, briefs or lifecycle transitions.

Remaining blockers before lifecycle/brief migration:

- Safe non-destructive repair for already-created duplicate recommended action rows.
- Canonical brief action ownership and CTA/source-link semantics.
- A controlled writer for canonical growth/autopilot references after diagnostics show no duplicate execution risk.

## Phase 2I Canonical Brief Action Ownership

Phase 2I defines future brief action ownership and diagnostics only. The visible content opportunity UI, routes and production brief creation flow remain unchanged.

Current brief creation behaviour:

- `routes/app.php` still posts `app.agentic-marketing.content-opportunities.brief.create` to `AppContentOpportunityController::createBrief`.
- `AppContentOpportunityController::createBrief` resolves the legacy `ContentOpportunity`, validates `mode` and optional `site_id`, resolves a `ClientSite`, creates a `Brief`, copies legacy fields into the brief payload, records legacy source data in `client_refs`, marks the legacy opportunity `planned`, logs the action run and redirects to the existing content workspace or chained content plan.
- Phase 2I does not call or change this controller path.

Current CTA/source links from recommended actions:

- Legacy `ContentOpportunity` recommended actions still map to `prepare_content_opportunity` and point to `app.agentic-marketing.content-opportunities.index`.
- Canonical `Opportunity` recommended actions still map to `review_opportunity` and point to `app.opportunities.show`.
- Phase 2F made linked legacy/canonical records share a canonical-equivalent `source_signature`; Phase 2I reuses that signature for future brief action planning.

Future canonical brief action owner:

- The linked canonical `Opportunity` becomes the future owner of the review/create-brief action.
- The proposed CTA label is `Review canonical opportunity`.
- The proposed CTA route is the canonical opportunity review route when a linked `Opportunity` exists.
- The legacy content opportunity route remains a fallback and source-evidence link. It is not removed or replaced in Phase 2I.

Legacy traceability:

- Future brief metadata must preserve the legacy `content_opportunity_id`.
- `ContentOpportunityBriefHandoffPlanner`/`ContentOpportunityCanonicalBriefActionPlanner` include `client_refs.content_opportunity_id` and `client_refs.canonical_opportunity_id` in the dry-run legacy field payload.
- Brief metadata should retain the canonical opportunity id once production migration happens, while source evidence should keep the legacy row reference for audits.

Fields required for safe brief creation:

- One linked canonical `Opportunity`.
- Workspace id.
- Client site id.
- Brief title.
- Primary keyword.
- Language, content type and output type required by the current brief form.
- Source evidence from canonical evidence or meaningful legacy reasoning/signals.
- Legacy traceability fields for `content_opportunity_id` and canonical opportunity id.

Diagnostics:

```bash
php artisan mos:plan-content-opportunity-brief-actions
```

The command is read-only and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports linked records, unlinked records, safe canonical brief action candidates, blocked candidates, missing fields, proposed CTA route, proposed source link and proposed source signature. It creates no briefs, changes no statuses and dispatches no queues.

Remaining blockers before production migration:

- Safe repair strategy for already-created duplicate recommended action rows.
- Decision on when the visible content opportunity CTA should switch from the legacy route to the canonical review/action route.
- Status transition migration from legacy `ContentOpportunity` to canonical `Opportunity`.
- Growth/autopilot canonical writers.
- The existing `GrowthProgramCoreTest` failure must remain investigated/documented before growth results are used as a migration signal.

## Phase 2J Canonical Brief Writer Dry-Run

Phase 2J adds a guarded writer path for linked canonical `Opportunity` plus legacy `ContentOpportunity` records. It does not replace `AppContentOpportunityController::createBrief`, does not change visible CTAs and does not change default production brief creation.

Existing legacy brief fields copied by `AppContentOpportunityController::createBrief`:

- Site/user/status/source fields: `client_site_id`, `created_by_user_id`, `status=draft`, `source=content_opportunity`, `progress=0`, `wp_site_id`.
- Editorial fields: `title`, `language`, `content_type`, `output_type`, `primary_keyword`, `secondary_keywords`, `audience`, `target_audience`, `funnel_stage`, `search_intent`, `unique_angle`, `key_points`, `call_to_action`, `desired_length_min`, `desired_length_max`, `notes`.
- Legacy traceability in `client_refs`: `client_type=content_opportunity`, `site_url`, nested `content_opportunity` reference, and `source_briefing` including optional chained plan.

Canonical writer input contract:

- Linked canonical `Opportunity`.
- Linked legacy `ContentOpportunity`.
- Target `ClientSite`.
- Mode `single` or `chained`.
- Optional operator/user context.
- Explicit dry-run/apply intent through `ContentOpportunityCanonicalBriefWriter` or the command.

Fields that must match the legacy output shape:

- The writer creates the same `Brief` model with `source=content_opportunity`, `status=draft`, article output, legacy content type mapping, keywords, audience, funnel stage, intent, angle, key points, CTA, notes, length bounds and WordPress site reference.
- Chained mode keeps the same shorter length bounds and `source_briefing.chain_proposal` shape.
- The shared `ContentOpportunityBriefPayloadBuilder` is used by the legacy controller and the canonical writer so later route migration can reuse one payload contract.

Metadata/client refs strategy:

- Legacy traceability remains in `client_refs.content_opportunity`.
- The guarded canonical writer additionally stores top-level `client_refs.content_opportunity_id`, `client_refs.canonical_opportunity_id`, `client_refs.mode`, `client_refs.source_signature` and `client_refs.canonical_brief_writer`.
- Idempotency uses the generated source signature plus the canonical id, legacy id, target site and mode. Existing legacy briefs with nested `client_refs.content_opportunity.id` are treated as duplicate risk.

Status update policy:

- Default policy is no status changes.
- `ContentOpportunity.status` and `Opportunity.status` are not updated by dry-run or apply.
- `--mark-planned` exists as an explicit guarded apply option for later controlled use, but it is not part of default Phase 2J behaviour.
- Status handoff remains a Phase 2K concern.

Rollback/safety policy:

- The command defaults to dry-run.
- Apply only creates briefs for safe records with a canonical link, site, title, keyword and source evidence.
- Missing fields, mismatched links, organization/site mismatches and duplicate brief risk block apply.
- No route, CTA, queue, UI, migration or destructive data change is included in Phase 2J.

Diagnostics and guarded writer:

```bash
php artisan mos:create-canonical-content-opportunity-brief
php artisan mos:create-canonical-content-opportunity-brief --apply
```

The command supports `--workspace=`, `--site=`, `--source-id=`, `--opportunity-id=`, `--mode=single|chained`, `--limit=` and `--mark-planned`. Dry-run output reports would-create status, target site, canonical opportunity id, legacy content opportunity id, title, keyword, missing fields, blocked reasons and duplicate brief risk.

Why UI/routes remain unchanged:

- The existing customer-facing CTA still posts to `AppContentOpportunityController::createBrief`.
- Phase 2J is a controlled service/command writer only. It proves payload compatibility, traceability and idempotency before any visible route migration.
- Recommended action repair, lifecycle ownership and growth/autopilot writer readiness are still blockers before UI migration.

## Phase 2K Feature-Flagged Visible Brief Route Migration

Phase 2K prepares the visible brief creation route to use `ContentOpportunityCanonicalBriefWriter` behind a disabled-by-default feature flag. The route name, URL, Blade form and legacy fallback remain in place.

Current route/UI entrypoints that create briefs:

- `routes/app.php` keeps `POST /agentic-marketing/content-opportunities/{opportunity}/brief` named `app.agentic-marketing.content-opportunities.brief.create`.
- `resources/views/app/content-opportunities/index.blade.php` is the only visible form posting to that route. It sends CSRF, `mode=single|chained` and, when required, `site_id`.
- The same index page remains linked from the app sidebar, growth tools, campaign clusters, agentic orchestration and legacy recommended actions; those links open the opportunity list rather than creating briefs directly.

Current request payloads and redirects:

- Request payload: required `mode` with `single` or `chained`; optional UUID `site_id` for workspace-level opportunities.
- Single redirect remains `app.content.workspace.show` with status text `Brief created from opportunity. Generate a single article draft when ready.`
- Chained redirect remains `app.content.series.create` with `source_brief` and status text `Brief created from opportunity. Review the chained article plan.`
- Multiple active sites without explicit `site_id` still returns the existing `site_id` validation error. Missing site still returns the existing opportunity error.

Status transitions:

- Legacy path continues to mark `ContentOpportunity.status=planned`.
- Canonical route path also marks the legacy `ContentOpportunity` planned after a successful canonical-created brief or duplicate-safe reuse so visible behavior remains consistent.
- Canonical `Opportunity.status` is not updated by the visible route in Phase 2K. Lifecycle ownership remains transitional and is deferred to Phase 2L.

Canonical writer eligibility requirements:

- `features.mos_canonical_content_opportunity_brief_writer=true`.
- A linked canonical `Opportunity` exists with matching `content_opportunity_id`, `workspace_id` and `organization_id`.
- The normal route site resolution succeeds.
- `ContentOpportunityCanonicalBriefWriter::dryRun` reports safe: canonical link, site, title, primary keyword and source evidence are present; organization/site/link checks pass; no unsafe duplicate creation is needed.

Feature flag/config strategy:

- Config key: `features.mos_canonical_content_opportunity_brief_writer`.
- Environment variable: `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_BRIEF_WRITER`.
- Default: `false`, so production behavior stays legacy until explicitly enabled.

Fallback rules:

- Flag disabled: legacy brief creation path only.
- Flag enabled with no linked canonical opportunity: fallback to legacy path.
- Flag enabled with unsafe canonical dry-run: fallback to legacy path.
- Flag enabled with duplicate canonical brief risk: reuse the existing duplicate brief and redirect with the existing messages instead of creating another brief.
- Canonical-created briefs keep legacy compatibility and store both `client_refs.content_opportunity_id` and `client_refs.canonical_opportunity_id`.

Rollout plan:

1. Keep the flag off in production and run existing route regression tests plus `mos:create-canonical-content-opportunity-brief` dry-runs.
2. Enable the flag for a small internal workspace with linked canonical opportunities.
3. Monitor created briefs for canonical refs, legacy planned status and unchanged redirects/messages.
4. Expand only after duplicate risk and unsafe fallback counts are understood.

Rollback plan:

- Set `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_BRIEF_WRITER=false`.
- No route, form, legacy table or legacy brief shape rollback is required.
- Briefs already created through the canonical writer remain readable by the legacy UI because `source=content_opportunity` and nested legacy `client_refs.content_opportunity` are preserved.

Remaining blockers before lifecycle ownership migration:

- Phase 2L must define when canonical `Opportunity.status` becomes authoritative.
- Safe duplicate recommended-action repair remains separate from route-level duplicate brief reuse.
- Growth/autopilot canonical writers still need their own guarded ownership policy.

## Phase 2L Canonical Lifecycle Sync Planning

Phase 2L keeps lifecycle authority exactly where it is today: the legacy `ContentOpportunity.status` remains the status users see in the content opportunity UI, and the visible brief route still marks the legacy row `planned`. Canonical `Opportunity.status` may be synchronized only through an explicit command for linked rows. No app flow triggers lifecycle sync by default.

Current status writers:

- `AppContentOpportunityController::createBrief` marks the legacy `ContentOpportunity` planned after successful brief creation or duplicate-safe canonical brief reuse.
- `ContentOpportunityEngine` and `ContentOpportunityLifecycleService` continue to create, refresh and maintain legacy content opportunity lifecycle/freshness state.
- `mos:create-canonical-content-opportunity-brief --mark-planned` can explicitly mark both sides planned, but that is outside default route behavior.
- Canonical opportunity writers outside this content opportunity bridge continue to own their own canonical lifecycle states.

Current status readers:

- The content opportunity screen and filters read `ContentOpportunity.status`.
- Campaign planning and agentic shared context select legacy content opportunities by legacy status, even when they dual-read canonical descriptive fields.
- Phase 2E diagnostics compare legacy and canonical status but do not mutate either side.
- Canonical opportunity screens read `Opportunity.status` for canonical opportunities.

Canonical/legacy status mapping remains deliberately narrow:

| Legacy `ContentOpportunity.status` | Canonical `Opportunity.status` | Safe direction |
| --- | --- | --- |
| `open` | `open` | both directions |
| `planned` | `planned` | both directions |
| `dismissed` | `dismissed` | both directions |
| `archived` | `archived` | both directions |

Canonical `reviewing`, `approved`, `actioned` and `resolved` are canonical-only for this phase. They are unsafe for reverse sync into legacy content opportunity statuses and must be reported, not forced. Unknown legacy or canonical statuses are reported as unmapped.

Lifecycle sync is handled by `App\Services\Mos\Opportunity\ContentOpportunityCanonicalLifecycleSyncService`. The service accepts a legacy `ContentOpportunity`, a linked canonical `Opportunity`, an explicit direction, dry-run/apply intent and optional actor context. It validates link integrity, organization/workspace/site alignment, duplicate canonical links, unmapped statuses and canonical-only reverse states. It returns `ContentOpportunityCanonicalLifecycleSyncResult` with the direction, desired status, safety, conflict flags, blocked reasons and apply status.

Safe sync directions:

- `legacy-to-canonical`: safe only for `open`, `planned`, `dismissed` and `archived`; updates canonical status only on explicit apply.
- `canonical-to-legacy`: safe only for canonical `open`, `planned`, `dismissed` and `archived`; updates legacy status only on explicit apply.

Unsafe sync directions:

- Reverse syncing canonical-only statuses (`reviewing`, `approved`, `actioned`, `resolved`) into legacy.
- Syncing missing canonical links, duplicate canonical links, mismatched `content_opportunity_id`, mismatched organization/workspace or conflicting non-null site context.
- Syncing unknown/unmapped statuses in either direction.

Conflict resolution rules:

- A mapped status difference is reported as a conflict and, when the selected direction is otherwise safe, as a would-update row.
- Dry-run never writes. Apply writes only safe records in the requested direction.
- Conflicts are not auto-resolved globally; the operator chooses direction explicitly per command run.
- Duplicate canonical links block updates because the intended canonical owner is ambiguous.

Dry-run-first diagnostics and controlled apply:

```bash
php artisan mos:sync-content-opportunity-lifecycle
php artisan mos:sync-content-opportunity-lifecycle --apply --direction=legacy-to-canonical
php artisan mos:sync-content-opportunity-lifecycle --direction=canonical-to-legacy --workspace=... --status=open
```

The command supports `--apply`, `--direction=legacy-to-canonical|canonical-to-legacy`, `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports aligned rows, would-update rows, applied updates, conflicts, unmapped statuses, blocked canonical-only states, missing links and skipped rows.

Feature flag strategy:

- `features.mos_canonical_content_opportunity_lifecycle_sync` is backed by `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_LIFECYCLE_SYNC`.
- Default is `false`.
- Phase 2L uses command-only lifecycle sync. App-flow lifecycle sync should be added only after both the brief writer flag and lifecycle sync flag are enabled and the route path is covered by explicit tests.

Rollback plan:

- Stop running the sync command, or rerun it in the opposite safe direction for linked rows where the old source of truth is still known.
- Keep `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_LIFECYCLE_SYNC=false` to prevent future app-flow integration.
- No table, route, UI or legacy status writer is removed in this phase, so rollback does not require data deletion.

Remaining blockers before legacy UI migration:

- Decide when canonical `Opportunity.status` becomes the visible authority for content opportunities.
- Add safe duplicate recommended-action repair.
- Complete growth/autopilot canonical writers.
- Define default app-flow status transition policy after canonical brief creation.
- Migrate or retire the legacy content opportunity UI only after status authority, run references and execution writers are canonical-owned.

## Phase 2N Growth & Autopilot Canonical Writers

Phase 2N adds guarded canonical writer boundaries for growth assets and Growth Autopilot queue references. Production growth execution remains unchanged by default: `GrowthProgramOrchestrator::attachContentOpportunity` still writes legacy `GrowthAsset` rows with role `content_opportunity`, existing assets are never rewritten, `GrowthAutopilotQueueBuilder::build` still performs its normal workspace queue hydration, and programmatic opportunity lifecycle is not migrated.

Current growth asset writer paths:

- `GrowthProgramOrchestrator::linkAsset` upserts `GrowthAsset` rows by `growth_program_id`, `assetable_type`, `assetable_id` and `role`.
- `GrowthProgramOrchestrator::attachContentOpportunity` creates legacy `GrowthAsset` role `content_opportunity` for `ContentOpportunity`.
- `GrowthProgramOrchestrator::attachOpportunity` creates canonical `GrowthAsset` role `opportunity` for canonical `Opportunity`.
- Phase 2N does not change those default paths. It adds `ContentOpportunityCanonicalGrowthAssetWriter` as an explicit service/command path only.

Current autopilot queue writer paths:

- `GrowthAutopilotQueueBuilder::build` hydrates recommended actions, adds competitor/content opportunity actions, de-duplicates by recommended action source signature and upserts queue items by `source_signature`.
- Queue item signatures remain `sha1("growth-autopilot|{workspace_id}|{recommended_action_source_signature}")`.
- Phase 2N exposes the existing queue upsert method and adds `ContentOpportunityCanonicalAutopilotQueueWriter`, which uses the canonical `Opportunity` recommended action payload and the Phase 2F canonical-equivalent signature.

Current programmatic opportunity writer paths:

- `ProgrammaticOpportunityDetector` and `GrowthProgramOrchestrator::attachProgrammaticOpportunity` continue to own specialized programmatic expansion state.
- Phase 2N is diagnostics-only for programmatic references. It documents that a future source-reference migration may point programmatic opportunities at canonical `Opportunity`, but status, validation, cluster, blueprint and publication lifecycle remain untouched.

Duplicate execution risks:

- Growth asset apply is blocked when the target growth program already contains either the linked legacy `ContentOpportunity` asset or the linked canonical `Opportunity` asset.
- Autopilot apply is blocked when a legacy queue item, canonical queue item or canonical-equivalent queue signature already exists.
- Existing legacy rows are reported as blockers and left unchanged.
- Existing canonical-equivalent queue signatures prevent duplicate queue items even if the source reference is still legacy.

Canonical writer eligibility:

- A single linked canonical `Opportunity` must be supplied or discoverable through `opportunities.content_opportunity_id`.
- The canonical opportunity must point back to the legacy `ContentOpportunity` and share the same workspace.
- Growth asset writing additionally requires an explicit target growth program in the same workspace.
- Apply requires the relevant feature flag to be enabled. Dry-run remains available with flags disabled.

Feature flags:

- `features.mos_canonical_content_opportunity_growth_writer`, backed by `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_GROWTH_WRITER`, defaults to `false`.
- `features.mos_canonical_content_opportunity_autopilot_writer`, backed by `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_AUTOPILOT_WRITER`, defaults to `false`.
- No app route, scheduler or orchestrator path uses these flags by default in Phase 2N.

Dry-run-first commands:

```bash
php artisan mos:write-content-opportunity-growth-assets
php artisan mos:write-content-opportunity-growth-assets --apply --growth-program=...
php artisan mos:write-content-opportunity-autopilot-queue
php artisan mos:write-content-opportunity-autopilot-queue --apply
```

Both commands support `--apply`, `--workspace=`, `--site=`, `--source-id=`, `--opportunity-id=`, `--growth-program=` and `--limit=`. They report safe candidates, blocked candidates, duplicate execution risks, created references, skipped reasons and feature-flag status. `--growth-program` is required for growth asset apply and accepted by the autopilot command for operational parity, but queue writing does not attach to a growth program.

Traceability:

- Canonical growth assets store `metadata.legacy_content_opportunity_id`, `metadata.canonical_opportunity_id` and `metadata.source_evidence`.
- Canonical autopilot queue items store the same legacy/canonical ids plus the recommended action source signature and canonical-equivalent queue signature.
- Legacy `content_opportunity_id` remains the bridge for audits and rollback.

Fallback strategy:

- Keep feature flags disabled for normal production behavior.
- Continue using legacy growth orchestration and autopilot queue creation.
- For unsafe rows, use command output to repair links or remove ambiguity in a later controlled phase; do not auto-rewrite legacy assets or queue items.

Rollback strategy:

- Disable the two Phase 2N feature flags.
- Stop running the writer commands.
- No route, scheduler, growth orchestrator or programmatic lifecycle rollback is required because defaults are unchanged.
- Canonical references created through apply are additive and traceable; they can be reviewed by `source_type=mos_canonical_content_opportunity_*_writer` and their metadata links back to the legacy source.

Remaining blockers:

- Canonical action ownership and visible CTA/source-link migration are still separate.
- Default UI/lifecycle authority migration is still deferred.
- Programmatic opportunity source-reference migration remains a future diagnostics-backed phase.

## Phase 2O Canonical Action Ownership & CTA Source-Link Migration Planning

Phase 2O defines a reversible action ownership boundary for linked legacy `ContentOpportunity` and canonical `Opportunity` rows. It does not switch visible CTAs by default, does not remove legacy content opportunity routes, does not migrate lifecycle authority, and does not delete legacy recommended actions.

Visible `RecommendedAction` consumers:

| Consumer | Surface | Current behavior | Phase 2O rule |
| --- | --- | --- | --- |
| `RecommendedActionEngine::forWorkspace()` and `dashboardSummary()` | app dashboard widget | Hydrates and reads stored visible open actions by workspace. | Keep unchanged; mapper controls stored CTA/source metadata. |
| `AppRecommendedActionsController` and `resources/views/app/recommended-actions/index.blade.php` | recommended actions inbox | Reads stored action rows and renders `<x-recommended-actions.card>`. | No UI redesign. Existing centralized card consumes stored CTA URL. |
| `resources/views/components/recommended-actions/card.blade.php` | CTA button | Renders `primary_cta_label` and `primary_cta_url`. | No route logic added to the card. |
| `Api\V1\RecommendedActionController` and `RecommendedActionResource` | `/api/v1/recommended-actions` | Hydrates stored rows and exposes `primary_cta` plus `source` and `metadata`. | Additive mapper metadata can expose canonical owner and legacy traceability. |
| `AssistantMessageMapper`, `AssistantNotificationStrategy`, `AssistantFeedService` | assistant feed and notifications | Reuses stored `primary_cta_url` and recommended action payload. | Inherits default-off behavior; no separate CTA implementation. |
| `GrowthAutopilotQueueBuilder` | autopilot queue approvals | Hydrates recommended actions and stores approval CTA fields. | Inherits mapper output; guarded writer duplicate checks remain separate. |

Current CTA routes:

- Legacy `ContentOpportunity` recommended actions map through `RecommendedActionMapper::contentOpportunity()` to `app.agentic-marketing.content-opportunities.index` with `workspace_id`.
- Canonical `Opportunity` recommended actions map through `RecommendedActionMapper::opportunity()` to `app.opportunities.show`.
- Legacy brief creation still posts to `app.agentic-marketing.content-opportunities.brief.create`; Phase 2O does not change that route or form.

Current source link semantics:

- Stored `recommended_actions.source_type/source_id` remain the row that produced the action.
- Linked legacy/canonical content opportunity actions share the Phase 2F canonical-equivalent `source_signature`.
- Phase 2M duplicate repair metadata lives under `recommended_actions.metadata.canonical_equivalence` and is non-destructive.
- Phase 2O adds optional `metadata.canonical_action_ownership` only when the feature flag is enabled and the resolver marks the linked pair safe.

Canonical action owner:

- The linked canonical `Opportunity` is the proposed future owner of visible review CTAs and source ownership.
- The legacy `ContentOpportunity` remains the fallback source, source evidence and lifecycle/brief route owner during this phase.
- Existing legacy and canonical recommended action rows remain valid; duplicate metadata is used only to choose a display/primary action id for diagnostics and additive metadata.

Resolver boundary:

`App\Services\Mos\Opportunity\ContentOpportunityCanonicalActionOwnershipResolver` accepts a legacy `ContentOpportunity`, an optional linked canonical `Opportunity`, optional recommended action rows, optional duplicate repair metadata and feature flag state. It returns canonical owner id, legacy source id, primary/duplicate/display action ids, CTA route, source link, ownership status, blocked reasons and fallback route. It never mutates records.

Ownership statuses:

- `legacy`: no canonical migration is active and the legacy fallback remains visible.
- `canonical-ready`: a safe linked canonical owner exists, but the default-off flag keeps visible CTA/source links on legacy fallback.
- `canonical-active`: the flag is enabled and the linked pair is safe, so the mapper may point the legacy action CTA to `app.opportunities.show`.
- `blocked`: the flag is enabled but required link/context checks fail, so legacy fallback is used.

Fallback rules:

- Missing canonical link, workspace mismatch or conflicting non-null site context blocks canonical activation.
- When blocked or disabled, the legacy content opportunities index route remains the visible CTA fallback.
- Duplicate repair metadata can prefer the annotated primary action, but it does not hide, delete, dismiss or relink duplicate rows.
- Mapper integration keeps `source_type/source_id`, statuses and source signatures compatible with Phase 2F.

Feature flag:

- `features.mos_canonical_content_opportunity_action_ownership`, backed by `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_ACTION_OWNERSHIP`, defaults to `false`.
- When false, `RecommendedActionMapper::contentOpportunity()` keeps the existing legacy CTA output and emits no `canonical_action_ownership` metadata.
- When true and safe, the legacy action may point to the canonical opportunity review route and stores canonical owner plus legacy traceability in metadata.
- Unsafe rows always fall back to the legacy CTA/source link.

Diagnostics:

```bash
php artisan mos:inspect-content-opportunity-action-ownership
```

The command is read-only and supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports legacy-owned, canonical-ready, canonical-active and blocked counts, duplicate metadata status, primary/duplicate action ids, proposed CTA route, fallback route and blocked reasons.

Rollout plan:

1. Keep the flag disabled and run the diagnostics command to measure `canonical-ready` and blocked rows.
2. Repair links or duplicate annotations only through existing dry-run-first commands.
3. Enable the flag for an internal workspace with safe linked rows.
4. Verify recommended action inbox, dashboard, API and assistant/autopilot inherited CTAs.
5. Expand only after blocked reasons and duplicate metadata status are understood.

Rollback plan:

- Set `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_ACTION_OWNERSHIP=false`.
- No route, table, lifecycle or legacy action rollback is required.
- Existing additive metadata can remain for traceability; visible CTAs return to the legacy route on the next mapper hydration.

Remaining blockers:

- Decide when canonical `Opportunity` becomes the default visible UI authority for content opportunity actions.
- Migrate lifecycle authority only after CTA/source ownership rollout is proven.
- Plan programmatic source-reference migration separately.
