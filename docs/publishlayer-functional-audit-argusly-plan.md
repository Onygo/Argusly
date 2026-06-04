# PublishLayer functional audit for Argusly

This document uses PublishLayer as a product and architecture reference only. Do not copy PublishLayer code, migrations, views, route names, model names, or public terminology into Argusly. Argusly stays the target architecture and vocabulary.

Core Argusly language:

- Workspace: tenant/account operating context.
- Brand: the monitored company, product, or market entity.
- Intelligence: normalized insight layer.
- Signals: atomic observations that can become recommendations, tasks, reports, or workflows.
- AI Visibility: prompts, citations, answer blocks, share of answer, source presence, and competitor comparisons.
- Agentic Marketing: governed workflows that turn intelligence into marketing actions.

## Current Argusly baseline

Argusly already has a strong foundation:

- Multi-tenant context: `Account`, `Brand`, `Membership`, `BrandMembership`, roles, permissions, modules.
- Admin control center: accounts, brands, users, modules, credits, LLM, integrations, connectors, jobs, logs, pilot signups.
- Intelligence: `IntelligenceSignal`, recommendations, notifications, narratives, graph, entities, mentions, topics, sources, evidence.
- AI Visibility: checks, prompt templates, provider runs, results, citations, snapshots, competitors, schedules.
- Content and marketing: content assets, generated assets, answer blocks, campaigns, calendar, tasks, briefings, newsletters, social posts.
- Agent foundation: `Agent`, `AgentTask`, `AgentRun`, dispatch/planning services.
- Commercial layer: plans, subscriptions, entitlements, credits, credit costs.
- Integration layer: integrations, connector manifests/installations/tokens/logs, outbox and domain events.
- Analytics: analytics sites/events, GA4, Search Console.

Main gap: Argusly has many foundations but several areas are still shallow screens or generic placeholders. The implementation work should harden workflows, governance, observability, and customer-facing screens without importing PublishLayer’s content-publishing vocabulary.

## Functional mapping

| PublishLayer reference area | Argusly equivalent | Already exists | Missing or incomplete | Argusly naming |
|---|---|---|---|---|
| Admin modules | Platform admin/control center | `AdminControlCenterController`, admin nav, modules, credits, LLM, logs | Split large controller into focused admin controllers; add richer health, queues, feature gates, webhook screens | Platform Control Center |
| Platformbeheer | Platform operations | `Module`, subscriptions, developer tools, activity logs | Environment health cards, queue controls, scheduled-task status, tenant diagnostics | Platform Operations |
| Organizations/users | Workspaces/accounts, brands, memberships | `Account`, `Brand`, `Membership`, `User`, permissions | Invites, workspace roles screen polish, impersonation audit, account lifecycle states | Workspace, Brand, Team |
| Sites/content network | Properties, sources, connectors, distribution | `Property`, `Source`, `SourceConnection`, `Connector*`, `PublishingChannel` | Clean site/source registry for monitored domains, source trust, crawling/sync jobs | Source Network, Properties |
| LLM settings | Provider/model/routing/runtime | `LlmProvider`, `LlmModel`, `LlmSetting`, `LlmRequest`, resolver services | Per-workspace/brand overrides, routing rules, audit log, budget guardrails | AI Runtime |
| LLM monitoring | AI request observability | `LlmRequest`, admin LLM request screen | Aggregates, latency/error/cost dashboards, prompt class filters, replay metadata | AI Runtime Monitor |
| Queues/jobs | Job operations | Laravel jobs table, existing jobs, admin `/jobs` | Queue dashboard with pending/failed details, retry/forget, worker heartbeat, scheduled command status | Operations Queue |
| Webhooks | Connector/API event delivery | `OutboxMessage`, `ConnectorLog`, `ConnectorApiController` | Customer webhooks, delivery attempts, signatures, idempotency keys, event catalog | Event Webhooks |
| Facturatie | Plans, entitlements, credits | `Plan`, `Subscription`, `AccountEntitlement`, `Credit*` | Invoices/payment lifecycle if needed, usage exports, plan change audit | Billing, Credits, Entitlements |
| Briefings | Marketing briefings | `Briefing`, briefing routes/views | Research-to-brief pipeline, templates, approval/audit, AI enhancement jobs | Briefings |
| Research flows | Research, topics, mentions, sources | topics, mentions, sources, entities | `ResearchProject` layer tying sources/findings/signals/briefings | Research Workbench |
| Drafts/content generation | Generated assets/content assets | `ContentAsset`, `GeneratedAsset`, generation job/service | Draft versioning, generation runs, comparisons, source lineage | Drafts inside Content Assets |
| Agentic Marketing workflows | Agents, tasks, campaigns | `Agent*`, marketing tasks, campaigns | Objectives, action runs, approvals, governance rules, orchestration history | Agentic Marketing |
| Featureflags | Module/entitlement gating | `Module`, `FeatureGate`, entitlements | Dedicated `FeatureFlag` model for platform and beta gates | Feature Flags |
| Product updates | Public/admin updates | Marketing pages, pilot/contact | `ProductUpdate`, admin CRUD, public changelog, in-app announcements | Product Updates |
| Support/early access | Pilot/contact/admin | pilot signups, contact requests | Support mode/snapshots, lifecycle/status for pilots, admin follow-up history | Pilot Access, Support Mode |

