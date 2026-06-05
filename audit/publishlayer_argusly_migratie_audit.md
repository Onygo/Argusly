# PublishLayer -> Argusly Migratie Audit

Datum: 2026-06-05  
Scope: PublishLayer codebase `/Users/ricardohagens/Sites/_project_publishlayer/publishlayer` vergeleken met Argusly `/Users/ricardohagens/Sites/argusly`.

## Samenvatting

PublishLayer is een volwassen content intelligence en content operations platform met sterke productieflows: briefings, AI-drafts, SEO/GEO/AEO-scoring, LLM-visibility tracking, content lifecycle, vertalingen, WordPress/Laravel publicatie, social distribution en agentic marketing. De grootste waarde zit niet in losse schermen, maar in de end-to-end keten: van website/competitor/research input naar concrete content, distributie, meting en optimalisatie.

Argusly heeft inmiddels een breder en beter passend strategisch fundament: accounts/brands, intelligence signals, AI visibility, competitors, evidence, graph, topics, content assets, campaigns, social posts, connector protocol, LLM runtime, feature flags, platform operations en influencer/relationship intelligence. De meeste PublishLayer-concepten zijn dus al gedeeltelijk aanwezig, maar vaak nog als foundation of generieke workflow. De migratie moet daarom selectief zijn: hergebruik vooral algoritmes, promptlogica, governancepatronen, jobs en data-normalizers; bouw de UI en naamgeving opnieuw in Argusly-taal.

Belangrijkste advies: migreer PublishLayer niet als contentplatform in Argusly. Migreer de intelligentie-, orkestratie- en publicatiecapaciteiten als modules binnen Argusly's intelligence operating system.

## Bronnen in de codebase

Belangrijkste PublishLayer-bewijs:

- App routes: `routes/app.php`
- API/headless routes: `routes/api.php`
- Admin routes: `routes/admin.php`
- Modellen: `app/Models/*`
- Domeinservices: `app/Services/*`
- Jobs: `app/Jobs/*`
- Schermen: `resources/views/app/*`, `resources/views/admin/*`
- Tests: `tests/Feature/*`, `tests/Unit/*`

Belangrijkste Argusly-bewijs:

- Routes: `/Users/ricardohagens/Sites/argusly/routes/web.php`, `/Users/ricardohagens/Sites/argusly/routes/api.php`
- Modellen/services: `/Users/ricardohagens/Sites/argusly/app/Models`, `/Users/ricardohagens/Sites/argusly/app/Services`
- Migraties: `/Users/ricardohagens/Sites/argusly/database/migrations`
- Bestaande planfile: `/Users/ricardohagens/Sites/argusly/docs/publishlayer-functional-audit-argusly-plan.md`

## Fase 1: Functionele Inventarisatie

