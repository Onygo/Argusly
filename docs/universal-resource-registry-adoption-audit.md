# Universal Resource Registry Adoption Audit

Date: 2026-06-30

## Purpose

This audit inventories where Argusly resources should first be registered and consumed by the Universal Resource Registry.

Reference documents:

- `docs/universal-resource-registry.md`
- `docs/universal-action-registry.md`
- `docs/universal-action-registry-adoption-audit.md`
- `docs/universal-interaction-framework.md`

The adoption path is metadata-first. The registry should describe existing model, route, policy, relationship, search, history, notification, and AI explainability metadata. It must not change controllers, routes, policies, models, Blade rendering, forms, authorization, or business behavior.

Batch 1 now registers production metadata providers only. No consuming UI has been changed, and the providers are only composed by `App\Support\Interaction\AppInteractionRegistry` for explicit registry construction and tests.

## Adoption Principles

- Register stable product concepts, not table names or route-shaped identifiers.
- Use `type:id` resource keys for model-backed records, for example `content:123`.
- Reference existing named routes and policies only.
- Reference Action Registry keys only; do not duplicate labels, form behavior, confirmation copy, or execution mode in the Resource Registry.
- Keep relationships descriptive. Do not add relationship loaders, repair logic, syncing, or writes.
- Resolve authorization before resources are exposed to search, command palette, drawers, notifications, or Explainable AI.
- Treat DataTable rows, page headers, detail pages, row actions, and future drawers as consumers of the same resolved resource shape.

## Current Registry Catalog

`App\Support\Interaction\ResourceType::initialTypes()` already defines the initial resource vocabulary and verifies that type mappings point to existing references where available.

| Resource type | Initial label | Model mapping | Primary route | Policy ability |
| --- | --- | --- | --- | --- |
| `content` | Content | `App\Models\Content` | `app.content.show` | `view` |
| `draft` | Draft | `App\Models\Draft` | `app.drafts.show` | `view` |
| `brief` | Brief | `App\Models\Brief` | `app.briefs.show` | `view` |
| `campaign` | Campaign | `App\Models\Campaign` | `app.agentic-marketing.campaign-planner.index` | Deferred |
| `opportunity` | Opportunity | `App\Models\Opportunity` | `app.opportunities.show` | `view` |
| `research_project` | Research project | `App\Models\ResearchProject` | `app.research.show` | `view` |
| `signal_detection` | Signal detection | `App\Models\SignalDetection` | `app.signal-intelligence.detections.show` | `view` |
| `competitor` | Competitor | `App\Models\SiteCompetitor` | `app.sites.competitors.index` | Deferred |
| `llm_tracking_query` | LLM tracking query | `App\Models\LlmTrackingQuery` | `app.sites.llm-tracking.show` | Route/middleware scoped |
| `seo_audit` | SEO audit | `App\Models\SeoAudit` | `app.sites.seo-audits.show` | Route/middleware scoped |
| `site` | Site | `App\Models\ClientSite` | `app.sites.show` | Route/middleware scoped |
| `organization` | Organization | `App\Models\Organization` | `admin.organizations.show` | `view-organization` |
| `workspace` | Workspace | `App\Models\Workspace` | `app.settings` | Route/middleware scoped |
| `user` | User | `App\Models\User` | `admin.users.show` | Admin gates |
| `queue_job` | Queue job | None | `admin.queues.pending.show` | `viewQueues` |
| `failed_job` | Failed job | None | `admin.queues.show` | `viewQueues` |

## Resource Inventory

### Existing Model-Backed Resources