## Global architecture rules

- Keep tenant isolation explicit on every operational model: `account_id` and usually `brand_id`.
- Prefer Argusly modules over PL-style feature names: `core`, `visibility`, `competitive_intelligence`, `content`, `campaigns`, `marketing_os`, `agentic_content`, `agentic_social`, `connectors`.
- Use small controllers by area when adding new work. The existing `AdminControlCenterController` can remain but should not absorb every new admin workflow.
- All external callbacks must be idempotent, signed when possible, rate-limited, and logged.
- All AI calls should create an `LlmRequest` with provider/model, prompt class, tenant context, cost, tokens, duration, status, and trace metadata.
- All long-running work should go through jobs plus domain events/outbox where cross-module side effects are expected.
- New customer screens should fit the current Blade/Tailwind app shell and `config/navigation.php`.

## Phase 1: Foundation and platform operations

Goal: turn the current admin foundation into a reliable platform operations layer.

Tasks:

- Create focused admin controllers:
  - `app/Http/Controllers/Admin/PlatformOverviewController.php`
  - `app/Http/Controllers/Admin/PlatformQueueController.php`
  - `app/Http/Controllers/Admin/PlatformWebhookController.php`
  - `app/Http/Controllers/Admin/PlatformFeatureFlagController.php`
- Keep existing routes compatible in `routes/web.php`; add new route names under `admin.platform.*`.
- Add feature flag foundation:
  - `app/Models/FeatureFlag.php`
  - migration `create_feature_flags_table`
  - columns: `key`, `name`, `description`, `scope`, `enabled`, `rules`, `starts_at`, `ends_at`, `created_by`, `updated_by`
- Add platform diagnostics:
  - `app/Services/PlatformHealthService.php`
  - `app/Services/QueueHealthService.php`
  - optional model `WorkerHeartbeat`
- Add webhook/event catalog foundation:
  - `app/Models/WebhookEndpoint.php`
  - `app/Models/WebhookDelivery.php`
  - migration `create_webhook_endpoints_and_deliveries_table`
  - use Argusly events such as `signal.created`, `visibility.run.completed`, `briefing.approved`, `agent.action.completed`
- Views/components:
  - `resources/views/admin/platform/overview.blade.php`
  - `resources/views/admin/platform/queues.blade.php`
  - `resources/views/admin/platform/webhooks.blade.php`
  - `resources/views/admin/platform/feature-flags.blade.php`
- Permissions:
  - existing `manage_platform` for all platform admin operations.
  - add `manage_feature_flags` only if product/support staff need a narrower role.
- Jobs/queues:
  - `DispatchWebhookDeliveryJob`
  - `RetryWebhookDeliveryJob`
  - `RecordWorkerHeartbeatJob` or console command if queue workers report heartbeat.
- Testcases:
  - `tests/Feature/PlatformOperationsTest.php`
  - `tests/Feature/FeatureFlagManagementTest.php`
  - `tests/Feature/WebhookDeliveryFoundationTest.php`
  - `tests/Feature/QueueOperationsTest.php`

