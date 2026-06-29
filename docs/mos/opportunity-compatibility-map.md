# MOS Opportunity Compatibility Map

Phase: MOS Core 2B competitor opportunity signal promotion.

This map documents opportunity-like storage concepts discovered in the codebase before any Phase 2 migration. It does not introduce a new opportunity system and does not migrate legacy records.

## Canonical Opportunity Core

The canonical MOS Opportunity Core is `App\Models\Opportunity` backed by `opportunities`, with `App\Models\OpportunitySignal` backed by `opportunity_signals` as the canonical bridge from observed signals into opportunities. Execution planning lives in `App\Models\OpportunityExecutionPlan` backed by `opportunity_execution_plans`.

## Compatibility Table

| Concept | Location | Current responsibility | Creates | Stores | Scores | Recommends | Executes | Classification | Relationship to canonical `Opportunity` | Recommended migration path | Risk | Consumers to migrate later |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Opportunity` | `app/Models/Opportunity.php`; table `opportunities`; services `App\Services\OpportunityIntelligence\*`; controllers `App\Http\Controllers\App\AppOpportunityIntelligenceController`, `App\Http\Controllers\App\AppOpportunitiesController` | Canonical opportunity record with priority, confidence, evidence, recommended actions and lifecycle status. | Yes | Yes | Yes | Yes | No | canonical | This is the MOS Opportunity Core target. | Keep as the destination model. Future work should route legacy candidates into this model through existing `OpportunitySignal` and `OpportunityIntelligenceEngine` paths. | Low | Unified opportunities workspace, campaign planning, programmatic detector, dashboard opportunity widgets, execution plan builder. |
| `OpportunitySignal` | `app/Models/OpportunitySignal.php`; table `opportunity_signals`; `OpportunitySignalIngestor`; `SignalDetectionToOpportunitySignalMapper`; `HumanSignalController` | Canonical source-signal record used to cluster, score and create opportunities. | Yes | Yes | Scores signal strength and confidence | Indirectly, through opportunity generation | No | source | Many-to-many source for canonical opportunities through `opportunity_signal_links`. | Keep as canonical source bridge. Legacy detectors should emit signals first unless they already have a fully materialized canonical opportunity. | Low | Signal Intelligence promotion, Human Signal API, onboarding readiness, opportunity intelligence engine. |
| `OpportunityExecutionPlan` | `app/Models/OpportunityExecutionPlan.php`; table `opportunity_execution_plans`; `OpportunityExecutionPlanBuilder`; app opportunity routes | Review and planning artifact for executing an accepted canonical opportunity. | Yes | Yes | Stores priority and impact estimates | Yes | No | projection | Belongs directly to canonical `Opportunity`. | Keep as execution-planning projection. Do not merge into opportunity records; migrate consumers to create plans from canonical opportunities only. | Low | Opportunity review UI, decision queue, execution recommendation views. |
| `ContentOpportunity` | `app/Models/ContentOpportunity.php`; table `content_opportunities`; `ContentOpportunityEngine`; `AppContentOpportunityController` | Content-specific opportunity candidates generated from workspace, company, competitor and content intelligence. | Yes | Yes | Yes | Yes | Can create briefs | consolidation candidate | `opportunities.content_opportunity_id` already links canonical opportunities to legacy content opportunities, but content opportunity screens still operate on this table directly. | Convert high-value/open records into canonical `Opportunity` records with source evidence preserved. Keep only run/candidate detail as evidence or remove once canonical consumers are complete. | High | Content opportunity engine UI, campaign cluster input builder, growth program orchestrator, content brief creation flow, agentic orchestration context. |
| `ContentOpportunityRun` | `app/Models/ContentOpportunityRun.php`; table `content_opportunity_runs`; `GenerateContentOpportunitiesJob` | Run ledger for content opportunity generation inputs and counts. | No | Yes | No | No | No | projection | Not an opportunity, but owns batches of `ContentOpportunity` rows. | Keep as a run/audit record while `ContentOpportunityEngine` exists. If candidates move to canonical `Opportunity`, update run result metrics to reference canonical IDs. | Medium | Content opportunity engine tests, run history in content opportunity UI. |
| `CompetitorContentOpportunity` | `app/Models/CompetitorContentOpportunity.php`; table `competitor_content_opportunities`; `CompetitorIntelligenceEngine`; `CompetitorOpportunityScorer`; `CompetitorContentOpportunitySignalPromotionService`; `mos:promote-competitor-opportunity-signals` | Competitor gap candidates with attackable angle, priority, impact and evidence. | Yes | Yes | Yes | Yes | No | source | Source evidence for canonical `OpportunitySignal`; not linked directly to canonical `Opportunity`. | Promote competitor gaps into canonical `OpportunitySignal` records, then let canonical opportunity generation create or refresh `Opportunity` records through signal clustering. | Medium | Competitor intelligence UI/json endpoint, content opportunity input builder, campaign cluster input builder, growth program orchestration. |
| `ProgrammaticOpportunity` | `app/Models/ProgrammaticOpportunity.php`; table `programmatic_opportunities`; `ProgrammaticOpportunityDetector`; `AppProgrammaticOpportunityController` | Detects scalable programmatic content patterns from canonical opportunities or growth program sources. | Yes | Yes | Yes | Yes | Creates clusters/growth program attachments | provider candidate | Can use canonical `Opportunity` as morph source, but stores a separate programmatic expansion candidate and lifecycle. | Keep as a specialized provider candidate until MOS provider boundaries mature. Later expose through a MOS provider that consumes canonical opportunities and stores only programmatic expansion state. | Medium | Programmatic opportunity UI, growth program orchestrator, programmatic cluster builder, smoke command. |
| `LinkOpportunity` | `app/Models/LinkOpportunity.php`; table `link_opportunities`; `ChainedContentOpportunityService`; content network jobs/controllers | Internal-link suggestion between source and target content. | Yes | Yes | Relevance score | Yes | Applied/rejected as link workflow state | projection | Narrow tactical projection, not a strategic opportunity. | Do not migrate directly into canonical opportunities unless links become decision-level work. Prefer keeping as execution/recommendation detail attached to content health or canonical opportunity evidence. | Medium | Content network analysis, content chain controller, content models, draft intelligence internal link panels. |
| `FaqOpportunityAudit` | `app/Models/FaqOpportunityAudit.php`; table `faq_opportunity_audits`; `FaqOpportunityService`; admin FAQ intelligence routes/jobs | FAQ coverage audit with missing questions, generated FAQs and impact scores. | Yes | Yes | Yes | Yes | Publishes/accepts FAQ work through admin workflow | consolidation candidate | No direct link to canonical `Opportunity`; currently admin-only FAQ intelligence pipeline. | Convert review-ready FAQ gaps into canonical opportunities or signals. Keep audit rows as evidence/audit trail until FAQ workflow is rewritten against canonical opportunities. | Medium | Admin FAQ intelligence page, FAQ analysis jobs, FAQ publishing/acceptance workflow. |
| `AgenticMarketingOpportunity` | `app/Models/AgenticMarketingOpportunity.php`; table `agentic_marketing_opportunities`; `AgenticMarketingOpportunityDetectionService`; opportunity detectors; execution pipeline services | Agentic Marketing objective-specific detected work item with payload, dedupe, action planning and execution pipeline anchors. | Yes | Yes | Priority score | Yes | Yes, through actions and execution pipelines | consolidation candidate | `opportunities.agentic_marketing_opportunity_id` already allows canonical linkage, but the Agentic Marketing workflow still uses its own opportunity table and execution FKs. | Phase 3A audits consumers, Phase 3B maps detector output read-only, Phase 3C inspects bridge eligibility, and Phase 3L reports planner readiness only. Do not migrate or backfill records yet. | High | Agentic marketing objectives/actions UI, autonomous workflow engine, campaign cluster materializer, action runs, execution asset generator, execution pipeline jobs, policies, growth/programmatic consumers. |
| `DetectedOpportunity` | `app/Services/AgenticMarketing/OpportunityDetection/DetectedOpportunity.php` | In-memory detection DTO used before persistence as `AgenticMarketingOpportunity`. | Yes | No | Carries priority | Yes | No | provider candidate | Not persisted and not linked to canonical `Opportunity`; Phase 3A identifies it as the earliest seam for canonical signal/opportunity payload mapping. | In Phase 3B, make detector outputs classify as `OpportunitySignal`, canonical `Opportunity`, both or execution-only before changing any persistence. | Medium | Agentic Marketing opportunity detector implementations, detection service and campaign cluster materialization handoff. |
| `InternalLinkOpportunityFinder` | `app/Agents/InternalLinking/InternalLinkOpportunityFinder.php` | In-memory finder for agent internal-link suggestions. | Yes | No | No | Yes | No | provider candidate | Tactical recommendation generator, not canonical storage. | Keep as provider-side detection logic. Persist durable work through `LinkOpportunity` or canonical opportunity evidence only when user-facing decision work exists. | Low | Internal linking agent flow. |

## Phase 2 Migration Risks

- `ContentOpportunity` and `AgenticMarketingOpportunity` are the highest-risk consolidation targets because they both store lifecycle state and drive user-facing execution flows.
- Phase 3A confirms `AgenticMarketingOpportunity` is higher execution risk than ordinary opportunity candidates: action planning, action runs, pipeline assets, approvals, audit logs, autonomous workflow selection, growth assets and programmatic detection all still resolve legacy Agentic opportunity ids.
- Phase 3C does not lower that risk by writing data; it only makes bridge readiness visible through `mos:inspect-agentic-opportunity-bridges`, including duplicate canonical bridge risk, source-scoped dedupe collisions and execution-state dependencies.
- Phase 3R does not complete migration. It is a feature-flagged, objective-scoped default-selection experiment that keeps `AgenticMarketingAction` rows legacy-owned and stores canonical ids only as metadata.
- Phase 3J keeps the risk high: lifecycle mapping is candidate-only and canonical action ownership is diagnostic-only. No canonical recommended actions should be created while Phase 3H signature blockers, Phase 3I parent lookup gaps or lifecycle ambiguity remain.
- Phase 3K permits only guarded additive canonical metadata on future Agentic execution rows. It does not reduce migration risk enough to change planner selection, lifecycle ownership, route binding or execution FKs.
- Phase 3L adds planner-readiness diagnostics but keeps the risk high. `metadata_ready_only` means traceability is available, not that planner selection can move. Planner readiness still requires one safe bridge, safe Phase 3H signatures, no Phase 3I parent lookup blockers, no Phase 3J lifecycle ambiguity/conflict and no duplicate open legacy action risk.
- `CompetitorContentOpportunity` and `FaqOpportunityAudit` should be handled as source/evidence first, then promoted through `OpportunitySignal` to avoid duplicate strategic opportunity records.
- `ProgrammaticOpportunity` is specialized expansion state. It should not be flattened into canonical `Opportunity`; instead, it should become a provider candidate that consumes canonical opportunities.
- `LinkOpportunity` is tactical execution/recommendation state. Migrating it into canonical opportunities would likely make the core noisier unless the product treats link work as reviewable strategic work.
- No Phase 2 migration should delete or backfill these tables until consumers listed above are moved and verified.

## Internal Diagnostics UI

An internal diagnostics page was added at the existing superadmin admin diagnostics cluster: `admin.mos-providers.index`. It uses `MosProviderRegistry` only and does not query provider services directly.

## Phase 2A Provider Readiness

Phase 2A adds read-only MOS Opportunity providers through the existing `MosProviderRegistry`. These providers normalize legacy records into `CanonicalOpportunityCandidate` DTOs only. They do not save `Opportunity` records, emit queue jobs, update legacy rows or change UI behaviour.

| Provider key | Legacy model | Classification | Readiness | Canonical payload | Signal payload | Risk | Mapping notes | Blockers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `legacy-content-opportunities` | `ContentOpportunity` | consolidation candidate | high-value records with existing canonical links | Yes | Yes | High | Maps title, reasoning/why-this-matters, type, status, priority, confidence, expected impact, business value, source signals, recommended actions, workspace/site context and dedupe hash. | Execution and brief-creation consumers still read `content_opportunities` directly. |
| `legacy-agentic-marketing-opportunities` | `AgenticMarketingOpportunity` | consolidation candidate | canonical link exists, execution state still blocked | Yes | Yes | High | Maps title, type, status, priority, payload evidence/actions, objective/content references and dedupe hash. | Objective context and execution pipelines must move behind canonical opportunity links before migration. |
| `legacy-competitor-content-opportunities` | `CompetitorContentOpportunity` | source evidence candidate | signal-first recommended | Yes | Yes | Medium | Maps competitor evidence, attackable angle, scores, topic, run/competitor references and workspace/site context. | Should likely emit `OpportunitySignal` first to prevent duplicate strategic opportunities. |
| `legacy-faq-opportunity-audits` | `FaqOpportunityAudit` | consolidation candidate | admin workflow context missing | Yes, with missing-field reporting | Yes | Medium | Maps page metadata, FAQ opportunity score, impact scores, missing questions, generated FAQs and suggested links/CTAs. | No durable workspace or organization context exists on the audit row. |
| `legacy-programmatic-opportunities` | `ProgrammaticOpportunity` | provider candidate | specialized expansion state should remain separate | Yes | No | Medium | Maps pattern type, base topic, scale/confidence/business/SEO/AI scores, growth program context, source morph references and variable metadata. | Programmatic expansion lifecycle should remain specialized until canonical opportunities own the upstream decision. |
| `legacy-link-opportunities` | `LinkOpportunity` | projection | tactical projection, not strategic opportunity | No | No | Medium | Produces an inspection DTO with source/target content, anchor, context and relevance, but reports unsupported canonical conversion. | Internal-link state is tactical execution detail unless product treats links as strategic review work. |

## Phase 2B Competitor Signal Promotion

Phase 2B adds the first write path from a legacy source into the canonical MOS Opportunity Core without creating canonical `Opportunity` records directly. `CompetitorContentOpportunity` remains the source evidence table and continues to power the existing competitor intelligence UI and downstream legacy consumers.

Promotion is handled by `App\Services\OpportunityIntelligence\CompetitorContentOpportunitySignalPromotionService` and can be backfilled with:

```bash
php artisan mos:promote-competitor-opportunity-signals
```

The command is a dry run by default. Use `--apply` to persist signals. It supports `--workspace=`, `--site=`, `--source-id=` and `--limit=` filters and reports seen, would-create, created, updated, duplicate and skipped counts.

Promoted signals can be inspected with:

```bash
php artisan mos:validate-competitor-opportunity-signals
```

The validation command is inspection-only. It supports the same `--workspace=`, `--site=`, `--source-id=` and `--limit=` filters, reports total promoted competitor signals, canonical opportunity eligibility, linked signals, unclustered eligible signals, incomplete signals, duplicates and stale source references. The current `OpportunityIntelligenceEngine` has no dry-run mode, so validation does not create or update `Opportunity` records.

Promoted signals use:

- `source`: `competitor_intelligence`.
- `category`: `content_gap` by default, or `competitor_movement` when the legacy type explicitly indicates movement.
- `topic`: the legacy topic, falling back to title only when topic is empty.
- `entity`: the competitor name.
- `metrics`: priority, impact, confidence, effort and the legacy dedupe hash.
- `evidence`: source model/id, title, topic, reason, attackable angle, query intent, funnel stage, recommended format, competitor details, competitor evidence, Argusly coverage and normalized payload.
- `metadata`: source type, source model/id, status, competitor/run references, action hints and promotion trace fields.

Dedupe is source scoped: `workspace_id`, `competitor_content_opportunity`, source model and source id are hashed into `opportunity_signals.dedupe_hash`. Promotion uses `updateOrCreate` on workspace plus this hash, so repeated runs are idempotent and do not create duplicate canonical signals.

Promotion skips records with missing workspace, site, competitor reference or topic/title context. Missing context is reported instead of inferred.

Signal quality validation requires: existing workspace, existing site, competitor source model/id, dedupe hash, topic or evidence title, competitor/entity context, non-empty evidence payload, valid canonical category and `competitor_intelligence` source. Stale source references and duplicate promoted signals are surfaced as risks; the validator does not silently repair them.

Canonical `Opportunity` creation remains the responsibility of the existing `OpportunityIntelligenceEngine` and signal clustering path. When the canonical engine runs, promoted competitor signals are grouped, scored and linked through `opportunity_signal_links` like any other signal. Generated opportunities record competitor source ids in `metadata.competitor_content_opportunity_ids` and `source_signal_summary.competitor_content_opportunity_ids`. This phase does not migrate `ContentOpportunity`, does not migrate `AgenticMarketingOpportunity`, does not delete legacy tables and does not replace the competitor intelligence UI.

Known blockers before `ContentOpportunity` consolidation:

- Content opportunity screens, brief creation and run metrics still read `content_opportunities` directly.
- Execution and campaign planning consumers still need canonical opportunity links before legacy rows can become source evidence only.
- Validation coverage should remain green for promoted competitor signals so canonical opportunity clustering can be trusted before higher-risk content opportunity data moves.

Phase 2C adds the safe bridge for selected legacy content opportunities without replacing those consumers. `App\Services\Mos\Opportunity\ContentOpportunityCanonicalLinkService` reuses the read-only `ContentOpportunityProvider` adapter, validates `canPersistCanonically()`, finds canonical rows by `opportunities.content_opportunity_id` first and by a source-scoped dedupe hash second, and only creates canonical `Opportunity` rows when explicitly applied. The service preserves source model/id, evidence, source signals, recommended actions, workspace/site context, priority, confidence, impact, urgency, business value, legacy status and legacy expected impact in canonical fields, evidence, source signal summary and metadata.

Backfill support is dry-run-first:

```bash
php artisan mos:link-content-opportunities
php artisan mos:link-content-opportunities --apply --workspace=... --status=open --min-priority=70
```

Safe records need workspace context, title/type/status, a stable dedupe key and evidence or reasoning. Skips are reported for missing candidate fields, unsupported provider conversion, missing context, missing title/topic/dedupe information and duplicate canonical rows already linked to another legacy content opportunity. Phase 2C does not move lifecycle ownership, alter brief creation, change campaign planning, alter queues or update public routes.

## Phase 2D ContentOpportunity Dual-Read

Phase 2D introduces `App\Services\Mos\Opportunity\ContentOpportunityCanonicalReadService` and `ContentOpportunityCanonicalReadModel` as a read-only compatibility layer for linked content opportunities. The service accepts a legacy `ContentOpportunity`, finds a linked canonical `Opportunity` through `opportunities.content_opportunity_id`, returns normalized fields and exposes field-level provenance. It never creates canonical opportunities, never links rows, never mutates legacy data and never changes lifecycle status.

Consumers dual-reading now:

- `CampaignClusterInputBuilder` and `CampaignClusterCandidateGenerator` use normalized snapshots for campaign planning inputs. Cluster items still store legacy `content_opportunity_id`; source signal metadata may include linked canonical opportunity ids.
- `SharedMarketingContextBuilder` includes `canonical_opportunity_id` and field provenance in the agentic shared context. Legacy-only rows still serialize with the same legacy id/title/topic shape.

Consumers intentionally kept legacy:

- Content opportunity routes, controller, Blade view, brief creation and status transitions.
- Content opportunity generation, scoring, dedupe, lifecycle refresh and run metrics.
- Growth program attachments, programmatic opportunity detection and autopilot queues.
- User-facing recommended action semantics, even though Phase 2F makes linked legacy/canonical signatures canonical-aware.

Fallback and provenance:

- Legacy row selection remains unchanged; legacy workspace/site/status filters decide which rows are visible to migrated consumers.
- Canonical values are preferred only for descriptive and scoring fields where safe: title, priority, confidence, impact, effort, urgency, business value, recommended actions and evidence.
- Legacy values remain authoritative for legacy ids, content-specific subtype and lifecycle status in Phase 2D.
- Missing canonical values fall back to legacy values field by field.
- The read model records `canonical` or `legacy` provenance for each important field so downstream diagnostics can explain the source of data.

Blockers before Phase 2E:

- Canonical lifecycle ownership must be defined before moving UI status actions.
- Brief creation needs an explicit canonical handoff before the controller/routes can move.
- `ContentOpportunityRun` needs canonical reference/metric rules before run ownership can move.
- Recommended-action dedupe between linked legacy and canonical sources must be designed.
- Growth attachments and autopilot queues need canonical execution semantics to avoid duplicate work.

## Phase 2E ContentOpportunity Lifecycle And Brief Handoff

Phase 2E adds explicit lifecycle mapping and dry-run brief handoff planning without changing production behaviour. `ContentOpportunity` remains the lifecycle owner; canonical `Opportunity` rows are compared as linked shadow context only.

Lifecycle mapping is centralized in `App\Services\Mos\Opportunity\ContentOpportunityLifecycleMap`:

- `open` maps to canonical `open`.
- `planned` maps to canonical `planned`.
- `dismissed` maps to canonical `dismissed`.
- `archived` maps to canonical `archived`.
- Canonical `reviewing`, `approved`, `actioned` and `resolved` are intentionally not safe to map back to legacy statuses yet.
- Unknown legacy or canonical statuses are reported as unmapped.

Use this read-only diagnostic command:

```bash
php artisan mos:compare-content-opportunity-lifecycle
```

It supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports aligned rows, conflicts, unmapped statuses and missing canonical links. It never updates either table.

Brief handoff planning is handled by `App\Services\Mos\Opportunity\ContentOpportunityBriefHandoffPlanner`. It describes how a later canonical brief action would represent a linked content opportunity: canonical id, legacy id, title, evidence, recommended actions, workspace/site context, legacy fields still needed by the current `Brief` creation flow, missing fields and safety.

Use:

```bash
php artisan mos:plan-content-opportunity-brief-handoff
```

The command is dry-run only and supports the same filters. It never creates briefs, marks opportunities planned, changes routes, dispatches queues or updates canonical lifecycle state.

Phase 2F resolves recommended-action dedupe only. Canonical lifecycle ownership, brief action ownership, run metrics references and growth/autopilot execution semantics remain blockers before moving user-facing status or brief behaviour.

## Phase 2F ContentOpportunity Recommended Action Dedupe

Phase 2F keeps the existing `RecommendedActionEngine` and `RecommendedActionMapper` as the only action projection path. It does not add a second recommendation engine.

Linked legacy/canonical content opportunity actions now share a canonical-equivalent source signature through `App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionSignature`. The signature is versioned and source scoped:

- version: `mos-content-opportunity-action:v1`
- workspace id and client site id
- legacy `content_opportunities.id`
- linked canonical `opportunities.id` when present
- normalized action type `content_opportunity_review`
- bridge source model/source id
- legacy or canonical dedupe key

This means `ContentOpportunity` action type `prepare_content_opportunity` and linked canonical `Opportunity` action type `review_opportunity` converge for dedupe while their payload mapping remains in the existing mapper.

The dry-run diagnostics command is:

```bash
php artisan mos:dedupe-content-opportunity-actions
```

It supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It reports linked rows, duplicate action risk, safe canonical-equivalent candidates and skipped records. Phase 2F kept `--apply` diagnostics-only because the current action model had no documented duplicate-reference metadata policy. Phase 2M now uses the existing nullable `recommended_actions.metadata` JSON column for non-destructive repair annotations. No recommended actions are deleted, dismissed, relinked or rewritten.

Phase 2F does not move brief creation, lifecycle status transitions, content opportunity UI, run metrics, queues or growth/autopilot execution. Existing duplicate rows remain visible; Phase 2M can annotate them for repair tracking without changing visible behaviour.

## Phase 2M Recommended Action Repair Metadata

Phase 2M keeps the mapper and engine as the only action projection path and adds `App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionRepairService` beside the Phase 2F dedupe inspector. The repair service detects duplicate groups between linked legacy `ContentOpportunity` and canonical `Opportunity` recommended action rows, selects a primary deterministically and optionally annotates the group.

Primary selection rule:

- open action over non-open action
- older `created_at` over newer `created_at`
- legacy `ContentOpportunity` source over canonical `Opportunity` source during the transition
- id as final tie-breaker

Repair metadata is stored under `recommended_actions.metadata.canonical_equivalence`:

- `canonical_equivalent_signature`
- `legacy_content_opportunity_id`
- `canonical_opportunity_id`
- `duplicate_group_id`
- `duplicate_role` (`primary` or `duplicate`)
- `repair_status` (`annotated`)
- `repaired_at`
- `repair_actor`
- `reason`

`php artisan mos:dedupe-content-opportunity-actions` remains dry-run by default. In dry-run it reports duplicate groups, primary action id, duplicate action ids, canonical-equivalent signature, whether it would annotate and skipped reasons. With `--apply`, it writes only the metadata annotation and reports `annotated` counts.

Rollback removes `metadata.canonical_equivalence` or restores the prior JSON value. Because Phase 2M does not change `source_signature`, `source_type`, `source_id`, status, dismissed/completed timestamps, CTAs, routes, briefs, lifecycle fields, queues or growth assets, rollback is metadata-only.

Remaining blockers after Phase 2M are canonical action ownership, CTA/source-link migration, brief ownership and growth/autopilot execution semantics.

## Phase 2G ContentOpportunityRun Canonical References

Phase 2G resolves the run-metric reference blocker without migrating run ownership. `ContentOpportunityRun` remains a projection/audit ledger for the legacy content opportunity engine, and `content_opportunities` remains the candidate lifecycle table.

`App\Services\Mos\Opportunity\ContentOpportunityRunCanonicalReferenceService` reads a run, inspects its associated `ContentOpportunity` rows and reports canonical `Opportunity` links through `opportunities.content_opportunity_id`. The service reports linked candidate counts, unlinked candidate counts, canonical id groupings by legacy candidate status and canonical status, missing links, missing context and duplicate link risks. It is read-only unless a caller explicitly writes an additive summary.

Use:

```bash
php artisan mos:inspect-content-opportunity-run-links
```

The command supports `--workspace=`, `--site=`, `--run-id=`, `--status=`, `--limit=` and optional `--write-summary`. Default mode is read-only. `--write-summary` stores a `canonical_reference_summary` under the existing `content_opportunity_runs.result` JSON field and does not alter legacy run counts or candidate lifecycle fields.

Compatibility rules:

- `candidates_count`, `created_count`, `refreshed_count` and `result.opportunity_ids` stay legacy-compatible.
- Canonical opportunity ids are audit metadata only and must not become lifecycle authority.
- Existing run history UI can remain unchanged; future UI annotations must be additive.
- A later migration is needed only if canonical run references must become queryable first-class records.

Remaining blockers before `ContentOpportunity` lifecycle or brief ownership can move:

- Safe repair strategy for already-created duplicate recommended action rows.
- Canonical brief action ownership and CTA/source-link semantics.
- Growth/autopilot execution semantics for linked canonical opportunities.

## Phase 2H Growth And Autopilot Handoff Compatibility

Phase 2H keeps Growth and Growth Autopilot execution on the existing legacy paths while making canonical handoff risks visible.

`ContentOpportunityGrowthHandoffPlanner` inspects a legacy `ContentOpportunity`, linked canonical `Opportunity` rows, `GrowthAsset` references, `ProgrammaticOpportunity` source references and `GrowthAutopilotQueueItem` source references. The planner is read-only and reports whether a future canonical handoff is safe, why a row is skipped and where duplicate execution could occur.

Use:

```bash
php artisan mos:plan-content-opportunity-growth-handoff
```

The command supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`. It does not create growth assets, programmatic opportunities, autopilot queue items, briefs, recommended actions or lifecycle transitions.

