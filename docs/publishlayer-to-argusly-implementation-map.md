# Argusly Implementation Map

## Doel en uitgangspunten

Deze audit beschrijft hoe volwassen engines, services, jobs, workflows en patronen kunnen worden vertaald naar Argusly-modules. De scope is bewust geen platformkopie: Argusly blijft georganiseerd rond workspace, brand, visibility, intelligence, content, agents, distribution, connectors, runtime, credits en platform operations.

Regels voor implementatie:

- Gebruik bestaande Argusly-modellen, module-gates, policies, tenant-scopes en routeconventies als basis.
- Breek geen bestaande routes, migrations, seeders, tests of UI-oppervlakken.
- Voeg nieuwe tabellen alleen toe wanneer bestaande tabellen geen duidelijke ownership of lifecycle hebben.
- Houd account- en brand-isolatie expliciet in migrations, models, policies, services en tests.
- Laat LLM-kosten, featuretoegang en operationele monitoring via bestaande credit-, entitlement-, feature-flag- en admin-fundamenten lopen.

## Huidige Argusly-inventaris

| Onderdeel | Status | Bestaande Argusly onderdelen |
| --- | --- | --- |
| Account | Bestaat | `Account`, `Membership`, `Subscription`, `AccountEntitlement`, `CreditBalance`, `CreditTransaction`, settings routes en admin accountbeheer |
| Brand | Bestaat | `Brand`, `BrandMembership`, `BrandProfile`, `BrandProduct`, `BrandService`, `BrandNarrative`, brand settings en knowledge center |
| Competitor | Bestaat | `Competitor`, `CompetitorSnapshot`, `CompetitorService`, `CompetitorController`, `CompetitorPolicy`, competitor intelligence tests |
| VisibilityCheck | Bestaat | `VisibilityCheck`, `RunVisibilityCheckJob`, `VisibilityMonitoringService`, `VisibilityController`, `VisibilityCheckPolicy` |
| VisibilityResult | Bestaat | `VisibilityResult`, `VisibilitySnapshot`, evidence/domain-event hooks en language/market retrofit |
| Citation | Bestaat als visibility citation | `VisibilityCitation`, `VisibilityProviderRun`, citations view en provider-run services |
| PromptTemplate | Bestaat als visibility prompt | `VisibilityPromptTemplate`, prompt routes onder `/visibility/prompts`, schedule support |
| ContentAsset | Bestaat | `ContentAsset`, `ContentAssetService`, `ContentAssetController`, content CRUD, audit/generate/publish actions |
| GeneratedAsset | Bestaat | `GeneratedAsset`, `GenerateContentAssetJob`, generated draft apply routes, generation logging |
| Briefing | Bestaat | `Briefing`, `BriefingService`, `BriefingController`, approval routes en briefing tests |
| Recommendation | Bestaat | `Recommendation`, `RecommendationService`, `RecommendationEngineService`, executable actions, recommendation routes |
| Signal | Bestaat als intelligence signal | `IntelligenceSignal`, `SignalAlert`, `IntelligenceSignalService`, signal producers, domain-event projectors |
| Campaign | Bestaat | `Campaign`, `CampaignStage`, `CampaignItem`, `CampaignService`, `CampaignBoardService`, campaign board routes |
| SocialPost | Bestaat | `SocialPost`, `SocialPostVariant`, `SocialMediaAsset`, social publishing en repurposing services/routes |
| PublishingAction | Bestaat | `PublishingAction`, `PublishingChannel`, `PublishingService`, `PublishContentAssetJob`, connector-backed channels |
| LlmProvider | Bestaat | `LlmProvider`, provider registry, admin provider settings, provider seeders |
| LlmModel | Bestaat | `LlmModel`, model registry, admin model settings, capability metadata |
| LlmRequest | Bestaat | `LlmRequest`, `LlmRequestTracker`, runtime guard/router, admin runtime monitor |
| CreditService | Bestaat | `CreditService`, `CreditCostResolver`, `CreditCostCatalog`, overrides, usage stats, admin credit screens |
| FeatureFlag | Bestaat | `FeatureFlag`, `FeatureGate`, platform feature flag admin routes |

## Modulekaart

## 1. AI Visibility

### Bestaande Argusly onderdelen

