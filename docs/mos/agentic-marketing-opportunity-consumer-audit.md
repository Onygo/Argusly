# AgenticMarketingOpportunity Consumer Audit

Phase 3A audits `AgenticMarketingOpportunity` before any consolidation into the canonical MOS Opportunity Core. This phase does not migrate records, create writers, change execution behaviour or delete `agentic_marketing_opportunities`.

## Phase 3F Signal Consumer Validation

Phase 3F validates the Phase 3E promoted Agentic `OpportunitySignal` rows as consumers of the existing canonical `OpportunityIntelligenceEngine`. It does not change Agentic detection, action planning, autonomous execution or execution pipeline behaviour.

Promoted signal shape:

- source/category/topic/strength/confidence come from the Phase 3B `AgenticCanonicalSignalPreview`;
- workspace, site, content and objective context are copied onto the signal;
- evidence includes detector context plus `legacy_agentic_marketing_opportunity.source_model`, `source_id`, `objective_id`, status, type and legacy dedupe;
- metadata includes `source_model`, `source_id`, `legacy_agentic_marketing_opportunity_id`, `objective_id`, `detector_key`, `agentic_type`, `agentic_status`, `source_scoped_dedupe_key` and `promotion.version=agentic-opportunity-signal-promotion:v1`.

Quality requirements:

- workspace and objective must exist;
- legacy Agentic source row must exist;
- detector key, Agentic type, topic/title, dedupe hash, evidence and metadata must be complete;
- source and category must be valid canonical enum values;
- duplicate signal risk blocks eligibility.

Canonical consumption:

- `OpportunityIntelligenceEngine` already reads all non-deleted `OpportunitySignal` rows in the workspace, so promoted Agentic signals enter the same clustering, scoring and link path as other canonical signals.
- No Agentic-specific opportunity engine is introduced.
- No Agentic validation or migration service creates canonical `Opportunity` rows directly.
- Phase 3F only adds traceability when the normal canonical engine links these signals: canonical `metadata` and `source_signal_summary` preserve Agentic legacy opportunity ids, objective ids and detector keys.

Phase 3R preserves this consumer boundary. It may use canonical-linked ordering inside a guarded command, but actions, execution and route consumers continue to bind the legacy `AgenticMarketingOpportunity`.

Clustering and dedupe assumptions:

- Phase 3E writes one signal per workspace plus source-scoped Phase 3B dedupe key.
- The canonical engine clusters by workspace, category, topic and existing context fields. Promoted Agentic sources/categories are not filtered out.
- Duplicate strategic opportunity risk remains possible if the same Agentic work was also bridged by Phase 3D or represented by another signal with the same dedupe/source id; Phase 3F reports duplicate signal risk instead of repairing it.

Bridge writer and signal promotion interaction:

- Phase 3D bridge writes canonical `Opportunity` rows or links through `opportunities.agentic_marketing_opportunity_id` only when explicitly applied.
- Phase 3E promotion writes `OpportunitySignal` rows only.
- Phase 3F validation inspects promoted signals and existing links. It does not reconcile bridge rows, merge opportunities or create missing links.

Agentic execution state remains untouched because objectives, actions, action runs, run ledgers, execution pipelines, approvals, assets and rollback snapshots still depend on legacy Agentic ids and semantics. Validation is read-only, and canonical opportunity creation remains owned by the normal engine run.

Next blockers before dual-read:

- selection order when both canonical-linked and legacy Agentic opportunities exist;
- action-planning duplicate prevention for canonical-linked Agentic work;
- execution pipeline parent/reference continuity;
- rollout confidence that promoted signals remain complete, non-stale and non-duplicative.

## Model And Table Summary

`App\Models\AgenticMarketingOpportunity` is backed by `agentic_marketing_opportunities`.

Core columns:

- `id`: UUID primary key.
- `objective_id`: required owner, foreign keyed to `agentic_marketing_objectives`.
- `content_id`: optional source content link, also derived from `payload.content_id` by the model.
- `title`: display and action-planning title.
- `type`: normalized `AgenticMarketingOpportunityType`.
- `priority_score`: deterministic Agentic Marketing score, 1 to 100.
- `status`: normalized `AgenticMarketingOpportunityStatus`.
- `payload`: detector evidence, execution hints, recommendation details and downstream planning context.
- `payload_hash`, `dedupe_hash`, `open_dedupe_hash`: legacy dedupe keys maintained by model hooks and migrations.
- timestamps.

Model responsibilities:

- normalizes type/status;
- derives `content_id` from payload when missing;
- calculates dedupe hashes;
- enforces open-row reuse with `createOrReuseOpen()`;
- emits Agentic audit records on create/update;
- relates to objective, content, actions and execution pipelines.

The table is not only detection storage. It is the foreign-key anchor for actions, run items, audit logs, action-run snapshots, execution pipelines, execution assets, growth assets and programmatic detection sources.

## Field Responsibility Map

| Field or relationship | Current responsibility | Canonical target | Specialized target | Migration stance |
| --- | --- | --- | --- | --- |
| `title` | Strategic opportunity display and execution title. | `opportunities.title`; signal evidence title when detector output is signal-like. | Keep copied into execution snapshots and generated asset titles. | migrate now for canonical read/write design; dual-read later in execution consumers. |
| `type` | Agentic detector/action-planning type. | Map to canonical `Opportunity.category` plus metadata subtype. | Keep original Agentic type for action planner and execution asset generation. | dual-read later. |
| `priority_score` | Agentic deterministic score. | `opportunities.priority_score`; component scores from `payload.score_explanation`. | Keep as execution confidence fallback until action planner reads canonical fields. | migrate now for mapping; defer ownership flip. |
| `status` | Agentic opportunity lifecycle: open, dismissed, completed. | High-level lifecycle on `Opportunity.status`. | Execution tables keep action/pipeline statuses separately. | blocked until status semantics are mapped and bidirectional sync rules exist. |
| `payload.signals` | Observed detector evidence from content, lifecycle, SEO, link, locale, AI visibility, LLM tracking and campaign cluster inputs. | `OpportunitySignal.metrics`, `OpportunitySignal.evidence`, `OpportunitySignal.metadata`; also canonical opportunity evidence after clustering. | Execution generators can keep a snapshot for repeatability. | migrate now for signal mapping design. |
| `payload.score_explanation` | Agentic scoring breakdown. | `Opportunity.score_breakdown`, `priority_score`, `confidence_score`, `impact_score`, `effort_score`, `metadata.agentic_score_explanation`. | Keep in execution snapshots for traceability. | migrate now for mapping. |
| `payload.reasoning`, `reason`, `recommendation`, `angle`, `suggested_*` | Recommendation and execution hints. | `Opportunity.summary`, `recommended_actions`, `evidence`. | Action payloads, briefs and drafts keep local copies. | dual-read later. |
| `objective_id` | Agentic goal, workspace, site, audience, approval and budget context. | Canonical opportunity context should store workspace/site directly; objective link can live in metadata or specialized execution state. | Objective remains specialized Agentic execution/governance context. | keep specialized. |
| `content_id` | Optional source content link. | `opportunities.content_id`; `opportunity_signals.content_id`. | Execution assets/actions keep local content refs. | migrate now. |
| `payload_hash`, `dedupe_hash`, `open_dedupe_hash` | Open-row reuse and legacy duplicate protection. | Canonical dedupe should be source scoped and workspace scoped. | Keep while Agentic table remains execution bridge. | keep specialized until bridge migration is complete. |
| `actions()` | Planned executable work. | Canonical opportunity may own strategic recommended actions. | `agentic_marketing_actions` remains specialized execution/action state. | keep specialized. |
| `executionPipelines()` | Brief/draft/asset/approval pipeline. | Canonical opportunity may be the future parent reference. | `agentic_marketing_execution_*` remains execution state. | keep specialized; dual-read later. |