| Functionaliteit | Doelgroep | Businesswaarde | Rollen | Belangrijkste schermen/routes | AI | Integraties | Status |
|---|---|---|---|---|---|---|---|
| Organizations, Workspaces, Brands | Agencies, marketingteams, platform admins | Tenantisolatie, klantbeheer, governance | Admin, owner, teamlid, support | `/dashboard`, `/sites`, `/brand/*`, admin organizations/users | Brand/company enrichment | Billing, invites | Volledig |
| Brand voice, personas, writer profiles | Contentteams, agencies | Consistente tone-of-voice en doelgroepfit | Marketeer, editor | `/brand/company-profile`, `/brand/voices`, `/brand/personas`, writer profiles | Profielanalyse, tone matching | Website crawler | Volledig |
| Onboarding scan & workspace intelligence | Nieuwe klanten | Snelle setup en automatische contextopbouw | Owner, marketer | `/onboarding`, `/workspace-intelligence` | Website-analyse, company intelligence | Website crawling | Gedeeltelijk/sterk |
| Briefings | Marketeers, editors | Minder briefingwerk, betere contentinput | Marketeer, editor, writer | `/content/create`, `/content/workspace/{brief}` | Brief enhancement, gap analysis | Research, URL extraction | Volledig |
| URL/source-to-brief | Contentteams | Hergebruik bestaande bronnen als briefing | Editor, marketer | `/content/create/from-url/*` | Source analysis, brief generation | URL fetcher | Volledig |
| Research projects | Strategen, agencies | Bronnen verzamelen en findings naar briefings brengen | Marketeer, strategist | `/research` | Extraction, summaries | Web sources | Gedeeltelijk |
| Draft generation | Contentteams | Snelle eerste versies, schaalbare productie | Writer, editor | workspace drafts, `/drafts/{draft}` | LLM content generation | LLM providers | Volledig |
| Draft intelligence | Editors, SEO/content leads | Kwaliteitscontrole, verbeteradvies | Editor | draft analysis/show | Metrics, recommendations, LLM visibility scoring | LLM | Volledig |
| Draft comparison & hybrid drafts | Agencies, high-volume teams | Modelselectie en kwaliteitsoptimalisatie | Editor, lead | `/content/workspace/{brief}/compare` | Multi-model generation/scoring | LLM providers | Volledig |
| Content lifecycle | Content managers | Workflowstatus, review, refresh nodig | Editor, reviewer, owner | `/content/lifecycle`, content detail | Decay/refresh analysis | Analytics | Volledig |
| Content series & clusters | Strategen, SEO teams | Topic clusters/pillar content plannen | Marketeer, editor | `/content/series`, `/campaign-clusters` | Strategy generation, cluster scoring | Internal content graph | Volledig |
| Content batches | Agencies | Bulkproductie en queuebeheer | Content manager | `/content/batches` | Batch brief/draft generation | Queue/credits | Volledig |
| Content automations | Agencies, content ops | Periodieke contentproductie | Owner, marketer | `/content/automations` | Planning, generation | Scheduler/queue | Volledig |
| Content calendar | Marketeers | Planning en overzicht | Marketeer | `/content/calendar` | Quick plan | Publishing schedule | Volledig |
| SEO audits | SEO teams | Technische en content-SEO verbeteringen | SEO, admin | `/sites/{site}/insights/audits` | AI fix suggestions | WordPress SEO providers | Volledig |
| GEO/AEO/answer blocks | AI visibility/content teams | Antwoordgeschikte content, structured answers | Editor, SEO | content answer blocks, `.answers` | Answer block generation, AEO scoring | Markdown/API | Volledig |
| LLM visibility tracking | Brand/AI visibility teams | Zichtbaarheid in AI-antwoorden meten | Marketeer, analyst | `/sites/{site}/insights/llm` | Query runs, answer parsing, scoring | LLM providers | Volledig |
| Competitor intelligence | Strategen, marketers | Concurrentiepatronen en gaps vinden | Marketeer, analyst | competitors, competitor-intelligence | Topic/entity extraction, opportunity scoring | Manual/import sources | Volledig |
| Content opportunity engine | Growth/content teams | Nieuwe contentkansen prioriteren | Marketeer | `/agentic-marketing/content-opportunities` | Candidate generation/scoring | Content graph, competitors | Volledig |
| Opportunity intelligence | Strategen | Signalen naar acties vertalen | Marketeer, strategist | `/agentic-marketing/intelligence` | Recommended actions | Signals | Volledig |
| Agentic marketing objectives/actions | Agencies, growth teams | Governed AI-acties op marketingdoelen | Owner, marketer, approver | `/agentic-marketing`, actions, approvals | Planning, decision engine, approval policy | Credits, queues | Volledig/experimenteel |
| Campaign planner/orchestration | Marketeers | Campagnes plannen en assets genereren | Marketeer | campaign planner, orchestration | Campaign plan/assets | Social/content | Volledig |
| Social distribution LinkedIn | Social/content teams | Content hergebruiken als posts | Social manager | `/agentic-marketing/distribution` | Post variants, language agent | LinkedIn OAuth/API | Gedeeltelijk/volledig MVP |
| Multi-account social publishing | Agencies | Publiceren over meerdere accounts | Social manager | distribution accounts/publications | Variant generation | LinkedIn | Gedeeltelijk |
| Analytics/performance | Marketeers | Performancefeedback en optimalisatie | Analyst, marketer | `/sites/{site}/insights/analytics`, dashboards | Insights | PL analytics script | Volledig |
| Internal linking/link intelligence | SEO/content teams | Betere topical authority en interlinking | Editor, SEO | content chain, draft link suggestions | Entity/relevance scoring | Embeddings | Volledig |
| Content network analysis | SEO strategen | Content graph gaps en clusters | SEO, strategist | `/content-network` | Cluster/gap analysis | Internal graph | Gedeeltelijk |
| Publication destinations | Developers/content ops | Headless en site-publicatie | Developer, admin | destinations/API docs | Geen kern-AI | API keys, webhooks | Volledig |
| WordPress publishing | Contentteams | Directe publicatie naar WordPress | Editor, admin | site setup, publish now | SEO metadata sync | WordPress plugin/API | Volledig |
| Laravel connector | Developers, owned platforms | Publicatie naar Laravel sites | Developer | Laravel destination setup/API | Geen kern-AI | Connector API | Volledig |
| Markdown/LLMs artifacts | AI/search teams | AI-readable contentoutput | Developer, SEO | `.md`, markdown index | Markdown normalization | API/connector | Volledig |
| Image generation & presets | Contentteams | Featured/OG images produceren | Editor | image presets, content images | Image prompts/generation | LLM/image provider, Unsplash | Volledig |
| Translations/localization | Internationale teams | Localevarianten en SEO-localisatie | Editor, translator | translate/localization actions | Translation prompts | WordPress sync | Volledig |
| Billing, credits, entitlements | SaaS/commercial | Kostenbeheersing en monetization | Owner, admin | billing, admin billing | Cost estimation | Mollie | Volledig |
| LLM layer | Platform/admin | Provider routing, logging, cost control | Admin | admin LLM settings/monitor | Model manager/routing | OpenAI, Mistral, Gemini etc. | Volledig |
| Platform operations | Platform team | Stabiliteit en support | Admin/support | queues, system health, feature flags | Geen kern-AI | Jobs, webhooks | Volledig |
| Plugin updates/licenses | WordPress klanten | Connector updates en licensing | Admin, developer | plugin endpoints/admin | Geen kern-AI | WP plugin releases | Volledig |