Compatibility rules:

- Existing `GrowthAsset` rows with role `content_opportunity` remain valid legacy references.
- Canonical `Opportunity` can become the future primary growth planning source only after duplicate legacy/canonical asset references are prevented.
- Preserve legacy `content_opportunity_id` in canonical-source metadata or evidence during migration.
- Programmatic opportunities remain specialized expansion state; only their source reference may later point to canonical `Opportunity`.
- Autopilot queue migration must reuse canonical-equivalent recommended action signatures so one linked opportunity cannot create two queue items.

Updated blocker status:

- Growth/autopilot semantics are now documented and diagnosable.
- A writer for canonical growth/autopilot references is still blocked until diagnostics show safe candidates and duplicate action repair/source-link rules are complete.

## Phase 2I ContentOpportunity Brief Action Ownership

Phase 2I defines canonical brief action ownership without moving production behaviour.

Rules:

- Linked canonical `Opportunity` is the future owner of the create-brief review action.
- Legacy `ContentOpportunity` remains the production brief creation source until the UI/controller route is migrated.
- Future brief metadata must preserve both `canonical_opportunity_id` and legacy `content_opportunity_id`.
- Recommended action CTA semantics remain unchanged in production: legacy content opportunity actions point to the content opportunities screen; canonical opportunity actions point to the canonical opportunity review screen.
- The future canonical brief action CTA should point to `app.opportunities.show`; the legacy content opportunity route remains a fallback/source evidence link.
- Source signatures must reuse the Phase 2F canonical-equivalent strategy from `ContentOpportunityRecommendedActionSignature`.