What can be simpler than PL: no separate admin subdomain yet; use the existing `/admin` group and permissions. No PL-style site-token legacy routes.

## Phase 2: Workspace, brands, users, and permissions

Goal: make workspace/brand/team management product-complete and tenant-safe.

Tasks:

- Normalize naming in UI from account to workspace where customer-facing:
  - keep `Account` model/table for now unless a later migration renames it.
  - use labels "Workspace" in views/lang files.
- Add invitation lifecycle:
  - `app/Models/WorkspaceInvite.php`
  - migration `create_workspace_invites_table`
  - controller `app/Http/Controllers/WorkspaceInviteController.php`
  - columns: `account_id`, `brand_id`, `email`, `role_id`, `token_hash`, `status`, `expires_at`, `accepted_at`, `invited_by`
- Harden membership screens:
  - `resources/views/app/settings/team.blade.php`
  - `resources/views/app/settings/brands.blade.php`
  - admin account/brand/user screens.
- Add brand onboarding/wizard:
  - controller `BrandSetupController`
  - views under `resources/views/app/brand-setup/`
  - update `BrandProfile`, `BrandNarrative`, `BrandEntity`, `BrandProduct`, `BrandService`.
- Routes:
  - `GET /settings/team`
  - `POST /settings/team/invites`
  - `POST /settings/team/invites/{invite}/resend`
  - `DELETE /settings/team/invites/{invite}`
  - `GET /invites/{token}`
  - `POST /invites/{token}`
  - `GET /settings/brand-setup`
  - `PATCH /settings/brand-setup`
- Permissions:
  - `manage_users` for invites and membership changes.
  - `manage_account` for workspace settings.
  - `manage_brand` if Argusly needs a narrower brand-edit role; otherwise use `manage_account`.
- Jobs:
  - `SendWorkspaceInviteJob`
  - `ExpireWorkspaceInvitesJob`
- Testcases:
  - `WorkspaceInviteFlowTest`
  - `WorkspaceBrandIsolationTest`
  - `BrandSetupFlowTest`
  - extend `PermissionSystemTest` and `TenantContextTest`.

What must be rebuilt: PL organization/team member logic becomes Argusly workspace/brand membership logic. Do not carry PL approval states unless Argusly needs `active`, `suspended`, `trial`, `pilot`.

## Phase 3: LLM settings, providers, models, costs, and monitoring

Goal: make AI runtime configurable, auditable, and cost-aware.

Tasks:

- Extend current LLM foundation:
  - `LlmProvider`, `LlmModel`, `LlmSetting`, `LlmRequest`
  - add `LlmRoutingRule` or Argusly equivalent `AiRuntimeRule`
  - add `LlmSettingAuditLog`
- Routing scopes:
  - platform default
  - workspace override
  - brand override
  - use-case/prompt-class override, e.g. `visibility_check`, `signal_summary`, `briefing_generate`, `content_generate`, `agent_action`
- Cost and budget integration:
  - connect `CreditCostCatalog` with actual `LlmRequest` token/cost data.
  - add `max_daily_spend_cents`, `max_request_cost_cents`, `fallback_model_id`.
- Controllers:
  - `Admin/AiRuntimeController.php`
  - `Admin/AiRuntimeMonitorController.php`
  - optional customer settings route in `SettingsController` or `AiSettingsController`.
- Views:
  - reuse/extend `resources/views/admin/llm/*`
  - `resources/views/admin/ai-runtime/monitor.blade.php`
  - `resources/views/app/settings/llm.blade.php`
- Routes:
  - `GET /admin/ai-runtime`
  - `PATCH /admin/ai-runtime/settings`
  - `POST /admin/ai-runtime/rules`
  - `GET /admin/ai-runtime/requests`
  - `GET /admin/ai-runtime/requests/{request}`
  - `GET /settings/llm`
  - `PATCH /settings/llm`
- Permissions:
  - platform: `manage_platform`
  - customer: `manage_account`
- Jobs:
  - `AggregateLlmUsageJob`
  - `PruneLlmRequestPayloadsJob`
  - `ReplayFailedAiRequestJob` only if safe and explicit.