## Fase 2: Klantperspectief Analyse

| Functiecluster | Probleem | Workflow | Input | Output | Waarde | AI-rol | Frequentie |
|---|---|---|---|---|---|---|---|
| Workspace/brand setup | Klantcontext ontbreekt | Account maken, site/brand toevoegen, crawl bevestigen, tone/personas aanvullen | Domein, merkinfo, team | Workspace context, brand profile | Snellere onboarding en consistentere output | Analyseert website en profielvelden | Eenmalig + per kwartaal |
| Content intelligence | Teams weten niet welke content werkt of ontbreekt | Site kiezen, audit/run starten, scores bekijken, aanbevelingen uitvoeren | Site, content, querysets | Scores, issues, aanbevelingen | Kwaliteitsverbetering, minder handmatige analyse | Scoring, diagnose, fixes | Wekelijks/maandelijks |
| AI visibility | Merken weten niet of LLMs hen noemen/citeren | Prompts/querysets beheren, runs uitvoeren, trends bekijken | Prompt, markt, competitors | Presence, citations, answer share, findings | Nieuwe marktstandaard voor zichtbaarheid | Antwoorden ophalen/parseren/scoren | Wekelijks/dagelijks |
| Agentic marketing | Signalen worden niet omgezet naar acties | Objective instellen, kansen laten detecteren, acties reviewen, uitvoeren | Doel, budget, policy | Acties, assets, audit trail | Tijdswinst en snellere campagne-iteratie | Planner, decision engine, copy/asset generatie | Dagelijks/wekelijks |
| Content productie | Briefings/drafts kosten veel tijd | Brief maken, AI verbeteren, draft genereren, vergelijken, reviseren | Brief, bronnen, tone | Draft, score, recommendations | 50-80 procent minder eerste-versie tijd | Generatie, vergelijking, verbetering | Dagelijks |
| Publicatie | Publiceren en synchroniseren is foutgevoelig | Destination koppelen, plannen/publishen, remote status verifiëren | Site credentials, content | Publicatie, sync status, events | Minder handwerk en minder publicatiefouten | Beperkt; metadata/SEO assist | Dagelijks/wekelijks |
| Social distribution | Content wordt onvoldoende hergebruikt | Content kiezen, varianten genereren, account kiezen, plannen/publiceren | Content, account, planning | LinkedIn posts, publications | Meer bereik uit bestaande content | Social copy variants | Wekelijks |
| Monitoring/reporting | Management mist overzicht | Dashboard, rollups, reports, alerts | Integraties, events, metrics | Performance, alerts, reports | Betere beslissingen, minder statusmeetings | Samenvattingen/insights | Dagelijks/maandelijks |
| AI runtime/platform | AI-kosten en fouten zijn onzichtbaar | Providers instellen, routing volgen, requests monitoren | Models, limits, prompts | Logs, cost, latency, errors | Kostencontrole en betrouwbaarheid | Runtime logging/routing | Continu |