- Modellen: `VisibilityCheck`, `VisibilityResult`, `VisibilitySnapshot`, `VisibilityPromptTemplate`, `VisibilityProviderRun`, `VisibilityCitation`, `VisibilityAnswerEntity`, `Mention`, `SearchConsoleSite`, `SearchConsoleQuerySnapshot`.
- Services: `VisibilityMonitoringService`, `Visibility\ProviderRegistry`, `Visibility\ProviderRunService`, `Visibility\RunScheduleService`, `Visibility\AiVisibilityDashboardService`, `SearchConsolePerformanceService`, `MentionIntelligenceService`.
- Jobs: `RunVisibilityCheckJob`, `SyncSearchConsoleSiteJob`.
- Routes/UI: `/visibility`, `/visibility/search`, `/visibility/citations`, `/visibility/prompts`, prompt create/update/archive/duplicate/run.
- Tests: `VisibilityMonitoringTest`, `VisibilityRunSchedulerTest`, `AiVisibilityProviderAdapterTest`, `SearchConsoleFoundationTest`, `MentionIntelligenceTest`, `EvidenceLayerTest`.

### Ontbrekende onderdelen

- Gestandaardiseerde provider-evaluatie per prompt, inclusief run comparison, variance en confidence.
- Cross-provider answer analysis die visibility results, citations, entities en recommendations samenbrengt.
- Prompt versioning/evaluation history buiten de huidige template-status en run metadata.
- Meer expliciete citation source quality lifecycle, bijvoorbeeld first seen, last seen, decay en ownership.

### Voorgestelde nieuwe tabellen

- `visibility_prompt_versions`: immutable prompttekst, variables, language, market, persona en created_by per templateversie.
- `visibility_evaluations`: genormaliseerde scoring per provider run, met dimensions zoals mention quality, position, sentiment, citation strength en confidence.
- `visibility_citation_sources`: bronprofiel per domein/url met quality score, source type, first_seen_at, last_seen_at en crawl metadata.

### Voorgestelde services

- `VisibilityEvaluationService`: berekent provider-onafhankelijke visibility scores.
- `VisibilityPromptVersionService`: maakt immutable versies en koppelt runs aan de juiste promptversie.
- `CitationSourceQualityService`: onderhoudt bronkwaliteit en signaleert wijzigingen.

### Voorgestelde jobs

- `EvaluateVisibilityProviderRunJob`: verwerkt run-output naar evaluations, citations, answer entities en signals.
- `RefreshCitationSourceQualityJob`: actualiseert bronkwaliteit periodiek.

### Voorgestelde UI routes

- `app.visibility.runs.show`: detailpagina voor provider-run, answer, citations, entities en scores.
- `app.visibility.prompts.versions`: promptversies en performance per versie.
- `app.visibility.sources`: citation source library.

### Risico's

- Dubbele scoring naast bestaande `visibility_results.score`.
- Provider-output kan onvergelijkbaar zijn zonder strikte normalisatie.
- Promptversies kunnen storage en UI-complexiteit vergroten.

### Teststrategie

- Featuretests voor promptversie-isolatie, provider-run tenant-isolatie en evaluation creation.
- Unit tests voor scoring normalisatie met fake providers.
- Regressietests op bestaande visibility routes en `RunVisibilityCheckJob`.

## 2. Opportunity Discovery

### Bestaande Argusly onderdelen

- Modellen: `Recommendation`, `IntelligenceSignal`, `PerformanceInsight`, `Topic`, `TopicCluster`, `CompetitorSnapshot`, `NarrativeGap`, `GraphNode`, `GraphEdge`.
- Services: `OpportunityDiscoveryService`, `Graph\GraphOpportunityService`, `RecommendationEngineService`, `PerformanceInsightService`, `TopicIntelligenceService`, `NarrativeIntelligenceService`, `CompetitorService`.
- Jobs: indirect via source sync, graph projection, visibility checks en content audits.
- Routes/UI: `/intelligence/opportunities`, `/intelligence/recommendations`, `/intelligence/graph`, topic and competitor screens.
- Tests: `OpportunityDiscoveryTest`, `RecommendationEngineTest`, `MarketingOsRecommendationConnectionTest`, `KnowledgeGraphProjectionLayerTest`, `TopicIntelligenceTest`.

### Ontbrekende onderdelen

- Persistente opportunity entities met lifecycle, score history, source evidence en recommended next action.
- Deduplicatie tussen signals, recommendations en opportunities.
- Opportunity pipeline per brand/team met ownership, status, SLA en projected impact.