Diagnostics:

```bash
php artisan mos:plan-content-opportunity-brief-actions
```

The command is read-only and reports linked/unlinked records, safe and blocked candidates, missing fields, proposed CTA route, proposed source link and proposed source signature. Required migration fields are canonical opportunity id, workspace id, client site id, title, primary keyword, current brief form defaults and source evidence.

Remaining blockers:

- Safe repair strategy for existing duplicate recommended action rows.
- Visible CTA migration decision.
- Legacy-to-canonical status transition migration.
- Growth/autopilot canonical writers.
- Existing `GrowthProgramCoreTest` failure must be investigated or documented before growth diagnostics become a migration signal.

## Phase 2J Canonical Brief Writer Compatibility

`ContentOpportunityCanonicalBriefWriter` adds a guarded service path for linked canonical/legacy opportunity pairs. The service uses `ContentOpportunityBriefPayloadBuilder` so canonical-created briefs keep the current legacy `Brief` output shape while adding canonical traceability.

Compatibility rules:

- Production content opportunity routes and CTAs remain legacy-owned.
- Canonical writer apply is explicit only; command execution defaults to dry-run.
- Briefs store both `client_refs.content_opportunity_id` and `client_refs.canonical_opportunity_id`.
- Duplicate detection checks target site, mode, canonical id, legacy id, generated source signature and existing legacy nested `client_refs.content_opportunity.id`.
- Default apply does not update `ContentOpportunity.status` or `Opportunity.status`.