| Resource key pattern | Resource type | Model | Primary UI surfaces | Adoption posture |
| --- | --- | --- | --- | --- |
| `content:{id}` | `content` | `Content` | Content index table, mobile cards, content detail, calendar, content network links, lifecycle dashboard, automation run details. | First batch. Strong route and policy anchors; start with open/detail metadata only. |
| `draft:{id}` | `draft` | `Draft` | Draft index, draft detail, brief workspace draft sections, compare variants, batch results. | First batch. Start with open/detail metadata and relationships to brief/content/site. |
| `brief:{id}` | `brief` | `Brief` | Brief index, content workspace, brief detail/edit, compare setup/show, content batch results. | First batch. Strong policy and route anchors; start with open/detail metadata. |
| `research_project:{id}` | `research_project` | `ResearchProject` | Research index table, research detail, brief linked research context. | First batch. Start with create/open metadata; defer run/start action. |
| `site:{id}` | `site` | `ClientSite` | Sites overview, site setup/detail, LLM tracking, SEO audits, competitors, insights pages. | First batch. Important scope resource for relationships, command palette, search, and drawers. |
| `llm_tracking_query:{id}` | `llm_tracking_query` | `LlmTrackingQuery` | LLM tracking query list, query performance tables, latest answers table, query detail page. | First batch. Start with open/detail metadata; defer run-now and toggles. |
| `seo_audit:{id}` | `seo_audit` | `SeoAudit` | SEO audits list, audit detail, site insights. | First batch. Start with open/detail metadata; defer run audit and AI fix actions. |
| `signal_detection:{id}` | `signal_detection` | `SignalDetection` | Signal intelligence table, detection detail, opportunity candidate links. | First batch. Start with review/open metadata; defer lifecycle mutations. |
| `opportunity:{id}` | `opportunity` | `Opportunity` | Opportunities inbox/detail and MOS/canonical opportunity flows. | Batch 2. Strong route and policy anchor, but current opportunity/action ownership is still converging. |
| `campaign:{id}` | `campaign` | `Campaign` | Admin campaign index/detail and agentic campaign planner surfaces. | Batch 2. Register after route semantics settle between admin campaigns and app planner concepts. |
| `competitor:{id}` | `competitor` | `SiteCompetitor` | Site competitors page, competitor intelligence page, signal/opportunity evidence. | Batch 2. Site-scoped and useful, but detail route is list-scoped today. |
| `organization:{id}` | `organization` | `Organization` | Admin organization detail, admin billing, organization search/support. | Deferred. High-risk admin operations should not be surfaced early. |
| `workspace:{id}` | `workspace` | `Workspace` | App settings, admin organization workspace sections, billing usage, notifications. | Deferred. Useful scope resource but no single app detail route beyond settings. |
| `user:{id}` | `user` | `User` | Admin users table/detail/drawer, organization user sections. | Deferred. Admin lifecycle and drawer actions need tighter metadata first. |
| `queue_job:{queue}:{id}` | `queue_job` | None | Admin queues pending rows/detail. | Safe test fixture only. Operational queue actions are high blast radius. |
| `failed_job:{id}` | `failed_job` | None | Admin failed jobs rows/detail. | Safe test fixture only. Start only with route-backed fixture metadata. |

### Existing Route-Backed Resources Without First-Batch Model Adoption

| Resource candidate | Existing routes | Why not first |
| --- | --- | --- |
| Content series | `app.content.series.index`, `app.content.series.show` | Useful resource identity, but details menu includes duplicate/archive/delete and heavy generation flows. |
| Content automation | `app.content.automations.*` | Good model-backed candidate later, but run/pause/resume/delete actions need state and confirmation metadata. |
| Content batch | `app.content.batches.*` | Batch/item identity is useful for history, but retry/cancel/start actions need queued-state metadata. |
| Programmatic brief blueprint | `app.programmatic-brief-blueprints.show` | Low-risk row link, but outside requested first batch. |
| Programmatic draft request | `app.programmatic-draft-requests.show` | Low-risk row link, but programmatic resource vocabulary should be grouped in a later batch. |
| Programmatic draft review | `app.programmatic-draft-reviews.show` | Same as above. |
| Programmatic publication plan | `app.programmatic-publication-plans.show` | Same as above. |
| Programmatic publication readiness | `app.programmatic-publication-readiness.show` | Same as above. |
| Invoice | `admin.invoices.download`, `admin.invoices.preview` | Download is low risk, but refunds and billing state make the resource better suited to a guarded admin batch. |
| Credit reservation | `admin.credit-reservations.show` | Billing impact and bulk release/capture semantics require selection and confirmation metadata. |
| Early access signup | `admin.early-access.show` | Mostly admin lifecycle actions; defer until disabled reasons are explicit. |
| LLM request | `admin.llm.monitor.show` | Low-risk admin detail link, but not part of app resource adoption batch. |

## DataTable Resource Consumers

The following existing DataTable surfaces should eventually consume resolved resources instead of rebuilding row identity locally.