### Voorgestelde nieuwe tabellen

- `opportunities`: account_id, brand_id, type, title, status, priority, impact_score, confidence_score, source_type, source_id, owner_id, due_at.
- `opportunity_evidence`: opportunity_id, evidence subject, summary, weight, metadata.
- `opportunity_score_snapshots`: historical score, drivers en captured_at.

### Voorgestelde services

- `OpportunityScoringService`: combineert visibility, search performance, content lifecycle, competitors en graph gaps.
- `OpportunityDeduplicationService`: voorkomt dubbele opportunities uit dezelfde signalen.
- `OpportunityWorkflowService`: koppelt opportunities aan recommendations, marketing tasks, briefings of campaigns.

### Voorgestelde jobs

- `DiscoverBrandOpportunitiesJob`: periodieke discovery per brand.
- `RefreshOpportunityScoresJob`: score drift en status updates.

### Voorgestelde UI routes

- `app.intelligence.opportunities.show`: opportunity detail met evidence, impact en action plan.
- `app.intelligence.opportunities.accept`: maakt recommendation, task, briefing of campaign-item.
- `app.intelligence.opportunities.dismiss`: met reason en feedback voor scoring.

### Risico's

- Overlap met `Recommendation` en `IntelligenceSignal` kan gebruikers verwarren.
- Te veel automatisch gegenereerde opportunities kan noise veroorzaken.
- Scorelogica moet uitlegbaar blijven.

### Teststrategie

- Featuretests voor opportunity lifecycle en action creation.
- Unit tests voor deduplicatie en scoring.
- Contracttests dat opportunities nooit buiten account/brand evidence ophalen.

## 3. Content Operations

### Bestaande Argusly onderdelen

- Modellen: `ContentAsset`, `GeneratedAsset`, `ContentAudit`, `ContentLifecycleScore`, `ContentTranslation`, `AnswerBlock`, `Briefing`, `Approval`, `MarketingTask`.
- Services: `ContentAssetService`, `ContentGenerationService`, `ContentFirstDraftService`, `ContentOperationsService`, `ContentAuditService`, `ContentLifecycleService`, `ContentTranslationService`, `BriefingService`, `ApprovalService`.
- Jobs: `GenerateContentAssetJob`, `RunContentAuditJob`, `CalculateContentLifecycleScoreJob`.
- Routes/UI: `/content`, `/content/operations`, content generate/draft/apply/distribution-bundle/audit/lifecycle/translation routes, answer-block screens.
- Tests: `ContentAssetTest`, `ContentGenerationTest`, `ContentOperationsWorkflowTest`, `ContentAuditTest`, `ContentLifecycleTest`, `ContentEngineLanguageRetrofitTest`, `ApprovalWorkflowTest`.

### Ontbrekende onderdelen

- End-to-end editorial workflow stages beyond asset status: intake, briefing, draft, review, approval, publish, refresh.
- Generation run lineage across briefing, generated asset, content asset, social post and distribution bundle.
- Reusable content operation playbooks for common workflows.

### Voorgestelde nieuwe tabellen

- `content_workflows`: workflow definition per account/brand with module, status, default stages.
- `content_workflow_runs`: subject_type/id, current_stage, owner, due_at, started_at, completed_at.
- `content_workflow_events`: immutable event log for stage changes, approvals, generated drafts and publishing transitions.
- `generation_runs`: shared lineage table for LLM generation across content, briefings, social and agents.

### Voorgestelde services

- `ContentWorkflowService`: stage transitions and ownership.
- `GenerationLineageService`: links LLM requests to generated assets and downstream assets.
- `ContentPlaybookService`: creates repeatable content operation plans from opportunities or briefings.

### Voorgestelde jobs

- `AdvanceContentWorkflowJob`: handles automated stage transitions and overdue checks.
- `GenerateContentPlaybookAssetsJob`: creates draft bundles from approved playbooks.

### Voorgestelde UI routes

- `app.content.workflows`: operational board by stage and owner.
- `app.content.workflows.show`: workflow run detail.
- `app.content.generated-assets`: generated asset library with lineage filters.

### Risico's

- Workflow tables can duplicate existing `status` columns if boundaries are unclear.
- Approval logic must remain compatible with current `Approval` model.
- Generation lineage must avoid storing sensitive prompts in full when policy requires hashing.

### Teststrategie