Use:

```bash
php artisan mos:create-canonical-content-opportunity-brief
php artisan mos:create-canonical-content-opportunity-brief --apply
```

Remaining blockers after Phase 2J:

- Visible CTA/route migration to canonical ownership.
- Lifecycle ownership migration and default status transition policy.
- Safe duplicate recommended-action repair.
- Growth/autopilot canonical writers.

## Phase 2K Visible Brief Route Compatibility

The visible content opportunity brief route now has a guarded canonical writer path, but compatibility remains legacy-first:

- `features.mos_canonical_content_opportunity_brief_writer` defaults to `false`.
- The existing route name `app.agentic-marketing.content-opportunities.brief.create`, POST URL and Blade form remain unchanged.
- When the flag is off, `AppContentOpportunityController::createBrief` creates briefs through the legacy payload path exactly as before.
- When the flag is on, the controller resolves the same `ContentOpportunity`, `ClientSite` and `mode`, finds a linked canonical `Opportunity`, runs `ContentOpportunityCanonicalBriefWriter::dryRun`, and applies the canonical writer only for safe linked records.
- Unsafe or unlinked records fall back to legacy brief creation. Existing canonical duplicate brief risk reuses the existing brief rather than creating a second one.

Brief compatibility and lifecycle policy:

- Canonical route-created briefs keep `source=content_opportunity` and the nested legacy `client_refs.content_opportunity` reference.
- Canonical route-created briefs additionally store `client_refs.content_opportunity_id`, `client_refs.canonical_opportunity_id`, `client_refs.mode` and `client_refs.source_signature`.
- The visible route preserves legacy behavior by marking the legacy `ContentOpportunity` planned after successful canonical creation or duplicate-safe reuse.
- The route does not update canonical `Opportunity.status`; status authority remains a Phase 2L migration topic.