- Testcases:
  - extend `LlmProviderFoundationTest`
  - extend `LlmRuntimeInterfaceTest`
  - `AiRuntimeRoutingRuleTest`
  - `LlmRequestMonitoringTest`
  - `CreditUsageAiRuntimeTest`

What can be simpler than PL: use one Argusly `LlmRequest` table as the canonical trace. Add aggregates only after the raw events are reliable.

## Phase 4: Briefings, research, and agentic workflows

Goal: connect research input, briefing creation, AI generation, and governed agent actions.

Tasks:

- Add research workbench:
  - `app/Models/ResearchProject.php`
  - `app/Models/ResearchFinding.php`
  - `app/Models/ResearchRun.php`
  - migration `create_research_workbench_tables`
  - tie findings to `Source`, `EvidenceItem`, `Topic`, `Entity`, `Mention`, `IntelligenceSignal`.
- Upgrade briefings:
  - extend `Briefing` with `research_project_id`, `objective_id`, `template_key`, `status`, `approved_by`, `approved_at`, `ai_summary`, `source_snapshot`.
  - add `BriefingTemplate` only if templates become editable.
- Add agentic governance:
  - `AgentObjective` or reuse `MarketingObjective`
  - `AgentActionRun`
  - `AgentApproval`
  - `AgentWorkflowRule`
  - `AgentWorkflowOverride`
  - migration `create_agentic_marketing_governance_tables`
- Controllers:
  - `ResearchController`
  - `BriefingController` additions
  - `AgenticMarketingController`
  - `AgentApprovalController`
  - `AgentWorkflowController`
- Views:
  - `resources/views/app/research/index.blade.php`
  - `resources/views/app/research/show.blade.php`
  - `resources/views/app/research/create.blade.php`
  - extend `resources/views/app/briefings/*`
  - `resources/views/app/agentic-marketing/index.blade.php`
  - `resources/views/app/agentic-marketing/approvals.blade.php`
  - `resources/views/app/agentic-marketing/workflows.blade.php`
- Routes:
  - `GET /research`
  - `POST /research`
  - `GET /research/{project}`
  - `POST /research/{project}/run`
  - `POST /research/{project}/briefing`
  - `POST /marketing/briefings/{briefing}/generate`
  - `GET /agentic-marketing`
  - `GET /agentic-marketing/approvals`
  - `POST /agentic-marketing/actions/{run}/approve`
  - `POST /agentic-marketing/actions/{run}/reject`
  - `POST /agentic-marketing/workflows/rules`
- Permissions:
  - `view_campaigns`, `manage_campaigns`
  - `view_agents`, `run_agents`
  - add `approve_agent_actions` if approval can be delegated.
- Jobs:
  - `RunResearchProjectJob`
  - `GenerateBriefingFromResearchJob`
  - `EnhanceBriefingJob`
  - `PlanAgenticActionsJob`
  - `ExecuteAgentActionJob`
  - `DetectAgenticMarketingOpportunitiesJob`
- Testcases:
  - `ResearchWorkbenchTest`
  - extend `BriefingFoundationTest`
  - `BriefingResearchConnectionTest`
  - extend `AgentFrameworkTest`
  - `AgenticMarketingGovernanceTest`

What must be different: PL draft/content workspace becomes Argusly Research Workbench plus Briefings plus Agentic Marketing. Briefings should feed intelligence and marketing actions, not only article generation.

## Phase 5: Signals, mentions, sources, and intelligence feed

Goal: make the intelligence feed the operational center of Argusly.

Tasks:

- Extend signal schema if needed:
  - `signal_type`, `source_type`, `severity`, `confidence`, `impact_area`, `expires_at`, `resolved_at`, `assigned_to`, `recommended_action_payload`.
- Strengthen source registry:
  - trust score, authority score, crawl/sync status, source categories, connector mapping.
  - models already exist: `Source`, `SourceConnection`, `SourceSync`, `EvidenceItem`.
- Build signal generation services:
  - `SignalNormalizer`
  - `SignalDeduplicator`
  - `SignalRecommendationMapper`
  - `SourceTrustScoringService`