- Featuretests for workflow transitions, approval gates and content status sync.
- Unit tests for playbook creation and lineage relationships.
- Regression tests for existing content create/edit/generate/publish routes.

## 4. Agentic Marketing

### Bestaande Argusly onderdelen

- Modellen: `Agent`, `AgentRun`, `AgentTask`, `MarketingWorkspace`, `MarketingObjective`, `MarketingTask`, `Campaign`, `CampaignItem`, `Briefing`, `Recommendation`.
- Services: `AgentManager`, `AgentRunner`, `AgentTaskDispatcher`, `AgentTaskPlannerService`, `AgenticMarketingWorkflowService`, `MarketingOsService`, `MarketingTaskService`, `CampaignService`.
- Jobs: agent execution appears service-driven; existing queue patterns are available.
- Routes/UI: `/agents`, `/agents/tasks`, `/agents/runs`, recommendation/briefing planning and task approval/queue/run routes.
- Tests: `AgentFrameworkTest`, `MarketingOsFoundationTest`, `MarketingTaskSystemTest`, `CampaignPlanningBoardTest`.

### Ontbrekende onderdelen

- Agent run steps with tool calls, approvals, checkpoints and resumability.
- Campaign-aware automation recipes that create tasks, draft assets and distribution plans.
- Guardrails per module, budget and permission context before tasks are queued.

### Voorgestelde nieuwe tabellen

- `agent_run_steps`: run_id, task_id, type, status, input_summary, output_summary, tool_name, started_at, completed_at, metadata.
- `agent_approvals`: task/run/step subject, requested_by, approved_by, status, policy_snapshot.
- `agent_playbooks`: reusable account/brand recipes for campaign planning, content refresh, social repurposing and reporting.

### Voorgestelde services

- `AgentRunStepService`: step persistence and resumability.
- `AgentGuardrailService`: permission, feature, module, credit and LLM runtime validation.
- `AgentPlaybookService`: converts recommendations, opportunities and briefings into tasks.

### Voorgestelde jobs

- `RunAgentTaskJob`: queue-safe task execution.
- `ResumeAgentRunJob`: resumes interrupted runs.
- `EvaluateAgentApprovalJob`: escalates or expires pending approvals.

### Voorgestelde UI routes

- `app.agents.runs.show`: step timeline, cost, approvals and outputs.
- `app.agents.playbooks`: playbook library.
- `app.agents.tasks.approve`: explicit approval action with policy snapshot.

### Risico's

- Agent automation can bypass existing permissions if context is not captured.
- Long-running runs need idempotency and retry safety.
- Tool outputs may leak cross-tenant data without strict subject scoping.

### Teststrategie

- Featuretests for task queue, approval gates and run detail.
- Unit tests for guardrail decisions.
- Queue tests for idempotent retries and failed/resumed runs.

## 5. Distribution

### Bestaande Argusly onderdelen

- Modellen: `PublishingAction`, `PublishingChannel`, `SocialPost`, `SocialPostVariant`, `SocialProfile`, `Newsletter`, `NewsletterSend`, `EmailProvider`, `ContentTranslation`.
- Services: `PublishingService`, `SocialPublishing\SocialPublishingService`, `SocialRepurposing\SocialRepurposingService`, `NewsletterSendingService`, `EmailProviderManager`.
- Jobs: `PublishContentAssetJob`, `PublishSocialPostJob`, `SendNewsletterJob`, `DispatchWebhookDeliveryJob`, `RetryWebhookDeliveryJob`.
- Routes/UI: `/content/distribution`, publish website, schedule social, audit/translate/reviewed distribution actions, social post routes, newsletter routes.
- Tests: `DistributionHubTest`, `PublishingActionTest`, `SocialPublishingFoundationTest`, `SocialPublishingIntelligenceTest`, `LinkedInPublishingTest`, `NewsletterSendingFoundationTest`.

### Ontbrekende onderdelen

- Unified distribution plan for one content asset across website, social, newsletter and connector channels.
- Publishing retries and channel-specific failure playbooks visible to users.
- Distribution calendar normalization across content, campaigns, social and newsletter sends.

### Voorgestelde nieuwe tabellen

- `distribution_plans`: subject content/campaign/briefing, status, owner, target_window, metadata.
- `distribution_plan_items`: plan_id, channel_type, channel_id, subject_type/id, scheduled_at, status, publishing_action_id.
- `publishing_attempts`: publishing_action_id, attempt, request/response summary, status, error_code, started_at, completed_at.