## Lifecycle And Status Map

| Agentic status | Meaning today | Candidate canonical status | Safe to sync now? | Notes |
| --- | --- | --- | --- | --- |
| `open` | Candidate is eligible for action planning and autonomous selection. | `open` or `reviewing` depending on workflow context. | No | Agentic `open` is both detected and executable-input state. |
| `dismissed` | Opportunity should no longer generate open deduped work. | `dismissed` | No | Dismissal can conflict with existing actions, action runs or pipelines. |
| `completed` | Agentic opportunity is no longer open. | `actioned` or `resolved` | No | Completion may be action-level or pipeline-level; canonical meaning must be defined before sync. |

Action statuses are separate: `proposed`, `approved`, `running`, `completed`, `failed`, `dismissed`. Pipeline statuses are separate: `queued`, `running`, `awaiting_approval`, `ready`, `failed` plus approval and publishing-readiness fields. Do not flatten those execution statuses into canonical `Opportunity.status`.

Phase 3J formalizes this as diagnostics only. `mos:inspect-agentic-lifecycle-map` reports lifecycle alignment, conflict, unmapped status and execution ambiguity. `mos:plan-agentic-canonical-action-ownership` proposes future canonical owner metadata only after reading Phase 3H signatures and Phase 3I continuity. Both commands are read-only; they do not sync statuses, create canonical recommended actions, change planner selection, rewrite payloads or alter routes.

Phase 3K adds a default-off future-row metadata writer. When enabled, newly created Agentic execution pipelines, assets, action-run snapshots, generated briefs and generated drafts can carry `canonical_opportunity_context` for traceability. Consumers must still use the legacy Agentic opportunity id for execution routes, policy checks, planner selection, approvals and rollback.

Phase 3L adds planner-readiness diagnostics only. `mos:inspect-agentic-planner-readiness` reports whether a canonical-linked Agentic row has enough safe context for a future guarded planner experiment, but it does not change `AgenticMarketingActionPlanner` selection, action creation, canonical recommended actions, dedupe behaviour, execution parents, routes, lifecycle state or historical payloads.

## Consumer Inventory