## Fase 3: Mapping naar Argusly

| PublishLayer functie | Argusly status | Argusly equivalent | Beoordeling |
|---|---|---|---|
| Workspaces/Organizations/Teams | Gedeeltelijk aanwezig | Account, Brand, Membership, permissions | Fundament sterk; invites/team UX missen polish |
| Brands/brand voice/personas | Gedeeltelijk aanwezig | BrandProfile, BrandNarrative, BrandEntity, BrandKnowledgeCenter | Argusly betere strategische richting; PL profielanalyse bruikbaar |
| Content audits | Gedeeltelijk aanwezig | ContentAudit, ContentAuditService | Argusly basis; PL SEO/AEO detail dieper |
| SEO audits | Ontbreekt/gedeeltelijk | ContentAudit/Search Console/PerformanceInsight | PL crawler en AI-fix logica waardevol |
| GEO/AEO answer blocks | Gedeeltelijk aanwezig | AnswerBlock, Visibility, ContentAsset | PL answer block generation/settings sterker |
| AI visibility tracking | Reeds aanwezig/gedeeltelijk | VisibilityCheck, PromptTemplate, Citation, ProviderRun | Argusly foundation sterk; PL scoring/analyzer/aggregates herbruikbaar |
| Competitor intelligence | Reeds aanwezig/gedeeltelijk | Competitor, snapshots, intelligence signals | Argusly heeft module; PL opportunity scoring verdiept |
| Content opportunity engine | Gedeeltelijk aanwezig | OpportunityDiscoveryService, recommendations | PL candidate/scoring engine waardevol |
| Briefings | Gedeeltelijk aanwezig | Briefing, Approval | PL source-to-brief en enhancement ontbreken deels |
| Draft generation | Gedeeltelijk aanwezig | ContentAsset, GeneratedAsset, ContentGenerationService | PL draft lifecycle/comparison/versioning dieper |
| Draft comparison | Ontbreekt | Geen duidelijke equivalent | Should have voor kwaliteitspositionering |
| Content lifecycle | Gedeeltelijk aanwezig | ContentLifecycleScore, ContentOperations | PL workflow en dashboard veel completer |
| Content series/clusters | Gedeeltelijk aanwezig | TopicCluster, CampaignBoard, MarketingCalendar | PL content-series generatie waardevol |
| Content automations | Ontbreekt/gedeeltelijk | Agent tasks/marketing tasks | PL automation orchestrator herbruikbaar als governed workflow |
| WordPress publishing | Gedeeltelijk aanwezig | Connector protocol, PublishingAction | Argusly protocol bestaat; PL WP driver/plugin-ervaring waardevol |
| Laravel connector | Gedeeltelijk aanwezig | Connector protocol | Argusly protocol beter; PL payload/status patterns bruikbaar |
| API/headless/webhooks | Gedeeltelijk aanwezig | Connector API, Outbox, Webhooks | Argusly foundation; PL scopes/api docs mature |
| LinkedIn distribution | Reeds aanwezig/gedeeltelijk | LinkedInIntegration, SocialPost, SocialPublishing | PL variant/audit/schedule logic verdiept |
| Multi-account social | Gedeeltelijk aanwezig | SocialProfile, SocialPost | PL publishing/rate limit governance bruikbaar |
| Analytics/performance | Gedeeltelijk aanwezig | AnalyticsSite/Event, GA4, Search Console | Argusly heeft betere external analytics richting; PL script bruikbaar |
| LLM provider/routing/logging | Reeds aanwezig/gedeeltelijk | LlmProvider, LlmModel, LlmRequest, RuntimeRouter | PL routing rules/audit/cost details bruikbaar |
| Billing/credits | Reeds aanwezig/gedeeltelijk | CreditService, CreditCostCatalog, subscriptions | Argusly foundation nieuwer; PL reservations/quotes nuttig |
| Feature flags/platform ops | Reeds aanwezig | FeatureFlag, Platform operations | Argusly heeft dit al grotendeels overgenomen |
| Support/admin queues | Reeds aanwezig/gedeeltelijk | PlatformQueue, Health, Alerts | Argusly basis; PL queue admin dieper |
| Influencer intelligence | Argusly beter | Influencer/Relationship/Mention intelligence | Niet uit PL migreren |