### Voorgestelde services

- `DistributionPlanService`: builds and maintains plans.
- `PublishingRetryPolicyService`: maps errors to retry, manual action or channel disconnect.
- `DistributionCalendarService`: normalized read model for planned and published actions.

### Voorgestelde jobs

- `ExecuteDistributionPlanItemJob`: queues a single plan item.
- `RetryPublishingAttemptJob`: retry based on policy.

### Voorgestelde UI routes

- `app.distribution.plans.show`: plan with channel item statuses.
- `app.distribution.calendar`: normalized distribution calendar.
- `app.distribution.actions.retry`: retry failed publishing action.

### Risico's

- Distribution plans can overlap with `PublishingAction` unless `PublishingAction` remains the execution record.
- External channel APIs require robust status reconciliation.
- Retrying publish/update actions needs idempotency keys.

### Teststrategie

- Featuretests for plan creation, scheduling and publishing action creation.
- Unit tests for retry policy decisions.
- Connector/social/newsletter integration tests with fake providers.

## 6. Connectors

### Bestaande Argusly onderdelen

- Modellen: `ConnectorManifest`, `ConnectorVersion`, `ConnectorCapability`, `ConnectorInstallation`, `ConnectorToken`, `ConnectorLog`, `Integration`, `IntegrationConnection`, `IntegrationPermission`, `Source`, `SourceConnection`, `SourceSync`.
- Services: `SourceRegistryService`, `SourceHealthService`, `WebhookDeliveryService`, integration managers and provider registry.
- Middleware/API: `AuthenticateConnector`, `/api/v1/connector/*`, pending content and publish callback endpoints.
- Routes/UI: settings connectors, admin connectors, connector token management, source registry screens.
- Tests: `ConnectorProtocolTest`, `ConnectorApiV1Test`, `ConnectorPublishingQueueTest`, `IntegrationArchitectureTest`, `IntegrationRuntimeTest`, `SourceRegistryTest`.

### Ontbrekende onderdelen

- Connector health score and capability drift detection.
- Version rollout lifecycle for connector installations.
- Structured inbound event normalization from connectors into domain events/signals.

### Voorgestelde nieuwe tabellen

- `connector_health_checks`: installation_id, status, latency_ms, capability_hash, checked_at, error_summary.
- `connector_events`: installation_id, event_type, external_id, payload_hash, status, processed_at.
- `connector_rollouts`: connector_version_id, account/brand scope, status, started_at, completed_at.

### Voorgestelde services

- `ConnectorHealthService`: checks heartbeat, version, capabilities and permissions.
- `ConnectorEventIngestionService`: validates inbound events and records domain events.
- `ConnectorRolloutService`: manages upgrades and compatibility checks.

### Voorgestelde jobs

- `CheckConnectorHealthJob`: scheduled health checks per installation.
- `ProcessConnectorEventJob`: async event normalization.
- `RolloutConnectorVersionJob`: controlled rollout execution.

### Voorgestelde UI routes

- `settings.connectors.health`: health overview per installation.
- `admin.connectors.rollouts`: platform rollout management.
- `settings.connectors.events`: event history and failures.

### Risico's

- Connector tokens and scopes are security-critical.
- Inbound event replay must be idempotent.
- Capability changes can silently break publishing unless validated before use.

### Teststrategie

- API tests for token scopes, event replay and invalid payloads.
- Featuretests for health UI and rollout status.
- Unit tests for capability hash comparison and scope enforcement.

## 7. LLM Runtime

### Bestaande Argusly onderdelen

- Modellen/data: `LlmProvider`, `LlmModel`, `LlmSetting`, `LlmRequest`, `App\Data\Llm\LlmRequest`, `LlmResponse`, `LlmUsage`.
- Services: `LlmClientManager`, `LlmPromptRuntime`, `LlmRequestTracker`, `LlmRuntimeGuard`, `LlmRuntimeRouter`, `LlmProviderRegistry`, `LlmModelRegistry`, `LlmResolver`, `LlmSettingsService`.
- Clients: OpenAI, Anthropic, Google, Groq, Mistral, OpenRouter and fake client.
- Routes/UI: admin LLM providers/models/settings, runtime monitor, account LLM settings.
- Tests: `LlmRuntimeInterfaceTest`, `LlmProviderFoundationTest`.

### Ontbrekende onderdelen