Rollback is immediate by disabling `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_BRIEF_WRITER`. No route or data deletion is required.

## Phase 2L ContentOpportunity Lifecycle Sync Boundary

Phase 2L adds an explicit sync boundary without changing lifecycle authority. `ContentOpportunity.status` remains the content opportunity UI authority, while `Opportunity.status` remains canonical opportunity state. Linked rows can be synchronized only through `ContentOpportunityCanonicalLifecycleSyncService` or the dry-run-first command:

```bash
php artisan mos:sync-content-opportunity-lifecycle
php artisan mos:sync-content-opportunity-lifecycle --apply --direction=legacy-to-canonical
```

Safe mappings are limited to `open`, `planned`, `dismissed` and `archived` in both directions. Canonical `reviewing`, `approved`, `actioned` and `resolved` are canonical-only and block reverse sync. Missing links, duplicate canonical links, mismatched linked pairs and unmapped statuses are reported as skipped/blocked rows.

The feature flag `features.mos_canonical_content_opportunity_lifecycle_sync` defaults to `false`. Phase 2L does not trigger lifecycle sync from app flows, does not make canonical status authoritative by default, does not delete `content_opportunities` and does not remove the legacy content opportunity UI.

## Phase 2N Growth & Autopilot Writer Compatibility

Phase 2N introduces explicit writer compatibility without changing default execution paths.

Compatibility rules:

- Existing `GrowthAsset` rows with role `content_opportunity` remain valid and are not rewritten.
- `ContentOpportunityCanonicalGrowthAssetWriter` can create a canonical `GrowthAsset` for the linked `Opportunity` only through explicit dry-run/apply service or command usage.
- Growth asset apply requires `features.mos_canonical_content_opportunity_growth_writer=true`, a linked canonical opportunity, a target growth program in the same workspace and no same-program legacy/canonical asset duplicate risk.
- `ContentOpportunityCanonicalAutopilotQueueWriter` can create a canonical queue reference only through explicit dry-run/apply service or command usage.
- Autopilot apply requires `features.mos_canonical_content_opportunity_autopilot_writer=true`, a linked canonical opportunity and no existing legacy queue item, canonical queue item or canonical-equivalent queue signature.
- Autopilot writing uses the existing `GrowthAutopilotQueueBuilder` upsert path and the Phase 2F canonical-equivalent recommended action signature.
- Programmatic opportunity lifecycle and source ownership are not migrated.

Use:

```bash
php artisan mos:write-content-opportunity-growth-assets
php artisan mos:write-content-opportunity-growth-assets --apply --growth-program=...
php artisan mos:write-content-opportunity-autopilot-queue
php artisan mos:write-content-opportunity-autopilot-queue --apply
```

Traceability and rollback:

- New canonical growth assets and queue items store both `legacy_content_opportunity_id` and `canonical_opportunity_id` in metadata/source evidence.
- Rollback is to disable the feature flags and stop invoking the commands; no default route, scheduler or orchestrator path was moved.

Updated blocker status:

- The growth/autopilot writer blocker is reduced to guarded command/service rollout and operational review of duplicate-risk reports.
- Canonical CTA/source-link migration, default lifecycle authority and programmatic source-reference migration remain blocked for later phases.

## Phase 2O Canonical Action Ownership Compatibility

Phase 2O reduces the CTA/source-link blocker to a default-off resolver and mapper integration.

Compatibility rules:

- `ContentOpportunityCanonicalActionOwnershipResolver` is read-only and returns canonical owner id, legacy source id, primary/duplicate/display recommended action ids, CTA route, source link, ownership status, blocked reasons and fallback route.
- `features.mos_canonical_content_opportunity_action_ownership` defaults to `false`.
- With the flag disabled, legacy `ContentOpportunity` recommended actions keep the existing `app.agentic-marketing.content-opportunities.index` CTA output.
- With the flag enabled and a linked row safe, `RecommendedActionMapper::contentOpportunity()` may point the action CTA to `app.opportunities.show`.
- Unsafe or unlinked records fall back to the legacy content opportunity route.
- Duplicate repair metadata under `metadata.canonical_equivalence` can identify the display/primary action, but Phase 2O does not delete duplicate rows or change action statuses.
- Source signatures continue to use `ContentOpportunityRecommendedActionSignature`, so linked legacy/canonical sources remain canonical-equivalent and do not create duplicate actions.

Diagnostics:

```bash
php artisan mos:inspect-content-opportunity-action-ownership
```

The command reports legacy-owned, canonical-ready, canonical-active and blocked counts, duplicate metadata status, proposed CTA route and fallback route. It supports `--workspace=`, `--site=`, `--source-id=`, `--status=` and `--limit=`.

Rollback:

- Disable `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_ACTION_OWNERSHIP`.
- No legacy route, content opportunity row, recommended action row or lifecycle status needs removal.
- Additive ownership metadata can remain as traceability until a later cleanup phase.

Updated blocker status:

- Visible CTA/source-link ownership now has an inspectable, reversible boundary.
- Default canonical UI authority, lifecycle authority and programmatic source-reference migration remain future phases.

## Phase 3A AgenticMarketingOpportunity Audit

Phase 3A documents `AgenticMarketingOpportunity` before any consolidation into the canonical MOS Opportunity Core. The full audit is `docs/mos/agentic-marketing-opportunity-consumer-audit.md`.

Phase 3A findings:

- `AgenticMarketingOpportunity` currently owns strategic candidate fields and also anchors specialized execution state.
- Detection is legacy-write only: detectors return `DetectedOpportunity`, `AgenticMarketingDecisionEngine` scores it, and `AgenticMarketingOpportunityDetectionService` persists or refreshes Agentic rows.
- Campaign cluster materialization is a second producer. It creates/reuses Agentic opportunities from `CampaignClusterItem` rows and immediately plans Agentic actions.
- Agentic action planning, autonomous selection and execution pipeline preparation all read open Agentic opportunities directly.
- `opportunities.agentic_marketing_opportunity_id` is a useful canonical bridge, but it is passive today. No default app flow depends on it for Agentic execution.
- Execution tables should remain specialized: objectives, actions, action runs, run items, audit logs, execution pipelines, execution assets, approvals, feedback and rollback snapshots must not be flattened into canonical `Opportunity`.

Ownership recommendation:

- Canonical `Opportunity` should own strategic identity, context, title/topic/summary, score breakdown, recommendation/evidence and high-level lifecycle after explicit status mapping.
- `OpportunitySignal` should own observed detector signals from lifecycle, link, locale, answer coverage, SEO/indexability, AI visibility, LLM tracking and campaign-cluster inputs.
- `AgenticMarketingOpportunity` should remain the execution bridge while existing actions and pipelines depend on it. It can later become execution/evidence state or be retired only after all FK-owning consumers move.

Migration stance:

- Do not create an Agentic canonical writer in Phase 3A.
- Do not backfill `opportunities.agentic_marketing_opportunity_id` in Phase 3A.
- Do not change Agentic detection, action planning, autonomous workflow selection or execution pipeline behaviour in Phase 3A.
- Phase 3B should define detector-level output classification: `OpportunitySignal`, canonical `Opportunity`, both or execution-only.

## Phase 3B Agentic Detector Output Mapping

Phase 3B defines the read-only mapping and dedupe contract for Agentic detector outputs. The full contract is `docs/mos/agentic-detector-canonical-mapping.md`.

New diagnostics-only components:

- `AgenticOpportunityCanonicalMappingService` maps a `DetectedOpportunity` plus objective context into canonical preview DTOs.
- `AgenticCanonicalSignalPreview` describes the future `OpportunitySignal` payload shape.
- `AgenticCanonicalOpportunityPreview` describes the future canonical opportunity candidate payload shape.
- `AgenticCanonicalMappingResult` reports classification, capability, missing context, blocked reasons, dedupe key and risk.
- `php artisan mos:map-agentic-detector-outputs` inspects existing rows and optional deterministic samples without running detectors or writing records.

Detector ownership stance:

| Detector/output | Classification | Canonical ownership | Specialized ownership |
| --- | --- | --- | --- |
| `refresh_lifecycle` | `signal_only` | Decay/lifecycle evidence belongs in `OpportunitySignal`. | Agentic execution state remains in objectives/actions/pipelines. |
| `internal_links` | `signal_only` | Link opportunity evidence belongs in `OpportunitySignal`. | Tactical link execution and action payloads remain specialized. |
| `localization_gaps` | `signal_only` | Locale gap evidence belongs in `OpportunitySignal`. | Agentic locale execution hints remain payload snapshots. |
| `structured_answer_gaps` | `signal_only` | Answer coverage evidence belongs in `OpportunitySignal`. | Generated answer/block assets remain execution assets. |
| `seo_indexability` | `signal_only` | Indexability/schema evidence belongs in `OpportunitySignal`. | Metadata/schema action execution remains specialized. |
| `ai_visibility_gaps` | `signal_only` | AI visibility evidence belongs in `OpportunitySignal`. | Agentic visibility scorecards and actions remain specialized. |
| `llm_tracking_ai_visibility` | `signal_only` | LLM tracking evidence belongs in `OpportunitySignal`. | LLM-specific execution context remains specialized. |
| `content_network_gaps` | `signal_and_opportunity` | May produce both signal and canonical opportunity candidate when content-cluster context exists. | Content-network planning and generated assets remain specialized. |
| `campaign_cluster_action_materializer` | `signal_and_opportunity` | May produce both signal and canonical opportunity candidate when campaign-cluster context and stable payload dedupe exist. | Campaign cluster item/action materialization remains specialized. |

No Phase 3B component persists canonical records, migrates `agentic_marketing_opportunities`, backfills `opportunities.agentic_marketing_opportunity_id`, changes detection service writes, changes action planning, changes autonomous workflow selection or changes execution pipelines.

The source-scoped dedupe contract is versioned as `agentic-detector-output:v1` and includes workspace, objective, detector, Agentic type, site, content, locale, normalized topic/title and stable payload identity. Volatile timestamp-style fields and score refreshes are excluded so repeated diagnostics produce deterministic keys.

## Phase 3D Agentic Guarded Bridge Writer

Phase 3D introduces `AgenticOpportunityBridgeWriter` and `php artisan mos:link-agentic-opportunities` as an opt-in writer boundary. It uses Phase 3C eligibility and only writes canonical bridges for `canonical_link_ready` or `signal_and_canonical_ready` rows.

Compatibility stance:

- Default Agentic detection, action planning and execution pipeline behaviour remain unchanged.
- `AgenticMarketingOpportunity` remains the execution identity for existing actions, action runs, approvals and execution pipelines.
- `opportunities.agentic_marketing_opportunity_id` is populated only by explicit flagged apply.
- `OpportunitySignal` promotion remains future work.
- Duplicate canonical opportunities are blocked by source-scoped dedupe checks and the existing workspace/dedupe uniqueness constraint.
- Rollback is feature-flag and invocation based; legacy Agentic and execution records are retained.

## Phase 3E/3F Agentic Signal Promotion And Validation

Phase 3E promotes selected existing `AgenticMarketingOpportunity` rows into canonical `OpportunitySignal` rows through `AgenticOpportunitySignalPromotionService` and:

```bash
php artisan mos:promote-agentic-opportunity-signals
```

Phase 3F validates those promoted signals through:

```bash
php artisan mos:validate-agentic-opportunity-signals
```

Compatibility stance:

- promoted Agentic signals are source evidence for the canonical opportunity engine, not direct canonical opportunities;
- default production behaviour remains unchanged unless operators explicitly run the promotion command with its feature flag enabled;
- the validation command is read-only and reports total promoted signals, eligible count, linked count, unlinked eligible count, incomplete count, duplicate signal risk, stale source risk, detector breakdown, category breakdown and sample blocked reasons;
- the existing `OpportunityIntelligenceEngine` can consume promoted Agentic signals through its current workspace `OpportunitySignal` query path;
- when the normal engine links promoted Agentic signals, canonical opportunity metadata and `source_signal_summary` preserve Agentic legacy opportunity ids, objective ids and detector keys;
- Agentic actions, action runs, run ledgers, execution pipelines and growth/programmatic execution state remain legacy-owned before dual-read.

Remaining blocker status:

- dual-read selection order is not defined;
- canonical-linked Agentic action planning dedupe is not defined;
- execution pipeline parent/reference continuity is not migrated;
- duplicate promoted signal risk must stay observable before broad rollout.

## Phase 3G Agentic Canonical Dual-Read Read Model

Phase 3G adds `AgenticOpportunityCanonicalReadService` and `AgenticOpportunityCanonicalReadModel` for selected read-only consumers. The read model accepts a legacy `AgenticMarketingOpportunity`, finds a safe linked canonical `Opportunity` through `opportunities.agentic_marketing_opportunity_id`, and exposes strategic fields with field-level provenance.

Compatibility stance:

- default detection persistence remains legacy-only;
- default action planning and autonomous workflow selection remain legacy-only;
- execution pipeline parent references remain `agentic_marketing_opportunities.id`;
- legacy Agentic status remains lifecycle authority;
- canonical context is additive metadata for selected displays and diagnostics;
- existing legacy selection order is preserved.

Migrated consumers:

- objective top-opportunity dashboard display;
- `php artisan mos:inspect-agentic-canonical-read-model` diagnostics.

Still blocked:

- action planner duplicate prevention;
- autonomous selection;
- approval gates and status routes;
- execution jobs and pipeline preparation;
- campaign cluster materialization writers;
- growth/programmatic execution-state migration.

Rollback is code/invocation only because the read model performs no writes.

## Phase 3H Agentic Action Dedupe Diagnostics

Phase 3H adds read-only action dedupe diagnostics for linked `AgenticMarketingOpportunity` and canonical `Opportunity` rows.

Compatibility stance:

- default `AgenticMarketingActionPlanner` selection remains the legacy objective opportunity query filtered to open rows and ordered by legacy priority score;
- default action creation still writes `AgenticMarketingAction` with the legacy Agentic opportunity id as parent;
- autonomous workflow selection and execution pipeline parent references remain unchanged;
- canonical opportunity ids are diagnostic context only;
- no action statuses, payloads, dedupe hashes or execution rows are updated.

The canonical-equivalent action signature is versioned as `mos-agentic-action:v1` and includes workspace id, objective id, legacy Agentic opportunity id, linked canonical opportunity id when safe, detector key, Agentic type, action type, content id, site id, Phase 3B source-scoped dedupe key and normalized title/topic context.

Use:

```bash
php artisan mos:inspect-agentic-action-dedupe
```

The command supports `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=`. It reports inspected rows, linked/legacy-only counts, open action counts, duplicate action risks, safe future canonical-equivalent candidates, signature samples and blocked reasons.

Remaining blocker status:

- execution parent/reference continuity is still required before canonical action ownership;
- lifecycle mapping for `open`, `dismissed` and `completed` remains separate;
- canonical selection by canonical priority fields remains blocked;
- canonical recommended actions must not be created by default until duplicate prevention and execution continuity are implemented.

## Phase 3I Agentic Execution Continuity Diagnostics