| Surface | Current resource rows | First resource use |
| --- | --- | --- |
| `resources/views/app/content/index.blade.php` | `Content` canonical rows and language variants. | Resolve `content:{id}` for row open, status, site relationship, preview/search metadata, and future drawer entry. |
| `resources/views/app/drafts/index.blade.php` | `Draft` rows. | Resolve `draft:{id}` for row open and empty-state navigation. |
| `resources/views/app/briefs/index.blade.php` | `Brief` rows. | Resolve `brief:{id}` for row open, site relationship, status, and search tokens. |
| `resources/views/app/research/index.blade.php` | `ResearchProject` rows. | Resolve `research_project:{id}` for row open, status, linked brief/site context, and future run action eligibility. |
| `resources/views/app/sites/llm-tracking/index.blade.php` | `LlmTrackingQuery`, run, aggregate, and answer rows. | Resolve `llm_tracking_query:{id}` for query rows and latest-answer detail links; keep run/aggregate rows deferred. |
| `resources/views/app/sites/seo-audits/index.blade.php` | `SeoAudit` rows. | Resolve `seo_audit:{id}` for audit open, score preview, issue count, and future drawer. |
| `resources/views/app/signal-intelligence/index.blade.php` | `SignalDetection` rows. | Resolve `signal_detection:{id}` for review/open and Explainable AI evidence. |
| `resources/views/app/sites/index.blade.php` | `ClientSite` rows. | Resolve `site:{id}` for setup/open links and scope relationships. |
| `resources/views/admin/queues/index.blade.php` | Pending and failed queue rows. | Safe test fixtures only for `queue_job` and `failed_job` open links. |
| `resources/views/admin/llm/monitor.blade.php` | `LlmRequest` rows. | Later admin support resource if a `llm_request` type is added. |
| `resources/views/admin/invoices/index.blade.php` | `Invoice` rows. | Later admin billing resource if an `invoice` type is added. |
| Programmatic index pages | Programmatic blueprint/request/review/plan/readiness rows. | Later programmatic resource family. |

## Page Header And Detail Page Consumers

| Resource | Existing page/detail usage | Registry metadata to expose first |
| --- | --- | --- |
| `content` | Content detail header, content index page actions, calendar links. | Title, status/readiness, site subtitle, primary URL, search tokens, preview summary, `content-detail` drawer target. |
| `draft` | Draft detail page, brief workspace latest draft links, compare variants. | Title, status, related brief/content, primary URL, preview fields, history timeline key. |
| `brief` | Brief/content workspace header, tabs, edit/enhance/archive/generate sections. | Title, status, site subtitle, related drafts/research project, primary URL, `brief-detail` drawer target. |
| `research_project` | Research index/detail and linked context on brief pages. | Title, status, source/findings counts, related brief/site, primary URL. |
| `site` | Site setup/detail, insights sections, SEO/LLM/competitor pages. | Site name, URL/domain subtitle, workspace relationship, primary URL, scope metadata. |
| `llm_tracking_query` | LLM query detail and answer/run tables. | Query name, status/enabled state, site relationship, latest score preview, primary URL. |
| `seo_audit` | SEO audit detail page. | Audit name/date, score/status, issue counts, site relationship, primary URL, AI input summary. |
| `signal_detection` | Detection detail and opportunity links. | Detection title/type, status, source/site relationships, evidence preview, primary URL. |

## Row Action Resource Consumers

Row actions should use resource identity plus Action Registry keys. The resource registry should only say which action keys are relevant.