- Prompt registry beyond visibility prompt templates.
- Evaluation dataset and replay support for runtime changes.
- Explicit model fallback policy with budget, latency and capability constraints.
- Tenant-safe prompt/input redaction policy.

### Voorgestelde nieuwe tabellen

- `llm_prompt_templates`: key, module, purpose, version, body, variables, status, metadata.
- `llm_evaluations`: prompt_template_id, model_id, dataset_key, score, status, output_summary, metadata.
- `llm_fallback_policies`: account_id/brand_id nullable, purpose, primary_model_id, fallback_model_id, constraints.
- `llm_redaction_rules`: scope, field_path/pattern, action, status.

### Voorgestelde services

- `LlmPromptRegistryService`: central prompt lookup and versioning.
- `LlmEvaluationService`: replay and score prompts against fake or live providers.
- `LlmFallbackPolicyService`: resolves fallback chain before runtime.
- `LlmRedactionService`: redacts logged inputs/outputs before tracking.

### Voorgestelde jobs

- `RunLlmEvaluationJob`: async eval run.
- `ReplayLlmPromptDatasetJob`: compares model/prompt changes.
- `PruneLlmRequestPayloadsJob`: retention and privacy cleanup.

### Voorgestelde UI routes

- `admin.llm.prompts`: prompt registry.
- `admin.llm.evaluations`: evaluation results and model comparisons.
- `admin.llm.fallbacks`: fallback policy management.

### Risico's

- New generic prompt registry must not conflict with `VisibilityPromptTemplate`.
- Live evaluations can consume credits unexpectedly.
- Runtime logging must avoid sensitive data retention.

### Teststrategie

- Unit tests for fallback resolution, redaction and prompt version lookup.
- Featuretests for admin prompt/evaluation routes.
- Integration tests using `FakeLlmClient` and budget guard exceptions.

## 8. Credits en billing

### Bestaande Argusly onderdelen

- Modellen: `CreditBalance`, `CreditTransaction`, `CreditCostCatalog`, `CreditCostOverride`, `CreditUsageStat`, `BillingInvoice`, `Plan`, `PlanFeature`, `PlanEntitlement`, `FeatureLimit`, `AccountEntitlement`, `Subscription`.
- Services: `CreditService`, `CreditCostResolver`, `CommercialOperationsService`, `MollieBillingService`, `EntitlementService`, `PlanResolver`, `SubscriptionService`.
- Routes/UI: admin billing, credits, cost catalog, overage recording, invoice creation, Mollie checkout and webhook.
- Tests: `CreditUsageTest`, `CreditCostCatalogTest`, `CommercialOperationsTest`, `CommercialEntitlementArchitectureTest`, `SubscriptionArchitectureTest`.

### Ontbrekende onderdelen

- Forecasting and alerts per module, brand and cost category.
- Reservation/hold pattern for multi-step jobs before actual credits are consumed.
- Billing reconciliation between invoices, usage stats and external provider status.

### Voorgestelde nieuwe tabellen

- `credit_reservations`: account_id, brand_id, subject, amount, status, expires_at, consumed_transaction_id.
- `credit_forecasts`: account_id, brand_id, period, projected_usage, projected_overage, drivers.
- `billing_reconciliations`: invoice_id/provider reference, status, checked_at, mismatch_summary.

### Voorgestelde services

- `CreditReservationService`: reserve, consume or release credits for queued jobs.
- `CreditForecastService`: forecast burn rate and low-credit dates.
- `BillingReconciliationService`: compares local billing state with provider state.

### Voorgestelde jobs

- `ExpireCreditReservationsJob`: releases stale holds.
- `RefreshCreditForecastsJob`: scheduled usage projection.
- `ReconcileBillingProviderJob`: provider reconciliation.

### Voorgestelde UI routes

- `admin.credits.forecasts`: forecast and module-level usage.
- `admin.billing.reconciliations`: mismatch review.
- `settings.billing.usage`: workspace-facing usage and forecast.

### Risico's

- Reservations can double-charge if not transactionally linked to final consumption.
- Forecasts can be misleading without enough historical data.
- Billing provider webhooks must remain idempotent.

### Teststrategie

- Unit tests for reservation state transitions.
- Featuretests for admin billing reconciliation views/actions.
- Transaction tests around concurrent credit consumption.

## 9. Platform Operations

### Bestaande Argusly onderdelen