| Consumer | Location | Classification | Current responsibility | Migration stance |
| --- | --- | --- | --- | --- |
| Agentic opportunity model | `app/Models/AgenticMarketingOpportunity.php` | lifecycle, legacy compatibility | Normalizes fields, dedupes open rows, audits create/update and owns relationships. | keep specialized |
| Agentic opportunity table migrations | `database/migrations/2026_05_21_130000_create_agentic_marketing_tables.php`, `2026_05_21_131000_normalize_agentic_marketing_dedupe.php` | lifecycle, legacy compatibility | Creates storage, FKs and dedupe indexes. | keep specialized |
| Detection command | `app/Console/Commands/DetectAgenticMarketingOpportunitiesCommand.php` | detection, queue/job | Runs or queues detection for active objectives. | dual-read later |
| Detection job | `app/Jobs/AgenticMarketing/DetectAgenticMarketingOpportunitiesJob.php` | detection, queue/job | Unique queued wrapper around detection service. | dual-read later |
| Detection service | `app/Services/AgenticMarketing/AgenticMarketingOpportunityDetectionService.php` | detection, scoring, lifecycle | Runs detectors, scores candidates, creates/reuses Agentic opportunities, records run items. | migrate now for mapping; writer migration blocked |
| `DetectedOpportunity` DTO | `app/Services/AgenticMarketing/OpportunityDetection/DetectedOpportunity.php` | detection, provider adapter | In-memory candidate before Agentic persistence. | migrate now |
| Detector interface | `app/Services/AgenticMarketing/OpportunityDetection/AgenticMarketingOpportunityDetector.php` | detection, provider adapter | Contract for detector output. | migrate now |
| Refresh detector | `RefreshLifecycleOpportunityDetector` | detection | Emits refresh opportunities from lifecycle/decay signals. | migrate now to signal-first |
| Internal link detector | `InternalLinkOpportunityDetector` | detection | Emits internal link opportunities from link suggestions. | migrate now to signal-first |
| Localization detector | `LocalizationGapOpportunityDetector` | detection | Emits locale expansion opportunities. | migrate now to signal-first |
| Structured answer detector | `StructuredAnswerGapOpportunityDetector` | detection | Emits answer coverage gaps. | migrate now to signal-first |
| SEO indexability detector | `SeoIndexabilityOpportunityDetector` | detection | Emits metadata/schema/indexability work. | migrate now to signal-first |
| Content network detector | `ContentNetworkGapOpportunityDetector` | detection | Emits new article/content network gaps. | migrate now; may create canonical opportunities when fully materialized |
| AI visibility detector | `AiVisibilityGapOpportunityDetector` | detection | Emits AI visibility opportunities from stored metrics. | migrate now to signal-first |
| LLM tracking detector | `LlmTrackingAiVisibilityOpportunityDetector` | detection | Emits brand mention, competitor dominance, citation and answer-block gaps. | migrate now to signal-first |
| Decision engine | `app/Services/AgenticMarketing/AgenticMarketingDecisionEngine.php` | scoring | Adds deterministic score explanation to detected candidates. | migrate now; score formula can map to canonical score fields |
| Action planner | `app/Services/AgenticMarketing/AgenticMarketingActionPlanner.php` | action planning, approval | Reads open Agentic opportunities and creates/reuses `AgenticMarketingAction`. | keep specialized |
| Action model | `app/Models/AgenticMarketingAction.php` | action planning, execution, approval | Stores proposed/approved/running/completed action state and dedupe. | keep specialized |
| Action executor | `app/Services/AgenticMarketing/AgenticMarketingActionExecutor.php` | execution, asset generation | Executes approved Agentic actions into drafts, proposals, metadata and content changes. | keep specialized |
| Action execution job | `app/Jobs/AgenticMarketing/ExecuteAgenticMarketingActionJob.php` | execution, queue/job | Unique queued action execution by Agentic action id. | keep specialized |
| Action-run logger/model | `AgenticActionRunLogger`, `AgenticActionRun` | execution, analytics/reporting, approval | Mirrors action lifecycle, policy snapshots, outputs and learning hooks. | keep specialized |
| Learning signal service | `app/Services/AgenticMarketing/AgenticLearningSignalService.php` | analytics/reporting | Stores learning signals back on actions/opportunities and counts repeated topics. | defer |
| Agentic audit logger/model | `AgenticMarketingAuditLogger`, `AgenticMarketingAuditLog` | analytics/reporting, lifecycle | Stores objective/opportunity/action/run audit events. | keep specialized |
| App Agentic controller | `app/Http/Controllers/App/AppAgenticMarketingController.php` | UI display, detection, action planning, approval, execution | Lists objectives/actions/runs, scans objectives, approves/dismisses/executes/retries actions. | keep specialized |
| App execution controller | `app/Http/Controllers/App/AppOpportunityExecutionController.php` | UI display, execution, approval, asset generation | Displays Agentic execution pipelines and handles prepare/approve/reject/feedback/retry. | keep specialized |
| Agentic routes | `routes/app.php` | UI display, detection, approval, execution | Exposes objective, action, approval, workflow, orchestration, campaign-cluster and execution routes. | keep specialized |
| Agentic index/objective/action views | `resources/views/app/agentic-marketing/index.blade.php`, `objectives/show.blade.php`, `actions/show.blade.php` | UI display, approval, execution | Show opportunities through objectives/actions and link to execution. | dual-read later |
| Execution view | `resources/views/app/agentic-marketing/execution/show.blade.php` | UI display, execution, approval | Displays pipeline assets, approvals, feedback and readiness. | keep specialized |
| Execution pipeline service | `app/Services/AgenticMarketing/ExecutionPipeline/OpportunityExecutionPipelineService.php` | execution, approval, asset generation | Creates pipelines, runs, run items, approvals, readiness, retry and feedback. | keep specialized |
| Execution asset generator | `app/Services/AgenticMarketing/ExecutionPipeline/OpportunityExecutionAssetGenerator.php` | asset generation, execution | Creates briefs, drafts, scorecards, campaign plans, answer blocks, schema, metadata, links and social copy. | keep specialized |
| Execution pipeline models | `AgenticMarketingExecutionPipeline`, `ExecutionAsset`, `ExecutionApproval`, `ExecutionFeedback`, `ExecutionAuditLog` | execution, approval, analytics/reporting | Pipeline-local state and approval/audit ledger. | keep specialized |
| Prepare execution job | `PrepareOpportunityExecutionPipelineJob` | execution, queue/job | Resolves Agentic opportunity id and prepares pipeline. | keep specialized; dual-read later |
| Generate execution asset job | `GenerateOpportunityExecutionAssetJob` | asset generation, queue/job | Updates execution asset payload/status. | keep specialized |
| Strategic planning engine | `app/Services/AgenticMarketing/StrategicPlanning/StrategicPlanningEngine.php` | action planning, asset generation | Builds cluster proposal from Agentic opportunity payload/objective. | keep specialized |
| Campaign orchestration engine | `app/Services/AgenticMarketing/CampaignOrchestration/AutonomousCampaignOrchestrationEngine.php` | action planning, asset generation | Builds campaign plan assets from Agentic opportunity. | keep specialized |
| AI visibility scoring engine | `app/Services/AgenticMarketing/VisibilityScoring/AIVisibilityScoringEngine.php` | scoring, asset generation | Produces per-article scorecard for execution assets. | keep specialized |
| Autonomous workflow engine | `app/Services/AgenticMarketing/AutonomousMarketingWorkflowEngine.php` | detection, action planning, approval, execution | Runs canonical Opportunity Intelligence, then Agentic detection, planning, gating and optional queuing. | blocked |
| Autonomous workflow job | `RunAutonomousMarketingWorkflowJob` | queue/job, execution | Unique queued wrapper around autonomous workflow engine. | blocked |
| Autonomous command | `app/Console/Commands/RunAutonomousAgenticMarketingCommand.php` | execution, queue/job, approval | Selects open Agentic opportunities by workspace, plans actions and dispatches eligible autonomous actions. | keep specialized |
| Workflow governance models/controllers/views | `AgenticMarketingWorkflowRule`, `AgenticMarketingWorkflowOverride`, workflow controller/views | policy/authorization, approval | Rules and overrides govern autonomous action generation and approval. | keep specialized |
| Approval gate and policy engine | `AgenticApprovalGate`, `AgenticMarketingApprovalPolicyEngine` | policy/authorization, approval | Decides approval, safety and autonomy for actions. | keep specialized |
| Laravel policy | `app/Policies/AgenticMarketingPolicy.php` | policy/authorization | Authorizes objective, opportunity, action and run access through organization context. | keep specialized |
| Campaign cluster action materializer | `app/Services/CampaignClusterEngine/CampaignClusterActionMaterializer.php` | provider adapter, action planning, detection | Creates/reuses Agentic opportunities from campaign cluster items, then plans actions. | blocked |
| Growth program orchestrator | `app/Services/Growth/GrowthProgramOrchestrator.php` | analytics/reporting, execution, legacy compatibility | Attaches Agentic opportunities as growth assets and derives metrics. | defer |
| Growth asset model/view | `app/Models/GrowthAsset.php`, `resources/views/app/growth-programs/show.blade.php` | UI display, analytics/reporting, legacy compatibility | Has `agentic_opportunity` role and links to Agentic execution view. | defer |
| Programmatic opportunity detector | `app/Services/Growth/ProgrammaticOpportunityDetector.php` | provider adapter, scoring | Detects programmatic patterns from Agentic opportunities as source models. | defer |
| MOS provider | `app/Services/Mos/Opportunity/Providers/AgenticMarketingOpportunityProvider.php` | provider adapter, legacy compatibility | Read-only adapter to `CanonicalOpportunityCandidate`; reports canonical and signal payload capability. | migrate now for audit/readiness only |
| Canonical Opportunity bridge | `app/Models/Opportunity.php`, `opportunities.agentic_marketing_opportunity_id` | legacy compatibility | Existing nullable FK from canonical Opportunity to Agentic opportunity. | migrate now for bridge analysis; no writer in Phase 3A |
| Tests | `tests/Feature/AgenticMarketing`, `tests/Feature/Growth`, `tests/Unit/Mos` | test-only | Cover detection, planning, approval, execution, workflows, growth and provider mapping. | migrate now for coverage planning |

## Detection Flow Map

1. `agentic-marketing:detect-opportunities`, queued detection job, objective scan UI or autonomous workflow invokes `AgenticMarketingOpportunityDetectionService`.
2. The service creates an `AgenticMarketingRun` with payload type `opportunity_detection`.
3. For each detector, the service creates an `AgenticMarketingRunItem` of type `detection`, runs `detect($objective)`, records detected count or failure.
4. Detectors return `DetectedOpportunity` DTOs with title, Agentic type, priority score, payload and optional content id.
5. `AgenticMarketingDecisionEngine` recalculates score and adds `payload.score_explanation`.
6. Candidates are sorted by priority and persisted through `AgenticMarketingOpportunity::createOrReuseOpen()`.
7. Existing open rows are refreshed with updated title, priority and payload.
8. The run result stores created/reused counts and Agentic opportunity ids.

Canonical implication: most detector outputs are observed signals before they are strategic opportunities. The default future path should emit `OpportunitySignal` first, then let `OpportunityIntelligenceEngine` cluster/create canonical `Opportunity`. Fully materialized campaign-cluster or content-network outputs may emit both a signal and a canonical opportunity when they already represent one reviewable strategic work item.

## Execution Flow Map

Action planning:

1. `AgenticMarketingActionPlanner::planForObjective()` loads open Agentic opportunities by priority.
2. `planForOpportunity()` creates a planning run item and maps Agentic type to one or more `AgenticMarketingAction` rows.
3. Action payloads embed objective context, content/site/locale, recommendation text, approval policy, prerequisites, source opportunity type and score.

Manual action execution:

1. UI approves/dismisses/executes/retries `AgenticMarketingAction`.
2. Approval gate validates whether execution is allowed.
3. `ExecuteAgenticMarketingActionJob` calls `AgenticMarketingActionExecutor`.
4. Executor creates drafts, review artifacts, metadata/schema/link proposals and action run snapshots. Nothing is published automatically by this flow.

Execution pipeline:

1. User opens `app.agentic-marketing.opportunities.execution.show` for an Agentic opportunity.
2. Prepare action runs `OpportunityExecutionPipelineService::prepare()` inline or through `PrepareOpportunityExecutionPipelineJob`.
3. Service creates `AgenticMarketingRun`, `AgenticMarketingExecutionPipeline`, execution run item, generated assets and approval rows.
4. `OpportunityExecutionAssetGenerator` creates a brief, draft and proposal assets from Agentic opportunity payload/objective/content.
5. Asset approval/rejection/feedback mutates pipeline-local state and recalculates publishing readiness.

Autonomous workflow:

1. `AutonomousMarketingWorkflowEngine` first runs canonical opportunity intelligence.
2. It then runs Agentic detection separately.
3. It plans actions from open Agentic opportunities, applies workflow rules/overrides, gates actions and optionally queues eligible non-publication-like work.

Canonical implication: action and pipeline state should remain specialized. Canonical `Opportunity` should become the strategic parent/source; execution tables should retain their own status, approvals, generated assets, rollback snapshots and audit logs.

## UI Route Map

| Route group | Routes | Controller | Responsibility | Migration stance |
| --- | --- | --- | --- | --- |
| Agentic dashboard | `GET /agentic-marketing` | `AppAgenticMarketingController@index` | Objective/action/run summary, autonomous settings and eligible action display. | dual-read later |
| Objectives | create/store/show/edit/update/delete/scan | `AppAgenticMarketingController` | Objective management and scan orchestration. | keep specialized |
| Actions | show/approve/dismiss/execute/retry | `AppAgenticMarketingController` | Action display, approval and execution queueing. | keep specialized |
| Approval inbox | `/agentic-marketing/approvals*` | `AppAgenticApprovalInboxController` | Action-run approval workflow. | keep specialized |
| Orchestration | `/agentic-marketing/orchestration*` | `AppAgentOrchestrationController` | Agent orchestration runs/tasks. | keep specialized |
| Workflows | `/agentic-marketing/workflows*` | `AppAutonomousMarketingWorkflowController` | Workflow rules, overrides and manual workflow runs. | keep specialized |
| Campaign clusters | `/agentic-marketing/campaign-clusters*` | `AppCampaignClusterController` | Campaign cluster planning and materialization into Agentic actions. | blocked |
| Execution pipeline | `/agentic-marketing/opportunities/{opportunity}/execution*`, execution asset/pipeline posts | `AppOpportunityExecutionController` | Pipeline display, prepare, approve/reject, feedback, retry. | keep specialized |
| Canonical opportunity intelligence | `/opportunity-intelligence*` | `AppOpportunityIntelligenceController` | Canonical opportunity display and execution-plan creation. | canonical target; not a replacement yet |
| Growth programs | `/growth-programs*` | `AppGrowthProgramController` | Displays Agentic opportunities as growth assets and links to execution view. | defer |

## Canonical Bridge Analysis

`opportunities.agentic_marketing_opportunity_id` already exists and `Opportunity::agenticMarketingOpportunity()` belongs to `AgenticMarketingOpportunity`. The bridge is useful but currently passive:

- no Phase 3A writer should populate or backfill it;
- Agentic detection still writes `agentic_marketing_opportunities`;
- Agentic action planning and autonomous execution query Agentic rows directly;
- execution pipeline FKs point to Agentic opportunity ids;
- MOS provider can normalize an Agentic row to a canonical candidate but remains read-only;
- growth/programmatic consumers can still treat Agentic rows as source models.

Bridge risk: if canonical rows are created while Agentic rows still drive action planning, duplicate strategic opportunities and duplicate recommended actions can appear unless source-scoped dedupe and canonical-equivalent action signatures are designed first.

Phase 3C adds read-only bridge eligibility diagnostics through `AgenticOpportunityBridgeEligibilityService` and `php artisan mos:inspect-agentic-opportunity-bridges`. The diagnostics reuse the Phase 3B mapping service, inspect existing bridge links and dedupe matches, and report execution-state dependencies without creating or linking records.

Bridge recommendation:

- use `opportunities.agentic_marketing_opportunity_id` as traceability for one linked canonical strategic opportunity per legacy Agentic row;
- keep `agentic_marketing_opportunities.id` stable for all existing execution state;
- add dual-read only after canonical rows exist and selection order is documented;
- do not repoint execution FKs until every execution consumer can resolve the canonical parent and preserve the legacy execution snapshot.

## Duplication Risks

- Detection can produce both Agentic rows and canonical opportunities for the same signal if a future writer is added without disabling or bridging legacy persistence.
- Autonomous workflow currently runs canonical Opportunity Intelligence and Agentic detection in the same transaction; this can create parallel opportunity concepts for the same workspace/topic.
- Campaign cluster materialization creates Agentic opportunities independently from canonical content/campaign opportunity rows.
- Phase 3C specifically reports canonical opportunities already linked to the same Agentic row, canonical opportunities with matching source-scoped dedupe but no bridge, and campaign-cluster materialization rows that may already represent the strategic opportunity.
- Growth programs can attach both canonical `Opportunity` and legacy Agentic opportunity assets.
- Programmatic detector can create programmatic opportunities from both canonical and Agentic sources.
- Recommended/action planning can duplicate work if canonical opportunities get their own recommended actions while Agentic actions remain open.
- Lifecycle sync is ambiguous because Agentic `completed` may mean actioned, resolved or only no-longer-open.
- Execution asset generation reads raw Agentic payload fields; canonical-only records would lose required execution hints unless payload snapshots are preserved.

## Proposed Canonical Ownership Model

Canonical `Opportunity` should own:

- strategic opportunity identity;
- workspace/site/content/campaign context;
- category/topic/title/summary;
- priority, confidence, impact, urgency, effort and score breakdown;
- high-level lifecycle after explicit status mapping;
- evidence and source signal summary;
- recommended strategic action descriptions;
- bridge metadata to legacy Agentic opportunity id while execution is still legacy-backed.

`OpportunitySignal` should own:

- upstream observed signals from lifecycle, internal link, locale, answer coverage, SEO/indexability, AI visibility, LLM tracking and campaign-cluster inputs;
- source model/id and detector metadata;
- observed metrics and evidence snapshots;
- signal strength/confidence;
- source-scoped dedupe.

Detection output rule:

- Signal-like detectors should emit `OpportunitySignal` first: refresh, internal links, localization, structured answer gaps, SEO indexability, AI visibility and LLM tracking.
- Materialized strategic work items may emit both: content network new article gaps and campaign-cluster materialization, but only when they have stable workspace/site/topic/context and dedupe policy.
- Canonical `Opportunity` creation should remain owned by the canonical opportunity intelligence path, not by detector adapters directly, unless a later phase adds an explicit, tested writer.

## Proposed Execution-State Ownership Model

Specialized Agentic execution should keep:

- `AgenticMarketingObjective` for goal, approval mode, budget and workflow context;
- `AgenticMarketingAction` for executable work, status, credits, approval timestamps, result and retry state;
- `AgenticActionRun` for policy snapshots and execution telemetry;
- `AgenticMarketingRun` and `AgenticMarketingRunItem` for detection/planning/execution run ledgers;
- `AgenticMarketingAuditLog` for Agentic-specific audit history;
- `AgenticMarketingExecutionPipeline` and related asset/approval/feedback/audit tables for generated execution assets and publishing readiness;
- rollback snapshots and generated briefs/drafts/client refs.

Future execution records can link to canonical opportunities, but they should not be flattened into `opportunities`. Canonical opportunities can answer “what strategic work should exist?”; Agentic execution tables answer “what was planned, approved, generated, queued, retried or audited?”

## Migration Phases