| Resource type | Existing row actions | Available Action Registry keys from audit | First adoption |
| --- | --- | --- | --- |
| `content` | Open, translate, restore, delete; variants open/restore/delete. | `app.content.open`, `app.content.create`, `app.content.open-calendar`. | Attach `app.content.open` first. Defer translate/restore/delete and bulk actions. |
| `draft` | Open draft, edit alias, analyze/improve/translate on detail pages. | `app.draft.open`; related existing route `app.drafts.show`. | Attached open metadata first. Defer analyze/improve/translate and governance actions. |
| `brief` | Open brief/workspace, create/generate draft, archive, edit/enhance. | `app.brief.open`; related existing route `app.briefs.show`. | Attached open metadata first. Defer generate/archive/enhance. |
| `research_project` | Open, start research. | `app.research-project.open`, `app.research-project.create`. | Attach open/create first. Defer start. |
| `site` | Open setup/detail, LLM tracking, SEO audits, competitor setup. | `app.site.open`. | Attached site open metadata first. Defer nested insight/setup actions. |
| `llm_tracking_query` | Open query, run now, activate/deactivate, update. | `app.llm-tracking-query.open`. | Attach open first. Defer run-now/toggle/update. |
| `seo_audit` | Open audit, run audit, generate/apply/sync AI fixes. | `app.seo-audit.open`. | Attach open first. Defer run and fix actions. |
| `signal_detection` | Review/open, mark reviewing, dismiss, resolve, promote. | `app.signal-detection.open`, Batch 2 `app.signal-detection.mark-reviewing`, `.dismiss`, `.resolve`. | Attached open metadata first. Defer mutations. |
| `queue_job` | Open pending job, requeue, delete, flush. | `admin.pending-job.open`. | Test fixture only. Defer operational actions. |
| `failed_job` | Open failed job, retry, delete, bulk delete. | `admin.failed-job.open`; documented fixture `admin.queues.failed.bulk-delete`. | Test fixture only. Defer retry/delete. |

## Route Mapping

| Resource type | Index/list route | Primary/detail route | Supporting routes to keep out of first batch |
| --- | --- | --- | --- |
| `content` | `app.content.index` | `app.content.show` | `app.content.store`, `.schedule-bulk`, `.sync-bulk`, `.translate`, `.restore`, `.delete`, `.publish-now`, image/answer-block/improvement/localization routes. |
| `draft` | `app.drafts` redirect to content inbox | `app.drafts.show` | Analyze, improve, translate, approve/request changes, link suggestion, image restore, republish routes. |
| `brief` | `app.briefs` redirect and `app.briefs.create` | `app.briefs.show`, `app.content.workspace.show` | Store/update/enhance/archive/create-draft/generate-draft/compare routes. |
| `research_project` | `app.research.index` | `app.research.show` | `app.research.store`, `.start`, `.findings.select`. |
| `site` | `app.sites.index` | `app.sites.show` | Site setup/store/update/download and nested site workflow routes. |
| `llm_tracking_query` | `app.sites.llm-tracking.index` | `app.sites.llm-tracking.show` | Store/update/toggle/run-now/rescore/starter/query-set/run-details routes. |
| `seo_audit` | `app.sites.seo-audits.index` | `app.sites.seo-audits.show` | Run audit and AI fix generate/apply/edit/sync routes. |
| `signal_detection` | `app.signal-intelligence.index` | `app.signal-intelligence.detections.show` | Run/review/dismiss/resolve/promote routes. |
| `opportunity` | `app.opportunities.index`, `.inbox`, `.decisions` | `app.opportunities.show` | Candidate and execution recommendation routes. |
| `queue_job` | `admin.queues.index` | `admin.queues.pending.show` | Flush, requeue, delete, translation repair routes. |
| `failed_job` | `admin.queues.failed`, `admin.queues.index` | `admin.queues.show` | Retry, retry-all, delete, bulk-delete routes. |

## Policy Mapping

| Resource type | Policy or guard anchor | Notes |
| --- | --- | --- |
| `content` | `ContentPolicy::view`, plus `viewAny`, `update`, `delete`, `restore`, lifecycle abilities. | Use `view` for resource visibility. Other abilities become permission metadata only when corresponding actions are registered. |
| `draft` | `DraftPolicy::view`, plus update/analyze/improve/republish/translate/runAgent. | Use `view` first. |
| `brief` | `BriefPolicy::view`, plus create/update/archive/generateDraft/enhance/createFromResearch/applySuggestion/rejectSuggestion. | Use `view` first. |
| `research_project` | `ResearchProjectPolicy::view`, plus `viewAny`, `create`, `run`. | Use `view` first; defer `run` to heavy action metadata. |
| `signal_detection` | `SignalDetectionPolicy::view`, plus create/update/delete. | Use `view` first; lifecycle actions may need route/controller state in addition to policy. |
| `site` | Existing app middleware, workspace/site scoping, and route model binding. | No explicit `ClientSitePolicy` is registered in the current initial catalog; resource visibility should be resolved through existing scope checks before production indexing. |
| `llm_tracking_query` | Existing app middleware, site scope, route model binding, heavy-action middleware for run-now. | Add explicit policy only if production search/indexing needs resource visibility outside controller-scoped page queries. |
| `seo_audit` | Existing app middleware, site scope, route model binding, heavy-action middleware for run/fix generation. | Same posture as LLM tracking query. |
| `opportunity` | Initial catalog references `view`; verify policy registration before production resource indexing. | Good later candidate after MOS/action ownership settles. |
| `organization` | `OrganizationPolicy` plus admin gates such as `can:admin-area-manage-approvals`, billing/superadmin gates. | Defer production resource exposure because admin account actions are high-risk. |
| `workspace` | `WorkspacePolicy` for specific capabilities; route/middleware scope for settings. | Defer until a stable workspace detail resource exists. |
| `user` | Admin route gates and user lifecycle controller checks. | Defer until admin drawer/action metadata is stable. |
| `queue_job`, `failed_job` | Admin queue gates and `viewQueues` metadata. | Use fixtures only until operational registry adoption is designed. |