- Controllers/views:
  - extend `IntelligenceSignalController`
  - extend `MentionController`
  - extend `SourceController`
  - views under `resources/views/app/intelligence`, `mentions`, `sources`.
- Routes:
  - `GET /intelligence`
  - `GET /intelligence/signals`
  - `POST /intelligence/signals/{signal}/assign`
  - `POST /intelligence/signals/{signal}/resolve`
  - `POST /intelligence/signals/{signal}/task`
  - `GET /research/sources`
  - `POST /research/sources/{source}/sync`
- Permissions:
  - `view_dashboard`
  - `manage_account` for source management
  - `view_visibility` for visibility-derived mentions.
- Jobs:
  - `SyncSourceJob`
  - `NormalizeMentionJob`
  - `GenerateSignalFromMentionJob`
  - `ScoreSourceTrustJob`
  - `RefreshIntelligenceFeedJob`
- Testcases:
  - extend `IntelligenceSignalTest`
  - extend `MentionIntelligenceTest`
  - extend `SourceRegistryTest`
  - `SignalLifecycleWorkflowTest`
  - `SourceTrustScoringTest`

What can be simpler than PL: do not create separate opportunity tables for every domain too early. Start with `IntelligenceSignal` plus typed payloads, then promote repeated patterns to dedicated models.

## Phase 6: AI Visibility and competitor tracking

Goal: turn visibility checks into recurring AI Visibility monitoring with competitor context and explainable sources.

Tasks:

- Extend current visibility model set:
  - `VisibilityCheck`
  - `VisibilityPromptTemplate`
  - `VisibilityProviderRun`
  - `VisibilityResult`
  - `VisibilityCitation`
  - `VisibilitySnapshot`
  - `VisibilityAnswerEntity`
  - `VisibilityRunSchedule`
- Add competitor dimensions:
  - extend `Competitor` and `CompetitorSnapshot`
  - add `VisibilityCompetitorMetric` if snapshots become too dense.
- Add answer/source intelligence:
  - link citations to `Source`
  - link answer entities to `Entity`/`BrandEntity`
  - generate `IntelligenceSignal` for ranking drops, new competitor appearances, missing citations, source opportunities.
- Controllers:
  - extend `VisibilityController`
  - extend `CompetitorController`
  - add `AiVisibilityReportController` if report exports need their own workflow.
- Views:
  - extend `resources/views/app/visibility/index.blade.php`
  - extend `resources/views/app/competitors/index.blade.php`
  - `resources/views/app/visibility/competitors.blade.php`
  - `resources/views/app/visibility/sources.blade.php`
- Routes:
  - `GET /visibility`
  - `POST /visibility/prompts`
  - `POST /visibility/prompts/{prompt}/run`
  - `POST /visibility/schedules`
  - `GET /visibility/competitors`
  - `POST /visibility/competitors`
  - `GET /visibility/sources`
- Permissions:
  - `view_visibility`
  - `manage_visibility`
  - `view_competitive_intelligence`
- Jobs:
  - existing `RunVisibilityCheckJob`
  - `ScheduleVisibilityRunsJob`
  - `AggregateVisibilitySnapshotsJob`
  - `GenerateVisibilitySignalsJob`
  - `RefreshCompetitorSnapshotsJob`
- Testcases:
  - extend `VisibilityMonitoringTest`
  - extend `VisibilityRunSchedulerTest`
  - extend `AiVisibilityProviderAdapterTest`
  - extend `CompetitorIntelligenceTest`
  - `VisibilitySignalGenerationTest`

What must be rebuilt: PL LLM tracking becomes Argusly AI Visibility. Use prompts, answers, citations, entities, sources, competitors, and signals as the domain language.

## Phase 7: Reporting, billing, and automation

Goal: make the platform operationally useful for customers and commercially manageable.

Tasks:

- Reporting:
  - extend `Report`, `ReportSection`, `ReportSnapshot`
  - add scheduled reports if needed: `ReportSchedule`
  - include Intelligence, AI Visibility, competitor, content, and credit sections.