| Phase | Goal | Behaviour change? | Notes |
| --- | --- | --- | --- |
| 3A | Complete this audit and update MOS docs. | No | Documentation only. |
| 3B | Define Agentic detector-to-`OpportunitySignal`/`Opportunity` mapping and dedupe policy. | No | Produce mapping tests around provider DTOs and signal payload shape only. |
| 3C | Add dry-run diagnostics for Agentic canonical bridge eligibility. | No | Inspect existing Agentic rows, missing context, duplicate canonical risks and execution blockers. |
| 3D | Add explicit, default-off bridge writer if diagnostics are safe. | Default no | Writer must be dry-run-first, idempotent and source scoped; no execution consumers move yet. |
| 3E | Add dual-read read model for Agentic opportunities. | No default change | UI/context/growth consumers can display canonical-safe fields with legacy fallback. |
| 3F | Plan lifecycle mapping and action/recommended-action dedupe. | No | Resolve `open`/`dismissed`/`completed` semantics and duplicate action risks. |
| 3G | Move detection outputs to canonical signals/opportunities behind a feature flag. | Guarded | Legacy Agentic row creation remains available until execution consumers are moved. |
| 3H | Repoint action planning selection to canonical-linked Agentic execution state. | Guarded | Must preserve objective, approval, budget and existing action dedupe. |
| 3I | Repoint execution pipeline parent references or add canonical parent fields. | Guarded | Do not rewrite historical pipeline state without a rollback plan. |
| 3J | Retire or archive legacy Agentic opportunity identity only after all consumers are canonical-safe. | Yes | Do not delete `agentic_marketing_opportunities` until no execution FK depends on it. |

## Blockers

- Execution tables and jobs foreign-key or resolve by `agentic_marketing_opportunities.id`.
- Action dedupe requires `opportunity_id`; moving strategic ownership without action dedupe would duplicate work.
- The autonomous workflow currently runs canonical and Agentic opportunity generation side by side.
- Campaign cluster materialization is an active Agentic opportunity producer outside the detector service.
- Lifecycle mapping is not safe: `completed` lacks a one-to-one canonical equivalent.
- Objective context, approval mode and budget governance are specialized and not represented by canonical `Opportunity`.
- Execution asset generator needs payload fields that are not guaranteed on canonical opportunities.
- Growth/programmatic consumers can already consume both canonical and Agentic sources.
- Existing provider is read-only and cannot be treated as a migration writer.

## Verification

No production code was changed in Phase 3A. Relevant existing tests were run after the documentation update:

```bash
php artisan test tests/Feature/AgenticMarketing
php artisan test tests/Feature/Mos tests/Unit/Mos
php artisan list mos
```

Results:

- `php artisan test tests/Feature/AgenticMarketing`: passed, 124 tests and 813 assertions.
- `php artisan test tests/Feature/Mos tests/Unit/Mos`: passed, 87 tests and 650 assertions.
- `php artisan list mos`: passed and listed the existing MOS namespace commands, including provider inspection, competitor signal promotion/validation and ContentOpportunity diagnostics/writers.

## Recommended Next Phase

Proceed to Phase 3B: design the Agentic detector output contract and canonical mapping before writing any data. The next phase should define, for each detector and for campaign-cluster materialization, whether the output is:

- `OpportunitySignal` only;
- canonical `Opportunity` only;
- both signal and opportunity;
- execution-only state linked to a canonical opportunity.

Phase 3B should also define source-scoped dedupe, required context, bridge eligibility and action-dedupe safeguards before any writer is added.

## Phase 3B Follow-Up: Detector Output Mapping

Phase 3B adds `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService` and read-only preview DTOs for Agentic detector outputs. The implementation remains diagnostics-only: it does not run detectors, create `Opportunity` rows, create `OpportunitySignal` rows, update `AgenticMarketingOpportunity`, dispatch queues or alter execution behaviour.

Detector classification is now explicit:

| Detector/output | Classification | Canonical target |
| --- | --- | --- |
| `refresh_lifecycle` | `signal_only` | `OpportunitySignal` |
| `internal_links` | `signal_only` | `OpportunitySignal` |
| `localization_gaps` | `signal_only` | `OpportunitySignal` |
| `structured_answer_gaps` | `signal_only` | `OpportunitySignal` |
| `seo_indexability` | `signal_only` | `OpportunitySignal` |
| `ai_visibility_gaps` | `signal_only` | `OpportunitySignal` |
| `llm_tracking_ai_visibility` | `signal_only` | `OpportunitySignal` |
| `content_network_gaps` | `signal_and_opportunity` | `OpportunitySignal` plus canonical opportunity candidate when cluster context exists |
| `campaign_cluster_action_materializer` | `signal_and_opportunity` | `OpportunitySignal` plus canonical opportunity candidate when campaign-cluster context and stable payload dedupe exist |

The mapping service returns `AgenticCanonicalMappingResult` with signal and/or opportunity previews, missing context, blocked reasons, risk level and a source-scoped dedupe key. Dedupe is versioned as `agentic-detector-output:v1` and scoped by workspace, objective, detector, Agentic type, site, content, locale, normalized topic/title and a stable payload fingerprint. Volatile timestamp-style fields and score refreshes are intentionally excluded from the fingerprint.

The diagnostics command is:

```bash
php artisan mos:map-agentic-detector-outputs
```

It supports `--workspace=`, `--objective=`, `--detector=`, `--limit=` and `--sample`. The command inspects existing Agentic rows and optional deterministic samples without running live detectors or writing canonical records.

The detailed mapping and dedupe contract is documented in `docs/mos/agentic-detector-canonical-mapping.md`.

Phase 3B verification passed: scoped Pint, PHP lint for new Phase 3B files, 8 new mapping tests with 95 assertions, existing `tests/Feature/AgenticMarketing` with 124 tests and 813 assertions, existing `tests/Feature/Mos tests/Unit/Mos` with 95 tests and 745 assertions, and `php artisan list mos`.

## Updated Next Phase

Proceed to Phase 3C: bridge eligibility diagnostics for existing `AgenticMarketingOpportunity` rows. Phase 3C should compare mapped detector output context against existing canonical `Opportunity` rows and the passive `opportunities.agentic_marketing_opportunity_id` bridge, then report duplicate strategic opportunity risks, missing context and execution blockers. It should remain read-only and should not add a writer.

## Phase 3D Follow-Up: Guarded Bridge Writer

Phase 3D adds an explicit, default-off bridge writer for selected existing `AgenticMarketingOpportunity` rows. It remains outside default app flows: detection, action planning, autonomous execution, execution pipelines, Agentic actions and legacy rows continue to behave as before.

Eligible Phase 3C statuses:

- `canonical_link_ready`
- `signal_and_canonical_ready`

Rows reported as `missing_context`, `duplicate_risk`, `blocked` or `execution_blocked` are not eligible for canonical bridge writing. `execution_blocked` rows may still be signal-promotion candidates in a later phase, but Phase 3D does not write signals.

Writer input contract:

- legacy `AgenticMarketingOpportunity`;
- optional existing canonical `Opportunity`;
- explicit dry-run/apply intent;
- optional operator context for command/operator traceability.

Create vs link rules:

- Existing rows linked through `opportunities.agentic_marketing_opportunity_id` are reported as `already_linked`.
- A canonical `Opportunity` is created only when Phase 3C eligibility is canonical-ready, required Phase 3B preview fields are present and no duplicate canonical row is found.
- A canonical bridge link may be written only with explicit `--apply` and the feature flag enabled.
- No `AgenticMarketingOpportunity`, `AgenticMarketingAction`, execution pipeline or `OpportunitySignal` row is updated.