## Relationship Candidates

Relationships are descriptive metadata only.

| Source resource | Relationship | Target resource | Metadata source |
| --- | --- | --- | --- |
| `content` | `belongs_to` | `site` | `client_site_id` or equivalent site relation. |
| `content` | `generated_from` | `brief` | Brief/content workspace linkage where present. |
| `content` | `related_to` | `draft` | Draft/content relation and latest draft links. |
| `content` | `belongs_to` | `workspace` | Via site/workspace relation. |
| `draft` | `belongs_to` | `brief` | Draft brief relation where present. |
| `draft` | `generated_from` | `content` | Draft content relation. |
| `brief` | `belongs_to` | `site` | Brief client site relation. |
| `brief` | `contains` | `draft` | Brief workspace drafts list. |
| `brief` | `related_to` | `research_project` | Research-linked brief context. |
| `research_project` | `scoped_to` | `site` or `workspace` | Research linked context. |
| `llm_tracking_query` | `belongs_to` | `site` | Route and query site scope. |
| `seo_audit` | `belongs_to` | `site` | Route and audit site scope. |
| `signal_detection` | `scoped_to` | `site` or `workspace` | Detection tenancy and source metadata. |
| `signal_detection` | `related_to` | `opportunity` | Candidate/promoted opportunity links. |
| `site` | `belongs_to` | `workspace` | Site workspace relation. |
| `workspace` | `belongs_to` | `organization` | Workspace organization relation. |
| `queue_job` | `scoped_to` | `workspace` or `organization` | Only when payload metadata is already safely redacted and authorized. |
| `failed_job` | `scoped_to` | `workspace` or `organization` | Same as queue jobs; do not parse sensitive payloads in the registry. |

## Future Drawer Candidates

| Resource | Drawer target candidate | First drawer use |
| --- | --- | --- |
| `content` | `content-detail` | Inspect title, status, site, readiness, language variants, and available visible actions. |
| `draft` | `draft-detail` | Inspect status, brief/content linkage, quality metrics, and visible draft actions. |
| `brief` | `brief-detail` | Inspect outline, keyword, site, linked research, and draft generation state. |
| `research_project` | `research-project-detail` | Inspect sources, findings counts, linked brief/site, and run state. |
| `site` | `site-detail` | Inspect setup status, integrations, related LLM queries, SEO audits, and competitors. |
| `llm_tracking_query` | `llm-query-detail` | Inspect latest score, answer runs, provider/model metadata, and site relation. |
| `seo_audit` | `seo-audit-detail` | Inspect score, issue counts, AI fix suggestions, and site relation. |
| `signal_detection` | `signal-detection-detail` | Inspect evidence, status, source, relationships, and visible lifecycle actions. |

Drawers should be consumed by the Application Shell later. This audit does not require rendering, state management, or JavaScript drawer changes.

## Future Command Palette And Global Search Candidates

First indexing should prioritize resources users naturally search for or jump to:

| Priority | Resource | Command palette use | Global search use |
| --- | --- | --- | --- |
| 1 | `content` | Open current/recent content, create content, open calendar. | Title, topic, site, status, language, publication state. |
| 1 | `brief` | Open brief/workspace, create brief. | Title, keyword, site, source, status. |
| 1 | `draft` | Open draft. | Title, brief title, site, status, quality/readiness tokens. |
| 1 | `site` | Open site setup/detail and site-scoped insight pages. | Site name, domain, workspace. |
| 2 | `research_project` | Open or create research project. | Project title, linked topic/site, status, source count. |
| 2 | `llm_tracking_query` | Open query. | Query name, site, provider/context tokens, latest score. |
| 2 | `seo_audit` | Open audit. | Site, audit date, score, issue type tokens. |
| 2 | `signal_detection` | Open/review detection. | Detection title/type, source, entity, site/workspace, status. |
| 3 | `opportunity` | Open opportunity. | Topic, category, status, source signals. |
| Deferred | Admin resources | Support/admin command palette only. | Admin search should remain separately gated. |