## Fase 4: Gap Analyse

| Gap | Wat ontbreekt | Belang | Inspanning | Afhankelijkheden | Herbruikbare PL-code | Prioriteit |
|---|---|---|---|---|---|---|
| AI visibility scoring diepte | Provider-aware answer presence, authority entities, aggregates | Kern voor Argusly positionering | M | Visibility foundation, LLM runtime | `LlmTrackingAnalyzer`, `LlmVisibilityScoreCalculator`, aggregate builder | Must Have |
| Citation/source monitoring | Bronregels, citation classification, source presence | Onderscheidende AI Visibility waarde | M | Visibility citations/evidence | `LlmSourceRule`, source parsers | Must Have |
| SEO/AEO audit engine | Crawler, issue model, AI fix suggestions | Content quality en opportunity input | L | ContentAsset, Source/Property | `SeoAuditCrawlerService`, `SeoAuditAiFixService`, AEO services | Should Have |
| Source-to-brief workflow | URL/research naar briefing | Snelle waarde voor agencies | M | Briefing, Research, Sources | `SourceBriefingService`, `UrlSourceFetcher`, `BriefIntelligenceService` | Must Have |
| Draft comparison | Multi-model varianten, scoring, winner/hybrid | Kwaliteitscontrole en AI trust | M/L | GeneratedAsset, LLM runtime, credits | DraftComparison services/jobs | Should Have |
| Content lifecycle dashboard | Review/approval/refresh workflow | Content ops bruikbaar maken | M | ContentAsset, Approval, lifecycle scores | ContentLifecycle services/events | Must Have |
| Content series generation | Pillar/cluster strategie + artikelen | Agentic marketing output | M | Topics, Campaigns, ContentAsset | SeriesStrategy/Structure/Article services | Should Have |
| Content automations | Periodieke governed production runs | Schaalbare agencies | L | Agent framework, credits, approvals | ContentAutomation orchestrator | Should Have |
| Internal linking graph | Suggesties, anchors, apply flow | SEO/GEO authority | M | ContentAsset, Topics, Graph | LinkIntelligence/InternalLinking services | Should Have |
| LinkedIn variant generation | Platform-copy, approvals, schedule/publish | Agentic distribution | M | SocialPost, LinkedIn service | SocialDistribution variant provider/publisher | Must Have |
| Publishing connector depth | WordPress/Laravel status, remote verify, images | Productie betrouwbaar maken | L | Connector protocol, PublishingAction | WP delivery, Laravel payload/status services | Should Have |
| AI runtime governance | Routing rules, budget guard, audit logs | Kosten en betrouwbaarheid | M | LlmRequest, providers/models, credits | LlmRoutingService, cost estimator, audit service | Must Have |
| Queue/support diagnostics | Failed job detail, retry, worker heartbeat | Operations | S/M | Platform ops | QueueAdminService, commands | Could Have |
| Credit reservations | Reserve/capture/refund per AI actie | Kostencontrole | M | CreditCostCatalog/CreditService | CreditReservationService, quote service | Should Have |
| Brand/writer profile fit | Auteur/tone fit en source profiles | Outputkwaliteit | M | Brand knowledge center | WriterProfile services | Could Have |
| Unsplash/image attribution | Stock image selection with attribution | Snelle visuele assets | S | ContentAsset media | UnsplashImageService | Could Have |