Dedupe rules:

- The writer reuses the Phase 3B source-scoped dedupe key as `opportunities.dedupe_hash`.
- Existing canonical rows with the same workspace/dedupe bridge risk are reported instead of creating a duplicate.
- Multiple canonical rows linked to one legacy Agentic row remain `duplicate_risk` and require manual resolution.

Execution-state continuity policy:

- `agentic_marketing_opportunities.id` remains the execution authority for actions, action runs, approvals, growth/programmatic references and execution pipelines.
- The canonical bridge is passive traceability only in Phase 3D.
- Canonical `Opportunity` creation stores the legacy payload snapshot, detector key, Agentic type/status, source-scoped dedupe key and an execution continuity note in metadata/evidence.

Feature flag strategy:

- `features.mos_agentic_marketing_opportunity_bridge_writer` defaults to `false`.
- It is backed by `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER`.
- Dry-run works with the flag disabled.
- Apply is blocked unless the flag is enabled and the operator passes `--apply`.

Rollback strategy:

- Disable `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER`.
- Stop invoking `php artisan mos:link-agentic-opportunities`.
- Optionally clear `opportunities.agentic_marketing_opportunity_id` only for rows created/linked by this writer if no downstream canonical consumers were enabled.
- Do not delete legacy Agentic rows or execution records.

See `docs/mos/agentic-opportunity-bridge-writer.md` for the command and writer contract.

## Phase 3E Follow-Up: Guarded Signal Promotion

Phase 3E adds a separate, default-off promotion path from existing `AgenticMarketingOpportunity` rows into canonical `OpportunitySignal` rows. It does not move Agentic detection persistence, action planning, execution pipelines or autonomous workflows onto canonical signals by default.

Signal-promotion eligible detector outputs are the Phase 3B signal-capable mappings:

| Detector/output | Promotion eligibility |
| --- | --- |
| `refresh_lifecycle` | eligible as `signal_only` |
| `internal_links` | eligible as `signal_only` |
| `localization_gaps` | eligible as `signal_only` |
| `structured_answer_gaps` | eligible as `signal_only` |
| `seo_indexability` | eligible as `signal_only` |
| `ai_visibility_gaps` | eligible as `signal_only` |
| `llm_tracking_ai_visibility` | eligible as `signal_only` |
| `content_network_gaps` | eligible as `signal_and_opportunity` when Phase 3B context is complete |
| `campaign_cluster_action_materializer` | eligible as `signal_and_opportunity` when Phase 3B context and stable payload dedupe are complete |

The write contract is implemented by `AgenticOpportunitySignalPromotionService`. It accepts a legacy Agentic opportunity, optional operator context and explicit dry-run/apply intent. The service calls `AgenticOpportunityCanonicalMappingService`, requires `canEmitSignal`, blocks missing context or blocked mappings, and upserts `OpportunitySignal` by workspace plus the Phase 3B source-scoped dedupe key. It never creates `Opportunity`, never links `Opportunity`, never updates `AgenticMarketingOpportunity`, and never updates Agentic actions or execution pipeline rows.

Dedupe rules:

- The Phase 3B key remains the canonical signal `dedupe_hash`.
- The lookup scope is `opportunity_signals.workspace_id` plus `dedupe_hash`.
- Repeated apply updates the same signal and reports `already_current` when the persisted payload already matches.
- Score, metrics, evidence or metadata refreshes may update the signal payload.
- Timestamp-only changes do not create new signal keys because volatile timestamp fields are excluded from the Phase 3B fingerprint.
- Different objective, site, content, detector or Agentic type contexts keep separate dedupe keys.

Metadata and evidence strategy:

- Phase 3B signal preview metrics, evidence and metadata are reused as the base payload.
- Legacy traceability is stored in signal evidence and metadata: source model, source id, legacy Agentic opportunity id, objective id, detector key, Agentic type/status, content/site context and source-scoped dedupe key.
- Promotion metadata is versioned as `agentic-opportunity-signal-promotion:v1` and records Phase 3E, promoted-at, optional promoted-by, legacy id, objective id, detector key and dedupe key.
- Existing `promoted_at` metadata is preserved on updates so repeat applies do not cause timestamp churn.

Interaction with Phase 3D:

- Phase 3E does not require an existing canonical `Opportunity` bridge.
- Rows that inspect as `signal_ready` or `signal_and_canonical_ready` can be promoted as signals.
- Rows that are `execution_blocked` for bridge writing may still be promoted when Phase 3B mapping is signal-safe.
- Duplicate canonical opportunity risk does not block signal promotion unless the signal dedupe key itself points at the wrong signal scope.
- Phase 3D continues to own only guarded canonical bridge writes; Phase 3E owns only signal promotion.

Canonical opportunity creation remains owned by `OpportunityIntelligenceEngine`. Promoted Agentic evidence enters canonical Opportunity Intelligence as source signals first, where existing clustering, scoring and opportunity generation rules decide whether and how to create or refresh canonical `Opportunity` records. Phase 3E intentionally avoids direct opportunity creation so Agentic execution state and canonical opportunity lifecycle can evolve independently.

Feature flag strategy:

- `features.mos_agentic_marketing_opportunity_signal_promotion` defaults to `false`.
- It is backed by `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_SIGNAL_PROMOTION`.
- Dry-run works with the flag disabled.
- Apply requires both explicit `--apply` and the feature flag enabled.
- No default production app flow invokes signal promotion in Phase 3E.

Command:

```bash
php artisan mos:promote-agentic-opportunity-signals
```

Options are `--apply`, `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=`. The command reports inspected, signal eligible, would-create, would-update, created, updated, already-current, missing-context, blocked, failed and dedupe samples.

Rollback strategy:

- Disable `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_SIGNAL_PROMOTION`.
- Stop invoking `php artisan mos:promote-agentic-opportunity-signals`.
- If necessary, remove or soft-delete Phase 3E-promoted `OpportunitySignal` rows by metadata `promotion.version = agentic-opportunity-signal-promotion:v1`.
- Do not delete or mutate legacy Agentic opportunities, actions, approvals, runs or execution pipelines.

## Phase 3G Follow-Up: Canonical Dual-Read Read Model

Phase 3G adds `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalReadService` as a read-only adapter for legacy `AgenticMarketingOpportunity` rows with an optional linked canonical `Opportunity`. It does not write bridges, promote signals, run detectors, plan actions, dispatch queues or update lifecycle state.

Safe dual-read consumers migrated now:

- Agentic objective detail summary cards/top-opportunity display in `AppAgenticMarketingController::showObjective` and `resources/views/app/agentic-marketing/objectives/show.blade.php`.
- Read-only diagnostics through `php artisan mos:inspect-agentic-canonical-read-model`.

Consumers that remain legacy in Phase 3G:

- `AgenticMarketingActionPlanner`;
- `AutonomousMarketingWorkflowEngine` selection;
- `AgenticMarketingOpportunityDetectionService`;
- `OpportunityExecutionPipelineService` and execution asset generation;
- execution jobs, action runs, run items and approval gates;
- status update routes and action buttons;
- campaign cluster materialization writers;
- growth/programmatic writers or queue builders that own execution state.

Field-level fallback rules:

- Canonical may enrich strategic display fields only when exactly one safe `opportunities.agentic_marketing_opportunity_id` bridge exists: title, summary, category, priority, confidence, impact, effort, urgency, recommended actions, evidence and source signal summary.
- Legacy remains authoritative for id, route identity, objective id, Agentic type, Agentic status, action-planning context, execution state, payload execution hints and approval/budget/governance context.
- Missing canonical values fall back to legacy payload/model values.
- Lifecycle/status always reports legacy provenance in Phase 3G.

Provenance rules:

- Each read-model field records `canonical`, `legacy`, `canonical_context` or `legacy_context`.
- Canonical id is additive metadata and never replaces the legacy route id.
- The read model exposes `migrationReadiness` flags and `blockedReasons` so display consumers can identify enriched, fallback and blocked rows.

Selection order rules:

- Existing legacy query order is preserved. Phase 3G does not re-sort by canonical score when both canonical and legacy rows exist.
- Ambiguous bridges are blocked for enrichment and displayed through legacy fallback.
- The read model does not de-duplicate legacy/canonical pairs for action planning.

Blocked consumers:

- Any consumer that creates actions, changes statuses, approves work, dispatches queues, selects autonomous work, prepares execution pipelines or materializes campaign clusters remains blocked until selection order, action duplicate prevention, execution reference continuity and lifecycle mapping are defined.

Rollback plan:

- Revert the objective display consumer to pass/use raw `AgenticMarketingOpportunity` rows.
- Stop invoking `php artisan mos:inspect-agentic-canonical-read-model`.
- No data rollback is required because Phase 3G performs no writes and does not remove `agentic_marketing_opportunities`.

## Phase 3H Follow-Up: Action Dedupe And Selection Planning

Phase 3H adds diagnostics for Agentic action dedupe without changing action planning, autonomous selection, execution parents, statuses or recommended-action writes.

Current `AgenticMarketingActionPlanner` selection query:

- `planForObjective()` uses `$objective->opportunities()`.
- It filters `agentic_marketing_opportunities.status = open`.
- It orders by `priority_score` descending.
- It chunks by legacy Agentic opportunity id and calls `planForOpportunity()` for each row.
- It does not query `opportunities`, does not prefer canonical priority fields and does not collapse linked legacy/canonical rows.

Current action dedupe keys:

- `AgenticMarketingAction::createOrReuseOpen()` requires `opportunity_id`.
- It hashes the normalized action type plus the action payload hash into `dedupe_hash`.
- It reuses only open actions with the same legacy `opportunity_id` and `dedupe_hash`.
- `payload_hash`, `dedupe_hash` and `open_dedupe_hash` are maintained by model hooks.

Current action source fields and payload references:

- Action rows store `objective_id`, `opportunity_id`, optional `run_id`, optional `content_id`, `action_type`, `status`, `payload`, `result` and execution/credit timestamps.
- Payloads include workspace/site/content/locale context, title, keyword/topic fields, recommendation text, approval policy, prerequisites and `planning.source_opportunity_type`.
- Execution pipelines, action runs, audit logs and route identities still resolve through `agentic_marketing_opportunities.id`.

Canonical-linked representation:

- `AgenticOpportunityActionSignatureService` creates a canonical-equivalent signature for a legacy Agentic opportunity, linked canonical `Opportunity`, existing `AgenticMarketingAction` or future canonical action candidate.
- The signature is versioned as `mos-agentic-action:v1`.
- Required signature context is workspace id, objective id, legacy Agentic opportunity id, detector key, Agentic type and action type.
- Optional but included context is linked canonical opportunity id, content id, client site id, source-scoped Phase 3B dedupe key and normalized title/topic.
- Linked legacy/canonical sources representing the same work resolve to the same signature because linked canonical rows resolve back through the legacy Agentic bridge before hashing.
- Missing required context returns blocked reasons and no signature; the service does not infer workspace, objective or legacy source values from unrelated rows.

Duplicate action risks:

- Existing legacy planning already avoids duplicate open actions for the same legacy opportunity/action payload.
- A future canonical planner could still duplicate work if it creates canonical-owned actions while a legacy open action exists for the same linked Agentic row.
- Locale or title-scoped action variants need title/topic context to remain distinct when action type alone is insufficient.
- Multiple linked canonical opportunities for one Agentic row block safe signature inspection.

Proposed canonical-equivalent action signature:

- version
- workspace id
- objective id
- legacy `agentic_marketing_opportunities.id`
- canonical `opportunities.id` when safely linked
- detector key
- Agentic type
- normalized action type
- content id or `none`
- client site id or `none`
- source-scoped Phase 3B dedupe key or `none`
- normalized title/topic/action context

Selection policy recommendation:

- Default current behaviour remains legacy query order.
- Canonical-linked rows may be enriched for display only.
- Action planning continues to create `AgenticMarketingAction` rows against the legacy Agentic opportunity until execution parent migration is ready.
- Future canonical selection may use canonical priority fields only after canonical-equivalent action signatures and execution parent continuity are implemented.
- Canonical opportunity ids may be stored as additive metadata in future action payloads.
- Legacy Agentic opportunity id remains execution identity until Phase 3I or later.

Blocked behaviours:

- Do not change `AgenticMarketingActionPlanner` selection.
- Do not change autonomous workflow selection.
- Do not repoint execution pipelines, action runs, audit logs or approvals.
- Do not update action statuses or payloads.
- Do not create canonical recommended actions by default.

Diagnostics:

- `AgenticOpportunityActionDedupeInspectionService` inspects one legacy Agentic opportunity and reports linked canonical id, objective/workspace/site context, detector/type, open action counts, actions grouped by type, current action dedupe keys, canonical-equivalent signatures, duplicate risks, safe future canonical candidates and blocked reasons.
- `php artisan mos:inspect-agentic-action-dedupe` supports `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=`.
- The command is read-only and reports inspected opportunities, linked canonical count, legacy-only count, open actions, duplicate action risk count, safe canonical-equivalent candidates, blocked count, signature samples and blocked reasons.

Rollback plan:

- Stop invoking `php artisan mos:inspect-agentic-action-dedupe`.
- Remove the Phase 3H diagnostic service/command code if needed.
- No data rollback is required because Phase 3H performs no writes and does not change planner selection, action creation, execution parents or lifecycle state.

## Phase 3I Follow-Up: Execution Parent And Reference Continuity

Phase 3I adds read-only diagnostics for Agentic execution continuity before any guarded planner writer or execution parent migration. It does not repoint execution foreign keys, change pipeline parents, rewrite historical execution records, change action execution behaviour, change approval gates or change autonomous workflow selection.

Execution table/model relationships:

- `AgenticMarketingOpportunity` remains the execution identity.
- `AgenticMarketingAction.opportunity_id`, `AgenticActionRun.opportunity_id`, `AgenticMarketingExecutionPipeline.opportunity_id` and `AgenticMarketingExecutionAsset.opportunity_id` still point at `agentic_marketing_opportunities.id`.
- `AgenticMarketingExecutionApproval`, `AgenticMarketingExecutionFeedback` and `AgenticMarketingExecutionAuditLog` remain pipeline-local through `pipeline_id`; approvals may also reference generated execution assets through `asset_id`.
- `AgenticMarketingAuditLog` may reference the legacy opportunity, action and run ids directly.

Execution route parameters and service assumptions:

- `app.agentic-marketing.opportunities.execution.show` and `app.agentic-marketing.opportunities.execution.prepare` bind `{opportunity}` as `AgenticMarketingOpportunity`.
- Asset approval/rejection and pipeline feedback/retry routes authorize through the pipeline or asset's legacy Agentic opportunity.
- `OpportunityExecutionPipelineService::prepare()` creates a run, pipeline, run item, assets, approvals and rollback snapshot from a legacy `AgenticMarketingOpportunity`.
- `OpportunityExecutionAssetGenerator` resolves `$pipeline->opportunity()` and creates generated brief/draft references with the legacy opportunity id in `client_refs` and `meta`.

Generated asset and rollback references:

- Generated briefs and drafts preserve `opportunity_id` as the legacy Agentic id.
- Execution assets preserve `opportunity_id`, `pipeline_id`, type/status payloads and optional `assetable_type`/`assetable_id`.
- Pipeline `rollback_snapshot.opportunity.id` preserves the legacy Agentic opportunity id and must remain untouched for audit/restore.
- Existing action payloads, action-run snapshots and generated asset payloads are not rewritten in this phase.

Canonical reference requirements:

- A canonical `Opportunity` is usable only when exactly one safe `opportunities.agentic_marketing_opportunity_id` bridge points to the legacy row and workspace context matches.
- Canonical ids may be stored only as additive metadata in future rows until a dedicated parent migration exists.
- Safe additive metadata candidates are future `agentic_marketing_execution_pipelines.input.canonical_opportunity_id`, `agentic_marketing_execution_assets.payload.canonical_opportunity_context`, `agentic_action_runs.input_snapshot.canonical_opportunity_id`, generated brief `client_refs.canonical_opportunity_id` and generated draft `meta.canonical_opportunity_id`.
- Canonical fields may enrich future generated assets only after payload compatibility is proven.

Blocked migration behaviours:

- Canonical-parent-only lookups would miss existing actions, action runs, execution pipelines and assets.
- Pipeline-local approvals, feedback and audit logs cannot be found from canonical opportunity ids without the legacy pipeline chain.
- Duplicate canonical bridges, missing safe bridges, missing execution payload fields and historical rollback references block continuity.
- Lifecycle mapping for `open`, `dismissed` and `completed`, guarded planner migration, canonical action ownership metadata and source-link semantics remain separate future phases.

Diagnostics:

- `AgenticOpportunityExecutionContinuityService` inspects one legacy Agentic opportunity and reports legacy/canonical ids, objective/workspace/site/content context, actions grouped by status, action runs grouped by status, pipelines grouped by status, assets grouped by type/status, approvals grouped by status, feedback/audit counts, generated references, required execution payload fields, canonical field availability, missing fields, legacy-only dependencies, additive metadata candidates, blocked reasons and recommended migration path.
- `php artisan mos:inspect-agentic-execution-continuity` supports `--workspace=`, `--objective=`, `--site=`, `--source-id=`, `--status=`, `--detector=` and `--limit=`.
- The command reports inspected opportunities, linked canonical count, legacy-only count, execution counts, safe additive metadata candidates, blocked count, blocked reasons and route/parent dependency samples.

Future migration options:

- Keep execution legacy-owned and show canonical context only in read-only diagnostics/displays.
- Add canonical ids as metadata only for newly-created pipelines/action runs/assets after compatibility tests pass.
- Design a guarded writer that dual-writes future execution references while retaining legacy route ids.
- Design an explicit parent migration only after route binding, approval gates, rollback snapshots, generated assets, action-run snapshots and lifecycle semantics have a tested rollback plan.

## Phase 3M Agentic Planner Experiment

Phase 3M adds a comparison-only consumer: `mos:compare-agentic-planner-candidates`. It inspects objectives, legacy planner candidate order and canonical-linked Phase 3L-ready rows without changing any app consumer.

Consumer boundary:

- default `AgenticMarketingActionPlanner` output remains legacy-owned;
- canonical experiment candidates are report rows and dry-run DTOs only;
- no routes, execution parents, lifecycle states, historical payloads, run items, audit logs or action statuses are changed;
- no canonical `Opportunity` creates `AgenticMarketingAction` rows or canonical recommended actions.

Future Phase 3N apply work remains blocked until scoped dry-runs show no duplicate risk, no signature mismatch, no continuity blocker and no lifecycle ambiguity.

## Phase 3N Agentic Planner Apply Experiment

Phase 3N adds a single scoped writer command, `mos:apply-agentic-planner-canonical-experiment`, but it does not migrate any default consumer.

Consumer boundary:

- default `AgenticMarketingActionPlanner::planForObjective` remains legacy-owned and unchanged;
- apply requires `--objective=`, `--limit=`, `--apply` and `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT=true`;
- only Phase 3L-ready rows with Phase 3M signature equivalence can be selected;
- duplicate open legacy action risk, Phase 3I continuity blockers and Phase 3J lifecycle ambiguity/conflict block apply;
- the command calls the existing legacy planner for the legacy `AgenticMarketingOpportunity`;
- canonical ids are stored only in `payload.planner_experiment`.

Rollback is flag-off. Existing payload metadata can be ignored by consumers because routes, execution parents, approval gates and lifecycle state still resolve through legacy Agentic opportunity ids.

## Phase 3O Apply Experiment Consumer Audit

Phase 3O adds consumer-facing observability for Phase 3N metadata. `mos:audit-agentic-planner-apply-experiment` verifies that existing consumers can continue to ignore canonical ids because `AgenticMarketingAction.opportunity_id` still resolves to the legacy Agentic opportunity.

`mos:plan-agentic-planner-apply-experiment-rollback` is read-only and reports candidate metadata paths only. There is no default metadata removal writer; consumers should treat rollback as flag-off plus ignoring `payload.planner_experiment`.

## Phase 3P Shadow Diagnostics

Phase 3P keeps all documented `AgenticMarketingOpportunity` consumers legacy-owned. `AgenticMarketingActionPlanner` may compute canonical-linked shadow diagnostics behind `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_SHADOW=false`, but action creation, approval, execution, routes, audit logs and lifecycle reads continue to resolve through legacy Agentic opportunity ids.

Shadow errors are caught as diagnostics and do not block legacy planning.

## Phase 3Q Default-Selection Preview

Phase 3Q keeps all documented consumers legacy-owned. `mos:preview-agentic-planner-default-selection` and the optional default-flow diagnostic evaluate whether canonical-linked default selection might be safe later, but they do not alter `AgenticMarketingActionPlanner::planForObjective` or `planForOpportunity` output.

The preview writes no run results, run items, action payloads, audit logs, lifecycle states, dedupe hashes, canonical recommended actions or execution parents. A Phase 3R experiment remains blocked unless Phase 3Q returns `preview_safe` for a narrow scope.

## Phase 3S Consumer Audit

Phase 3S audits persisted Phase 3R `payload.default_selection_experiment` metadata and verifies consumers are still legacy-safe. Actions must still resolve through `AgenticMarketingAction.opportunity_id`, canonical bridges must still point back to the same legacy Agentic opportunity, and Phase 3Q/3P/3O/3L/3H/3I/3J checks must not regress.

The rollback plan is read-only and metadata-only. It does not delete actions, remove metadata, change routes, update execution pipelines, move run items, sync lifecycle, change statuses or rewrite historical payloads. Any risky Phase 3S row blocks a broader default planner rollout.

## Phase 3T Consumer Boundary

Phase 3T adds a consumer-facing readiness check for explicit multi-objective scopes only. It verifies that default-selection diagnostics remain compatible with legacy consumers before a later Phase 3U is considered.

Consumers still read legacy `AgenticMarketingOpportunity` parents. Phase 3T does not change routes, dispatch extra jobs, create canonical recommended actions or move execution pipelines.