- Modellen: `FeatureFlag`, `WebhookEndpoint`, `WebhookDelivery`, `WorkerHeartbeat`, `SignalAlert`, `ActivityLog`, `DomainEvent`, `DomainEventProjectorRun`, `OutboxMessage`.
- Services: `PlatformHealthService`, `QueueHealthService`, `SchedulerMonitorService`, `AlertService`, `ActivityLogger`, `DomainEventService`, `OutboxService`, `WebhookDeliveryService`.
- Jobs: `RecordWorkerHeartbeatJob`, `ProcessOutboxMessageJob`, `ProjectDomainEventJob`, `DispatchWebhookDeliveryJob`, `RetryWebhookDeliveryJob`, `GenerateScheduledReportsJob`.
- Routes/UI: admin platform overview, queues, alerts, webhooks, feature flags, runtime monitor, activity/developer tools.
- Tests: `PlatformOperationsTest`, `OperationalMonitoringTest`, `AdminControlCenterTest`, `DomainEventSpineTest`, `OutboxPatternTest`, `ActivityLogTest`.

### Ontbrekende onderdelen

- Unified incident timeline that ties alerts, failed jobs, source syncs, webhooks and LLM errors together.
- Scheduler run audit for all critical recurring commands.
- Operational SLO definitions per module.

### Voorgestelde nieuwe tabellen

- `operation_incidents`: scope, severity, status, title, detected_at, resolved_at, owner_id.
- `operation_incident_events`: incident_id, event_type, subject, summary, metadata.
- `scheduler_runs`: command, status, started_at, finished_at, duration_ms, output_summary.
- `module_slos`: module_key, metric_key, target, window, status.

### Voorgestelde services

- `IncidentService`: creates and updates incidents from alerts and failures.
- `SchedulerAuditService`: records scheduled command runs.
- `ModuleSloService`: computes module health against operational thresholds.

### Voorgestelde jobs

- `AggregateOperationalIncidentsJob`: groups related failures.
- `EvaluateModuleSloJob`: scheduled SLO evaluation.
- `RecordSchedulerRunJob`: wrapper or event hook for scheduled tasks.

### Voorgestelde UI routes

- `admin.platform.incidents`: incident overview.
- `admin.platform.incidents.show`: timeline and affected tenants/modules.
- `admin.platform.scheduler`: scheduled run history.
- `admin.platform.slos`: module SLO dashboard.

### Risico's

- Incident grouping can hide unique failures if correlation is too broad.
- Scheduler auditing must not fail the scheduled command path.
- Operational data can expose tenant details to platform users; admin authorization must remain strict.

### Teststrategie

- Featuretests for admin-only incident and scheduler screens.
- Unit tests for incident correlation and SLO evaluation.
- Queue tests for failed job aggregation and webhook retry observability.

## Cross-module implementatievolgorde

1. Stabiliseer shared contracts: prompt/runtime logging, credit reservation, domain events and tenant-safe evidence references.
2. Voeg persistence toe waar de bestaande lifecycle ontbreekt: opportunities, prompt versions, generation lineage and distribution plans.
3. Bouw orchestration services bovenop bestaande services; hergebruik bestaande jobs waar mogelijk.
4. Voeg UI routes toe als read-first dashboards, daarna pas mutating actions.
5. Breid tests per module uit met tenant isolation, policy gates, queue idempotency and regression coverage for existing routes.

## Algemene risico's

- Te veel nieuwe tabellen kunnen de bestaande eenvoudige moduleflows vertroebelen.
- Automatisering rond agents, runtime en distribution moet altijd permissions, module access, feature flags en credits respecteren.
- Bestaande dirty worktree-wijzigingen lijken al veel fundamenten te bevatten; volgende implementatiestappen moeten eerst met `git diff` worden gevalideerd.
- LLM-, connector- en billing-onderdelen zijn operationeel gevoelig; implementatie moet fake providers en idempotente jobs als standaard gebruiken.

## Algemene teststrategie

- Start elke module met schema/model/policy tests en tenant-isolatie.
- Gebruik fake LLM, fake connector, fake social/email providers en queue fake voor workflowtests.
- Voeg featuretests toe voor nieuwe routes inclusief permissions en module gates.
- Voeg regression tests toe voor bestaande routes die worden uitgebreid, vooral content, visibility, intelligence, distribution, connectors en admin.
- Voeg concurrency tests toe voor credits, reservations, publishing retries and agent task execution.