- Billing:
  - extend current plans/subscriptions/entitlements/credits.
  - add invoices/payments only when payment flow is in scope:
    - `Invoice`
    - `InvoiceLine`
    - `PaymentAttempt`
    - `BillingEvent`
  - keep plan-feature and credit-cost admin screens.
- Automation:
  - consolidate agent automations, schedules, newsletter sends, visibility runs, source syncs.
  - add `AutomationRule` only if existing `AgentTask`/`VisibilityRunSchedule` are not enough.
- Product updates/support:
  - `ProductUpdate`
  - `InAppAnnouncement`
  - `SupportSnapshot`
  - extend `PilotSignup`/contact request flow.
- Controllers:
  - `ReportController` additions
  - `BillingController`
  - `AutomationController`
  - admin `ProductUpdateController`
  - admin `SupportModeController`
- Views:
  - extend `resources/views/app/reports/*`
  - extend `resources/views/app/settings/modules.blade.php`
  - `resources/views/app/automations/index.blade.php`
  - `resources/views/admin/product-updates/*`
  - `resources/views/admin/support/*`
- Routes:
  - `GET /reporting/reports`
  - `POST /reports`
  - `POST /reports/{report}/snapshot`
  - `GET /settings/billing`
  - `GET /automations`
  - `POST /automations`
  - `GET /admin/product-updates`
  - `POST /admin/product-updates`
  - `GET /admin/support`
- Permissions:
  - `view_dashboard` for reports
  - `manage_billing` for billing
  - `view_agents` and `run_agents` for automation
  - `manage_platform` for product updates/support mode.
- Jobs:
  - `GenerateReportSnapshotJob`
  - `SendScheduledReportJob`
  - `ApplySubscriptionEntitlementsJob`
  - `RecalculateCreditUsageStatsJob`
  - `RunAutomationRuleJob`
  - `PublishProductUpdateNotificationJob`
- Testcases:
  - extend `ExecutiveReportingTest`
  - extend `SubscriptionArchitectureTest`
  - extend `CommercialEntitlementArchitectureTest`
  - extend `CreditUsageTest`
  - `AutomationRuleTest`
  - `ProductUpdatePublishingTest`
  - `SupportModeAuditTest`

What can be simpler than PL: avoid full payment-provider complexity until Argusly actually needs checkout, mandates, refunds, and invoice PDFs. Entitlements and usage visibility matter first.

## Suggested delivery order inside each phase

1. Migration and model foundation with tenant keys and indexes.
2. Policies/permissions and seed updates.
3. Service layer with unit or feature coverage.
4. Controller/routes using existing app/admin shell.
5. Focused Blade screens and navigation entries.
6. Queue jobs and scheduled commands.
7. Tests for isolation, permissions, state transitions, and happy-path UX.

## High-priority naming decisions

- PublishLayer `organization` maps to Argusly `workspace` in UI and `Account` in current code.
- PublishLayer `client site` maps to Argusly `Property` or `Source`, depending on whether it is owned/monitored or just observed.
- PublishLayer `content destination` maps to Argusly `PublishingChannel` or `ConnectorInstallation`.
- PublishLayer `LLM tracking` maps to Argusly `AI Visibility` when it monitors market answers, and `AI Runtime Monitor` when it monitors internal usage.
- PublishLayer `draft` maps to Argusly `GeneratedAsset` or a draft state/version of `ContentAsset`.
- PublishLayer `opportunity` maps first to `IntelligenceSignal` or `Recommendation`; only create a dedicated model when the workflow needs ownership, approval, execution, or history.
- PublishLayer `early access` maps to Argusly `Pilot Access`.

## Immediate next implementation slice

The best first slice is Phase 1 plus the Phase 3 monitoring hardening already started in this codebase:

- Split platform queue/webhook/feature-flag screens out of the large admin controller.
- Add `FeatureFlag`, `WebhookEndpoint`, `WebhookDelivery`.
- Add raw queue and worker health visibility.
- Extend `LlmRequest` monitoring into an AI Runtime Monitor with cost/latency/status filters.
- Add tests around `manage_platform`, webhook idempotency, queue retry controls, and LLM request aggregation.

This creates the operational backbone needed before deeper research, visibility, and agentic workflows become expensive to run.