## Fase 5: Product Positionering in Argusly

| Argusly structuur | PublishLayer functies die passen | Migratiehouding |
|---|---|---|
| Core Intelligence: Brand Monitoring | Workspace intelligence, company profiles, analytics, content performance, alerts | Herbouw in Argusly signal/evidence model |
| Core Intelligence: Competitor Intelligence | Competitor import, topic/entity extraction, coverage comparison, opportunity scoring | Scoring en analyzers migreren; UI opnieuw |
| Core Intelligence: Campaign Intelligence | Campaign planner, distribution plans, learning optimization | Hergebruik services/jobs waar logisch |
| Core Intelligence: Alerts | Notifications, low-credit warnings, lifecycle decay, failed publication | Koppel aan Argusly `SignalAlert` |
| AI Visibility: Prompt Coverage | LLM query sets, scheduled runs | Samenvoegen met Argusly Prompt Templates |
| AI Visibility: Answer Presence | LLM visibility score, AI attention fields | PL scoring gebruiken als verdieping |
| AI Visibility: Citation Monitoring | Source rules, citation/source extraction | Must-have uitbreiding |
| AI Visibility: RAG Footprint | Markdown artifacts, answer blocks, source/LLMs files | Herbouw als Argusly source footprint |
| Opportunity Discovery: Content Gaps | Content opportunity engine, content network, SEO audit gaps | PL engines als input voor Argusly opportunities |
| Opportunity Discovery: Trend Detection | Research findings, query intent, competitor topic signals | Koppelen aan topics/signals |
| Opportunity Discovery: Topic Velocity | Content clusters, topic extraction | Integreren met Topic Intelligence |
| Opportunity Discovery: Competitor Moves | Competitor content import/analyze | Migreren als signal producer |
| Agentic Marketing: Campaign Planning | Campaign planner, clusters, objectives | PL workflow als referentie; Argusly campaign board als UI |
| Agentic Marketing: Content Generation | Brief-to-draft, series, batches, automations | Selectief migreren rond ContentAsset |
| Agentic Marketing: Distribution | LinkedIn variants, WordPress/Laravel publishing | Connector/social modules verdiepen |
| Agentic Marketing: Optimization | Lifecycle, content improvement, learning optimization | Koppelen aan recommendations/tasks |
| Influencer Intelligence | Geen directe PL-kern | Argusly heeft betere eigen module; PL social metrics alleen indirect bruikbaar |

## Top 20 direct overnemen of vertalen

1. LLM visibility analyzer en scorecalculator.
2. LLM tracking aggregates en AI attention dashboard builder.
3. Source/citation rules voor AI visibility.
4. Source-to-brief pipeline.
5. Brief intelligence/gap analyzer.
6. Draft intelligence recommendation engine.
7. Draft comparison en hybrid draft workflow.
8. AEO score en structured answer block generation.
9. SEO audit crawler en AI fix suggestions.
10. Content lifecycle transition/event model.
11. Content decay/refresh recommendation engine.
12. Content opportunity engine scoring.
13. Competitor content pattern detector en coverage comparator.
14. Query intent classification/scoring.
15. Internal link intelligence en anchor suggestions.
16. Campaign cluster planning engine.
17. LinkedIn social variant generation provider.
18. Social publication audit/rate-limit patterns.
19. LLM routing rules, cost estimator en request audit logs.
20. Credit reservation/quote/capture patterns voor AI-acties.

## Functionaliteiten die Argusly al heeft

Argusly heeft reeds: tenant/account/brand model, rollen/rechten, modules/entitlements, dashboard, intelligence signals, recommendations, evidence, graph projection, topics/clusters, mentions, relationships/influencer intelligence, competitor foundation, AI visibility foundation, prompt templates, citations, visibility schedules, content assets, generated assets, answer blocks, content audits, lifecycle scores, campaigns, campaign board, marketing calendar, tasks, briefings, newsletters, social posts, social profiles, LinkedIn/Google integrations, connector protocol, publishing actions, analytics/GA4/Search Console, LLM providers/models/requests/runtime router, credits/cost catalog, feature flags, platform queues/alerts/webhooks, admin control center and reporting.