Phase 3I adds `AgenticOpportunityExecutionContinuityService` and:

```bash
php artisan mos:inspect-agentic-execution-continuity
```

Compatibility stance:

- `AgenticMarketingOpportunity` remains the execution FK authority.
- Execution pipeline route ids remain legacy Agentic opportunity ids.
- Existing actions, action runs, pipelines, generated assets, approvals, feedback, audit logs and rollback snapshots are not rewritten.
- Canonical opportunity ids may be considered only as additive metadata for future rows.
- Pipeline-local approval/feedback/audit records remain reachable through legacy pipeline ids, not canonical opportunity ids.

The diagnostic reports actions by status, action runs by status, pipelines by status, assets by type/status, approvals by status, feedback count, audit count, generated references, execution payload requirements, canonical field availability, missing fields, safe additive metadata targets, blocked reasons and route/parent dependency samples.

Remaining blocker status:

- guarded planner migration remains blocked while canonical-parent-only lookup would miss legacy execution rows;
- lifecycle mapping remains separate;
- generated asset payload compatibility must be proven before canonical fields enrich new assets;
- explicit parent migration still needs route, approval, rollback and historical record strategy.

## Phase 3M Agentic Planner Experiment

Phase 3M adds a guarded, default-off comparison path:

```bash
php artisan mos:compare-agentic-planner-candidates
```

Compatibility stance:

- production planner selection remains the legacy open Agentic opportunity order;
- canonical experiment order is reported separately and includes Phase 3L-ready rows only;
- blocked rows remain visible with reasons;
- dry-run output is DTO-only and cannot create actions, run items, audit logs or canonical recommended actions;
- the flag `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_EXPERIMENT=false` is off by default.

Remaining blocker status:

- any duplicate open legacy action, signature blocker, continuity blocker or lifecycle ambiguity blocks a future apply phase;
- default planner behaviour must continue to be proven unchanged;
- Phase 3N must define scoped apply rules, observability and rollback before canonical planner selection can be considered.

## Phase 3N Agentic Planner Apply Experiment

Phase 3N defines that scoped apply rule without changing default compatibility:

- command: `php artisan mos:apply-agentic-planner-canonical-experiment`;
- required filters: `--objective=` and `--limit=`;
- apply guard: `--apply` plus `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=true`;
- candidate guard: Phase 3L ready, Phase 3M signature-equivalent, no duplicate open legacy action risk, no Phase 3I continuity blockers and no Phase 3J lifecycle ambiguity/conflict;
- write path: existing `AgenticMarketingActionPlanner` against the legacy `AgenticMarketingOpportunity`;
- metadata: additive `payload.planner_experiment` only.

Compatibility stance remains unchanged: canonical opportunity ids are not execution parents, canonical recommended actions are not created, lifecycle state is not synced and historical rows are not rewritten. Rollback is flag-off and ignoring additive metadata.

## Phase 3O Agentic Planner Apply Audit

Phase 3O adds read-only diagnostics around Phase 3N output:

- `mos:audit-agentic-planner-apply-experiment`;
- `mos:plan-agentic-planner-apply-experiment-rollback`;
- no metadata removal writer by default;
- action ownership remains `AgenticMarketingAction.opportunity_id -> AgenticMarketingOpportunity.id`;
- canonical ids remain metadata only.

Before Phase 3P, scoped audits must show no stale or missing canonical context, no bridge mismatch, no signature mismatch, no readiness regression beyond `metadata_only_ok`, no duplicate open action risk, no Phase 3I continuity risk and no Phase 3J lifecycle conflict.

## Recommended Phase 2B Order

1. Continue with `CompetitorContentOpportunity` signal promotion rollout and observability because it has contained scope and should enrich canonical opportunities rather than create a competing opportunity surface.
2. Use `mos:link-content-opportunities` to link selected open/high-priority `ContentOpportunity` records through the existing `opportunities.content_opportunity_id` bridge while keeping run rows as audit history.
3. Add FAQ signal promotion after a workspace or organization context source is defined for `FaqOpportunityAudit`.

## Recommended Phase 2C Order

1. Start `AgenticMarketingOpportunity` consolidation with Phase 3B detector-output mapping and dry-run diagnostics. Do not migrate records until signal/opportunity classification, bridge dedupe and execution-state ownership are explicit.
2. Reframe `ProgrammaticOpportunity` as specialized expansion state that consumes canonical opportunities rather than replacing them.
3. Keep `LinkOpportunity` outside canonical opportunity persistence unless link work becomes a strategic decision-level workflow.

## Phase 3P Agentic Planner Shadow

Compatibility status remains diagnostic-only. Phase 3P adds `features.mos_agentic_planner_canonical_shadow` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_SHADOW=false` and a read-only command, `mos:shadow-agentic-planner-candidates`.

The shadow report compares legacy planner candidates with canonical-linked candidates and records blockers from Phase 3L readiness, Phase 3O audit status, duplicate risk, continuity risk, lifecycle risk and signature mismatch. It does not change compatibility ratings, provider ownership, routes, lifecycle state or execution parentage.

## Phase 3Q Agentic Planner Default-Selection Preview

Compatibility status remains preview-only. Phase 3Q adds `features.mos_agentic_planner_canonical_default_selection_preview` / `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_PREVIEW=false` and `mos:preview-agentic-planner-default-selection`.

The preview can recommend `eligible for Phase 3R scoped default experiment` only when Phase 3P recommends `continue shadow`, Phase 3O has no risky rows, Phase 3L readiness is complete, Phase 3H signatures match, Phase 3I/3J have no blockers, duplicate action risk is zero, canonical coverage is sufficient and the proposed canonical order exactly matches legacy output. Otherwise compatibility remains `keep legacy` or `blocked`.

## Phase 3S Default-Selection Experiment Audit

Phase 3S adds read-only compatibility diagnostics for Phase 3R actions with `payload.default_selection_experiment`. Compatibility remains `keep legacy` unless every audited row is `clean` or operator-accepted `metadata_only_ok`.

`missing_legacy_parent`, `missing_canonical_context`, `bridge_mismatch`, `preview_regressed`, `shadow_regressed`, `phase_3o_audit_risk`, `readiness_regressed`, `signature_mismatch`, `continuity_risk`, `lifecycle_risk`, `duplicate_risk` and `ownership_risk` all block broader rollout. Rollback remains disabling the Phase 3R flag and ignoring metadata; no metadata removal writer is part of Phase 3S.

## Phase 3T Compatibility

Phase 3T maps compatibility to rollout statuses: `ready_for_scoped_expansion`, `keep_single_objective_scope`, `blocked_by_*`, `insufficient_canonical_coverage`, `order_mismatch` and `no_candidate_scope`.

Compatibility remains diagnostic. A ready status is only a prerequisite for limited multi-objective Phase 3U; it is not global default planner migration and does not approve canonical recommended actions.