Global search must never expose policy-denied resources or hidden relationship titles. Destructive actions should not be indexed as search results.

## Future Explainable AI Candidates

Explainable AI should consume only visible authorized resources and visible authorized actions.

| Resource | Explainability metadata candidates |
| --- | --- |
| `content` | Brief inputs, draft lineage, performance/readiness signals, site context, publication state, content network relationships. |
| `draft` | Brief source, generation inputs, quality metrics, humanization/intelligence outputs, linked content. |
| `brief` | Research inputs, keyword/audience/angle fields, linked drafts, suggestions, source type. |
| `research_project` | Sources, findings, selected findings, linked brief/content creation outcome. |
| `site` | Setup state, integrations, domain, related audits, LLM tracking, competitors. |
| `llm_tracking_query` | Latest answers, provider/model runs, visibility score, citations, competitors, trend. |
| `seo_audit` | Crawl/audit inputs, score, issues, fix suggestions, severity. |
| `signal_detection` | Source event, evidence, confidence, linked signals, promoted opportunity. |
| `opportunity` | Promoted signal lineage, recommended actions, canonical/agentic ownership state. |

Explainable AI should not infer denied relationship names, read hidden action metadata, parse queue payloads, or summarize admin billing/user state before explicit authorization metadata exists.

## First Implementation Batch

Batch 1 should register low-risk metadata for test fixtures or the first production registration pass, but should not change Blade, controllers, routes, policies, models, or business logic.

| Resource type | Key pattern | Model | Primary route | Policy posture | Available action keys | First consumers |
| --- | --- | --- | --- | --- | --- | --- |
| `content` | `content:{id}` | `Content` | `app.content.show` | `ContentPolicy::view` | `app.content.open` | Content DataTable row open, detail header, future drawer/search. |
| `draft` | `draft:{id}` | `Draft` | `app.drafts.show` | `DraftPolicy::view` | `app.draft.open` | Draft index/detail, brief workspace draft links. |
| `brief` | `brief:{id}` | `Brief` | `app.briefs.show` | `BriefPolicy::view` | `app.brief.open` | Brief index, content workspace header, future drawer/search. |
| `research_project` | `research_project:{id}` | `ResearchProject` | `app.research.show` | `ResearchProjectPolicy::view` | `app.research-project.open`, `app.research-project.create` | Research DataTable and linked brief context. |
| `site` | `site:{id}` | `ClientSite` | `app.sites.show` | Existing site/workspace scope | `app.site.open` | Scope resource for all site-scoped tables, search, drawers. |
| `llm_tracking_query` | `llm_tracking_query:{id}` | `LlmTrackingQuery` | `app.sites.llm-tracking.show` | Existing site scope | `app.llm-tracking-query.open` | LLM tracking query rows and future search. |
| `seo_audit` | `seo_audit:{id}` | `SeoAudit` | `app.sites.seo-audits.show` | Existing site scope | `app.seo-audit.open` | SEO audit rows and future drawer/explainability. |
| `signal_detection` | `signal_detection:{id}` | `SignalDetection` | `app.signal-intelligence.detections.show` | `SignalDetectionPolicy::view` | `app.signal-detection.open` | Signal intelligence rows and Explainable AI evidence. |

Batch 1 implementation:

- Provider classes added:
  - `App\Support\Interaction\Providers\AppContentInteractionProvider`
  - `App\Support\Interaction\Providers\AppResearchInteractionProvider`
  - `App\Support\Interaction\Providers\AppSiteInteractionProvider`
  - `App\Support\Interaction\Providers\AppSignalInteractionProvider`
- Composition helper added: `App\Support\Interaction\AppInteractionRegistry`.
- Actions are route-backed GET metadata only.
- Resources may reference Action Registry keys, but the Action Registry remains the source of route/execution metadata.

Batch 1 acceptance criteria:

- Resource types already present in `ResourceType::initialTypes()` continue to map to existing model and route references.
- Provider resources can be resolved by key, produce primary URLs, include available action keys, and hide policy-denied resources.
- Provider resources can reference existing Action Registry keys only when those keys are registered by the same provider batch.
- Production pages remain visually and behaviorally unchanged.
- No controller, route, policy, model, or business service changes are required.

## Batch 2 Candidates

| Resource | Why Batch 2 |
| --- | --- |
| `opportunity` | Important for MOS, recommended actions, and Explainable AI, but current canonical/agentic ownership requires careful relationship metadata. |
| `campaign` | Useful for admin campaign detail and agentic campaign planner, but route meaning spans admin and app concepts. |
| `competitor` | Site-scoped and useful for LLM/SEO/signal explanations, but detail routing is list-oriented today. |
| Content series | Useful content family resource, but action menu mutations and generation flows need confirmation/heavy metadata. |
| Content automation | Useful workflow resource, but run/pause/resume/delete states need action disabled reasons. |
| Programmatic resource family | Mostly low-risk table detail links, best adopted together with a programmatic type vocabulary. |
| Admin LLM request | Useful admin support detail resource, but outside first app resource adoption. |

## Deferred Resources With Reasons

| Deferred resource | Reason |
| --- | --- |
| Answer blocks, image versions, render artifacts, content revisions, content versions | Fine-grained content assets should remain detail-page local until they have stable detail routes, policies, and drawer/search consumers. |
| LLM tracking runs, aggregates, latest answer rows | Analytics/run rows are valuable evidence but not primary resource destinations yet. Use them as preview/AI metadata under `llm_tracking_query` first. |
| SEO audit pages, issues, fix suggestions | Keep under `seo_audit` preview/AI metadata until route, policy, and action eligibility are explicit. |
| Signal entities, events, mentions, feed items, scores, processing runs | Low-level signal intelligence objects need clearer user-facing ownership and detail destinations. |
| Queue translation rows and failed translation jobs | Operational admin queue internals are high-risk and may expose payload details. |
| Invoice, billing ledger, credit wallets, credit reservations, payment intents, subscriptions | Financial/admin resources require stronger confirmation, idempotency, provider-state, and policy metadata. |
| Organization, workspace, user | Important global/admin resources, but early exposure could leak support/admin state. Defer until admin search and command palette gates are explicit. |
| Social posts, social variants, publications, accounts | Externally visible publishing workflows need preview, confirmation, account eligibility, and external-risk metadata. |
| Agentic marketing actions, objectives, runs, approvals, pipelines | Workflow state, autonomous execution, retry semantics, and external effects need richer action/resource explainability first. |
| Content lifecycle tasks and JS-driven lifecycle actions | Current page owns local JavaScript behavior and task-specific forms. |
| Network linking permissions | Feature-gated permission eligibility should be explicit before registry adoption. |
| Onboarding scan/wizard state and temporary source-generation state | Temporary workflow state is not a stable resource destination. |
| Marketing/public CMS models | Public marketing resources are outside authenticated app/admin interaction adoption. |

## Test Plan

Current resource registry tests should remain the first safety net:

- `php artisan test tests/Feature/UI/ResourceRegistryFrameworkTest.php`
- `php artisan test tests/Feature/UI/InteractionProviderAdoptionTest.php`
- `php artisan test tests/Unit/Architecture/UniversalResourceRegistryArchitectureTest.php`

Add future adoption tests beside the first production consumer. They should assert:

- The resource type exists in `ResourceType::initialTypes()`.
- The resource model class and primary route exist.
- Resource keys use the `type:id` convention.
- Resolved resources include `key`, `type`, `id`, `primary_route`, `primary_url`, and `available_actions`.
- Policy-denied resources are not returned by default.
- Relationship metadata is descriptive and does not load or mutate records.
- Resource `available_actions` reference Action Registry keys that exist in the test registry.
- DataTable or page consumers preserve resolved resource identity instead of re-inferring the resource type locally.
- Search, command palette, drawer, and Explainable AI tests use only visible authorized resources.

Verification for this documentation-only audit:

- `php artisan view:cache`
- `php artisan test tests/Feature/UI/ResourceRegistryFrameworkTest.php`
- `php artisan test tests/Unit/Architecture/UniversalResourceRegistryArchitectureTest.php`
- `npm run build`
- `git diff --check`