## Functionaliteiten die ontbreken of onvoldoende diep zijn

Hoogste prioriteit:

- AI visibility scoring en citation intelligence op PublishLayer-niveau.
- Research/source-to-brief naar Argusly Briefings.
- Content lifecycle als echte workflow in plaats van placeholder/foundation.
- LinkedIn variant generation en governed social publishing.
- AI runtime routing, budgetten, audit en per-use-case controls.

Middelste prioriteit:

- SEO/AEO audit engine.
- Draft comparison en hybrid drafts.
- Content opportunity scoring uit competitors/content graph.
- Internal linking authority graph.
- Connector-publicatie met remote verification.

Later:

- Full content automations.
- Writer profile fit.
- Stock image/attribution workflow.
- PL plugin update/licensing, tenzij Argusly WordPress plugin commercieel wordt.

## Roadmap

### Sprint 1: AI Visibility en Runtime Core

- Migreer/vertaal PL visibility scoring, answer presence en citation/source rules.
- Voeg provider-aware run analysis toe op Argusly `VisibilityCheck`/`VisibilityResult`.
- Verdiep `LlmRequest` logging met prompt class, cost, latency, trace en tenant context.
- Voeg budget/routing guardrails toe.

### Sprint 2: Briefing, Research en Content Operations

- Bouw source-to-brief in Argusly `Briefing`.
- Voeg AI briefing enhancement en gap analysis toe.
- Maak content lifecycle workflow echt: review, approve, refresh-needed, history.
- Koppel recommendations/tasks aan lifecycle en briefings.

### Sprint 3: Opportunity Discovery en Agentic Marketing

- Vertaal PL content opportunity engine naar Argusly signals/opportunities.
- Voeg competitor pattern/coverage scoring toe.
- Bouw campaign cluster planning op Argusly topics/campaign board.
- Voeg approval-governed agent actions toe met audit trail.

### Sprint 4: Distribution en Connectors

- Verdiep LinkedIn social publishing met varianten, approvals, scheduling en audit logs.
- Verdiep connector protocol met pending content, remote status, failures, retries.
- Voeg WordPress/Laravel driverpatronen toe zonder PL route/naamgeving over te nemen.
- Voeg internal link suggestions en answer block publication footprint toe.

## Advies: letterlijk migreren, opnieuw bouwen, laten vervallen

Letterlijk of bijna letterlijk migreren als service/pattern:

- Scoring/normalizer services waar weinig UI-terminologie in zit: LLM visibility, AEO, query intent, competitor scoring, draft intelligence, link intelligence.
- Queue/jobpatronen voor long-running AI work.
- Cost estimation, request logging, credit reservation en audit logging patronen.
- Connector payload/status/verification concepten.

Opnieuw bouwen in Argusly:

- Alle customer-facing UI, navigatie en terminologie.
- Tenantmodellen rond workspace/brand/team.
- Briefing/content screens rond `ContentAsset`, `Briefing`, `GeneratedAsset`, `Signal`, `Recommendation`.
- Agentic marketing als Argusly workflow rond objectives, tasks, approvals en signals.

Laten vervallen of alleen als referentie gebruiken:

- PublishLayer branding, public marketing pages, plugin licensing als zelfstandig productconcept.
- Legacy `/briefs`/`/drafts` navigatie en PublishLayer-specific route names.
- PL-only admin subdomainstructuur.
- Diepe content publishing SaaS-positionering waar Argusly inmiddels breder intelligence-first is.

## Eindoordeel

PublishLayer is vooral waardevol als bewezen productielijn voor content intelligence en AI-assisted execution. Argusly heeft de betere strategische architectuur voor intelligence, visibility, opportunities en influencer/relationship context. De winnende migratie is daarom geen copy-paste, maar een transplantatie van volwassen engines: scoring, generation, auditability, governance en connector reliability. Daarmee krijgt Argusly sneller diepte zonder zijn productidentiteit te verliezen.
