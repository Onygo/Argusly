# Argusly roadmap execution plan

Datum: 2026-07-12
Gebaseerd op:

- `audit/argusly_functional_status_audit_2026-07-12.md`
- Aangeleverde roadmap/adviesnotities uit de projectgesprekken
- `docs/architecture/page-intelligence-roadmap.md`
- `docs/architecture/website-content-inventory-activation-audit.md`
- `docs/mos/core-phase-1-audit.md`
- `docs/programmatic-growth-beta-test-checklist.md`
- `docs/connector-production-activation-runbook.md`
- `audit/argusly_agentic_marketing_os_positioning_audit_2026-06-21.md`
- `docs/architecture/agentic-marketing-os-execution-guardrails.md`

## North star

Argusly moet niet verder groeien als losse AI-contenttool, SEO-tool of dashboard. De sterkste richting is:

> Argusly is een Agentic Marketing Operating System voor kennisintensieve B2B-organisaties. Het observeert markt, website, content, concurrenten, zoekmachines, AI-antwoorden en campagnes; bouwt daar marketingkennis uit op; vertaalt signalen naar kansen; stelt acties voor; organiseert uitvoering via content, social, email en publishing; meet resultaat; en leert daarvan.

De operating loop:

`Observations -> Knowledge -> Insights -> Recommendations -> Actions -> Execution -> Measurement -> Learning`

## Strategische keuze

De audit laat zien dat de meeste fundering al bestaat. De volgende fase moet daarom niet primair "meer losse features" zijn, maar:

1. Productiebetrouwbaarheid bewijzen.
2. Bestaande intelligentie zichtbaar en bruikbaar maken voor marketeers.
3. Relaties leggen tussen content, signalen, entiteiten, campagnes, sites, doelen en acties.
4. De agentic laag laten werken als gecontroleerde marketingmedewerker, niet als ongecontroleerde autopilot.

## Productpijlers

| Pijler | Betekenis | Huidige basis | Volgende stap |
| --- | --- | --- | --- |
| Marketing Operating System | Centrale werklaag voor objectives, signalen, kansen, acties, approvals, uitvoering en learning. | MOS registry, Opportunity, Signal Intelligence, Agent workflows, Recommended Actions. | Een samenhangende action queue en graph-context over alle modules. |
| Marketing Intelligence | Page, signal, search, GEO, competitor, analytics en connector data worden inzicht en kans. | Page Intelligence, Signal Intelligence, LLM tracking, SEO audit, connector observations. | Signal Intelligence 2.0 en evidence-to-action flows. |
| Content Operating System | Website inventory, content lifecycle, publishing, internal linking, refreshes en content activation. | Monitored Pages, Content Inventory, Content lifecycle, publishing, content chain intelligence. | Content Inventory UI en activation workflow als eerste zichtbare productwaarde. |
| Brand and Entity Intelligence | Een centrale laag voor merk, ICP, personen, producten, topics, concurrenten en positionering. | Company profile, brand voice, writer profiles, competitors, taxonomy, market packs. | Centraliseren tot Brand Intelligence en Entity Intelligence. |
| Agentic Marketing | Agents observeren, redeneren, plannen, genereren, laten goedkeuren, publiceren en leren. | Agentic Marketing, Programmatic Growth beta, approvals, publication readiness. | Guided workflows verdiepen, autonomie gated houden. |
| Campaign OS | Campagnes verbinden content, social, email, assets, planning en performance. | Campaigns, social variants, email export, publication plans, analytics. | Campagne-workspace als uitvoering bovenop graph en actions. |
| Executive Intelligence | CMO/management ziet kansen, risico's, KPI's, voortgang, ROI en aanbevolen acties. | Reports, scheduled briefings, dashboards, billing/analytics. | Executive dashboard pas na goede graph/action-data. |

## Fase 0: Launch hardening en bewijsbaarheid

Doel: het bestaande platform betrouwbaar genoeg maken voor controlled production pilots.

Indicatie: 1-2 weken.

Deliverables:

- Unit-suite repareren door `makeDraftIntelligenceContext()` naar gedeelde test support te verplaatsen of unit tests self-contained te maken. Status 2026-07-12: afgerond.
- Go-live checklist actualiseren naar de huidige codebase en afvinken per omgeving.
- Productie-env controleren: `APP_ENV=production`, `APP_DEBUG=false`, Sentry, Mailgun, Mollie, LLM keys, storage links, queues, scheduler.
- Queue/worker topology vastleggen voor default, AI, deliveries, billing, social, page intelligence en connector sync.
- Failed job, queue depth, connector health, LLM cost en publish success monitoring definieren.
- Feature-flag matrix maken: GA, beta, intern, uit, deprecated.
- Docs opschonen waar ze aantoonbaar stale zijn, vooral SEO RankMath/AIOSEO en connector release status.
- Skipped tests triagen en vastleggen waarom ze skippen.

Acceptatie:

- `composer validate`, `npm run build`, feature-suite en unit-suite slagen.
- Productie checklist heeft geen P0 open items.
- Operators weten welke queues, schedules en alerts kritisch zijn.
- Feature flags hebben eigenaar, status en rollout-besluit.

Niet doen in deze fase:

- Geen nieuwe grote productlaag bouwen.
- Geen autonome publishing aanzetten.
- Geen negen connectorproviders tegelijk activeren.

Voortgang 2026-07-12:

- Gedeelde test support toegevoegd voor Draft Intelligence unit tests.
- Unit-suite is groen: 880 tests, 4747 assertions.
- Architectuur-guardrails zijn vastgelegd in `docs/architecture/agentic-marketing-os-execution-guardrails.md`.
- Eerste operationele documentatie-update is gestart: queue topology, feature-flag matrix en launch gates.

## Fase 1: Content Inventory UI en activation MVP

Doel: de krachtige backend rond website inventory, Page Intelligence en analytics zichtbaar maken voor marketeers.

Indicatie: 2-4 weken.

Waarom eerst:

- De audit en roadmapnotities wijzen uit dat de backend grotendeels bestaat.
- Dit maakt Argusly direct tastbaar: "wat staat er op mijn website, wat presteert, wat ontbreekt, wat moet ik doen?"
- Het is de kortste route van bestaande techniek naar productwaarde.

Deliverables:

- Een Content Inventory hub per workspace/site. Status 2026-07-12: aanwezig in Page Intelligence tab.
- Overzicht van discovered/observed pages uit sitemap, analytics, manual, SERP/GEO en Page Intelligence.
- Status per page: observed, extracted, linked to content, promoted, ignored, stale, opportunity.
- Koppeling tussen `MonitoredPage`, `Content`, `ContentMetric`, `PageScore`, `SignalEvent` en public URL.
- Filters: source, page type, status, traffic, freshness, score, brand/competitor/topic match, missing content link.
- Acties: inspect, fetch/refresh, activate as content, link existing content, ignore, create refresh recommendation. Status 2026-07-12: refresh, exclude/include, activate en link existing content aanwezig; refresh recommendation nog open.
- Drawer met evidence: latest snapshot, extraction summary, metrics, score breakdown, detected entities/topics, related signals.
- Backfill/diagnostics scherm alleen voor operators of advanced users.

Acceptatie:

- Een marketeer kan binnen 5 minuten zien welke bestaande pagina's relevant zijn, welke niet in Argusly-content zitten, en welke acties logisch zijn.
- Geen parallel `WebsiteContent` domein wordt gebouwd; `MonitoredPage` blijft observed external page, `Content` blijft owned content asset.
- Inventory acties zijn tenant-safe en idempotent.

Voortgang 2026-07-12:

- `ContentPageLink` wordt nu direct vanuit Content Inventory gebruikt om een observed `MonitoredPage` te koppelen aan bestaand `Content`.
- De link-flow blijft idempotent via `WebsiteContentActivationService::linkExistingContent()`.
- De UI toont alleen site-passende of site-neutrale contentkandidaten.
- Gerichte Website Content Inventory-suite is groen: 18 tests, 105 assertions.

## Fase 2: Page Intelligence naar action layer

Doel: Page Intelligence niet alleen laten rapporteren, maar concrete marketingacties laten voorbereiden.

Indicatie: 3-5 weken, deels parallel na Fase 1.

Deliverables:

- Page opportunity types: stale content, missing AI citation readiness, weak entity coverage, competitor pressure, low topic authority, internal link gap, high-value page without CTA, poor freshness, SERP/GEO movement.
- Mapping van high-priority findings naar Recommended Actions. Status 2026-07-12: Page Alerts maken trigger-specifieke `RecommendedAction` records voor opportunity activation, risk response, competitor response en visibility movement.
- Page alerts verbinden met action queue en notifications.
- Scheduled briefings uitbreiden met concrete "next actions".
- Email delivery voor Page Intelligence reports afmaken, inclusief Mailgun delivery, retry, opt-out, audit en failure state.
- Market-pack-aware thresholds en templates gebruiken voor eerste sectoren.

Acceptatie:

- Elke belangrijke Page Intelligence finding heeft: evidence, impact, recommended action, eigenaar/status, en mogelijke execution route.
- Scheduled briefings zijn niet alleen rapporten maar werkvoorraad.
- Email delivery is niet langer placeholder.

Voortgang 2026-07-12:

- `PageAlertNotificationMapper` is uitgebreid met trigger-specifieke action anatomy.
- `RecommendedAction` metadata bevat nu een evidence package, action category en recommended next step vanuit Page Intelligence alerts.
- High-risk negative alerts leiden naar `prepare_reputation_response`.
- High-value/opportunity alerts leiden naar `amplify_page_opportunity`.

## Fase 3: Marketing Memory en Marketing Graph MVP

Doel: Argusly moet kennis opbouwen over relaties, niet alleen records opslaan.

Indicatie: 4-8 weken.

Concept:

Marketing Memory is de centrale kennislaag. Marketing Graph is de relatiekaart tussen alle marketingobjecten.

Minimale relaties:

- Website -> MonitoredPage -> Content -> ContentVersion -> ContentPublication.
- Content -> Campaign -> SocialPostVariant -> SocialPublication -> performance.
- SignalEvent -> SignalDetection -> OpportunitySignal -> Opportunity -> RecommendedAction.
- Entity -> PageMention -> Topic -> Competitor -> MarketPack.
- Brand profile -> ICP -> persona -> tone -> CTA -> funnel stage.
- LLM/GEO citation -> MonitoredPage -> brand/competitor/entity evidence.

Deliverables:

- Een read-model of service die graph-context kan samenstellen zonder bestaande domeinen te herschrijven. Status 2026-07-12: eerste `ContentInventoryGraphContextBuilder` projecteert Content Inventory links, Page Alerts en Recommended Actions naar bestaande Intelligence Graph interfaces.
- Universal Resource/Action metadata gebruiken als UI-ingang voor graph-links.
- Entity identifiers normaliseren voor brands, people, companies, products, technologies, competitors en topics.
- Graph context tonen in drawers: "waarom hoort dit bij elkaar?"
- Graph-backed recommendations: "deze pagina hoort bij deze campagne/topic/persona maar mist interne links/CTA/evidence".

Acceptatie:

- Een contentitem kan tonen welke campagne, doelgroep, persona, CTA, funnel stage, pillar, serie, competitor, signalen en AI citations eraan gekoppeld zijn of ontbreken.
- Een signal kan laten zien welke content/campaign/action het zou moeten triggeren.
- De graph is eerst een additive/read-model laag, niet een riskante rewrite van alle domeinen.

Voortgang 2026-07-12:

- Eerste additive Marketing Memory read-model toegevoegd voor `Content -> MonitoredPage -> PageAlert -> RecommendedAction`.
- Geen graph database of nieuwe brontabellen toegevoegd.
- De service gebruikt bestaande `IntelligenceGraphProjector`, `IntelligenceGraphNode` en `IntelligenceGraphEdge`.
- Marketing Memory context is zichtbaar gemaakt in de Page Intelligence drawer met linked content, actions, evidence count en relationship summary.

## Fase 4: Brand Intelligence en Entity Intelligence centraliseren

Doel: alle generatieve en analytische functies dezelfde merk- en entiteitskennis laten gebruiken.

Indicatie: 4-6 weken, parallel met Fase 3 waar mogelijk.

Deliverables:

- Brand Intelligence hub: company profile, brand voice, writing style, ICP, markets, tone, USP, positioning, proof points, forbidden claims.
- Entity Intelligence registry: companies, people, products, technologies, frameworks, competitors, topics, market terms.
- Entity extraction en matching vanuit Page Intelligence, Signal Intelligence, Research, LLM tracking, SEO audits en content.
- Confidence/evidence per entity-link.
- Governance: wie mag brand/entity data goedkeuren, vervangen of archiveren.
- Brand/entity context standaard injecteren in briefs, drafts, social posts, reports, recommendations en agent workflows.

Acceptatie:

- Nieuwe content en acties gebruiken dezelfde goedgekeurde brand/entity context.
- Signal en page findings kunnen worden gegroepeerd rond dezelfde entiteiten.
- Brand consistency wordt meetbaar en actionable.

Voortgang 2026-07-13:

- Eerste centrale `BrandIntelligenceContextService` toegevoegd als compacte read-laag boven bestaande `CompanyIntelligenceProfile`, `BrandVoice` en `BrandContext`.
- `CompanyIntelligenceContextService` exposeert nu dezelfde actieve profielselectie voor hergebruik.
- Page Intelligence `RecommendedAction` metadata bevat nu `brand_intelligence.snapshot.v1` met company, audience, voice, proof, entity en governance context.
- Agentic Marketing `SharedMarketingContextBuilder` gebruikt dezelfde snapshot en behoudt backward-compatible `company` output.
- Geen parallel brand source-of-truth model toegevoegd; `CompanyIntelligenceProfile` blijft canonical approved context.

## Fase 5: Signal Intelligence 2.0

Doel: Signal Intelligence wordt de commerciele radar van Argusly.

Indicatie: 6-10 weken.

Bronnen:

- Website and analytics.
- Competitors.
- SERP and GEO.
- LLM tracking and AI citations.
- News, RSS and press rooms.
- Social and LinkedIn.
- Data connectors: GA4, GSC, Ads, CRM.
- Research projects.

Deliverables:

- Signal source taxonomy opschonen rond source type, market, entity, confidence en retention.
- News/competitor/market monitoring als source packs.
- Trend detection: entity/topic velocity, competitor movement, AI answer share, content freshness, funnel gaps.
- Impact analysis: why it matters, affected products/markets/pages/campaigns, urgency, confidence.
- Promotion naar opportunities met evidence packages.
- Suggested campaigns/content/social/sales enablement acties.

Acceptatie:

- Een gebruiker ziet niet alleen "er is een signaal", maar "dit betekent dit, voor deze markt, met deze voorgestelde actie".
- Signal Intelligence voedt Opportunity Intelligence en Agentic workflows zonder duplicaat-opportunity chaos.

Voortgang 2026-07-13:

- Eerste `SignalDetectionImpactAnalyzer` toegevoegd als additive impactlaag boven bestaande detections en events.
- Signal Detection detailpagina toont nu impactanalyse met why-it-matters, business impact, urgency, confidence, affected scope en suggested actions.
- Promotie naar `OpportunitySignal` bevat nu `signal_impact.v1` en `signal_evidence_package.v1`.
- Dedupe, lifecycle en bestaande `SignalDetectionPromotionService` flow zijn ongemoeid gebleven.
- Gerichte Signal Intelligence-regressie is groen: 46 tests, 256 assertions.

## Fase 6: Agentic Marketing guided execution

Doel: van aanbevelingen naar gecontroleerde uitvoering met menselijke goedkeuring.

Indicatie: 8-12 weken.

Operating flow:

`Observe -> Reason -> Plan -> Approve -> Generate -> Publish/Schedule -> Monitor -> Learn -> Improve`

Deliverables:

- Unified action queue: aanbevolen acties uit Page, Signal, Content Inventory, SEO, Campaign en Programmatic Growth.
- Action anatomy: signal, cause, business impact, proposed execution, required assets, risk, credit estimate, approval state.
- Approval gates per operation: draft, update, internal link, schema, social, email, publish.
- Agent workflows voor:
  - refresh existing page
  - create answer block
  - create new article/landing page
  - add internal links
  - create LinkedIn/Instagram post
  - create newsletter snippet
  - prepare campaign response
- Programmatic Growth beta blijven gebruiken met safety expectation: lokale assets en schedules mogen, live publish niet automatisch.
- Autonomy policies per workspace/site/customer.

Acceptatie:

- Agents voeren geen live publish uit zonder expliciete policy en approval.
- Elke agentic actie heeft audit trail, idempotency key, rollback/recovery pad en duidelijke owner.
- De gebruiker ervaart Argusly als marketingmedewerker die werk voorbereidt, niet als zwarte doos.

## Fase 7: Campaign OS

Doel: campagnes worden de plek waar intelligence, content, social, email, planning en performance samenkomen.

Indicatie: 12+ weken, na graph/action basis.

Deliverables:

- Campaign workspace met objective, audience, market, funnel stage, key entities, channels, assets, timeline en KPIs.
- Campaign asset map: pages, articles, social variants, newsletter snippets, video/YouTube placeholders, sales enablement snippets.
- Campaign action queue: kansen, blockers, content gaps, distribution tasks.
- Publication calendar over content, social en email.
- Campaign measurement: analytics, connector observations, social engagement, SEO/GEO movement, pipeline/revenue where available.
- Learning loop: what worked, what to refresh, what to repeat.

Acceptatie:

- Een campagne kan van signal/opportunity naar assets, approvals, scheduled distribution en measurement.
- Campaign OS gebruikt dezelfde graph, brand, entity en action primitives, geen nieuw los ecosysteem.

## Fase 8: Executive Intelligence

Doel: management krijgt geen datadump, maar beslissingen.

Indicatie: na Fase 5-7.

Deliverables:

- Executive dashboard voor kansen, risico's, voortgang, content health, AI visibility, competitor pressure, campaign ROI en pipeline indicators.
- Weekly/monthly executive briefings.
- Board-ready exports: what changed, why it matters, what Argusly recommends, what is already in execution.
- KPI hierarchy: visibility, authority, engagement, conversion, pipeline/revenue, operational velocity.
- Scenario view: "wat gebeurt er als we deze 10 acties uitvoeren?"

Acceptatie:

- CMO/management ziet waar marketing moet ingrijpen, niet alleen welke metrics bewogen.
- Iedere executive insight linkt naar evidence en een action path.

## Eerste 30 dagen

Week 1:

- Unit test helper fix.
- Feature-flag matrix.
- Go-live checklist actualiseren.
- Queue/scheduler/monitoring plan.
- Docs reconciliatie voor SEO en connector status.
- Besluit: launch mode, eerste pilotproviders, eerste market pack, autonomie policy.

Week 2:

- Content Inventory UI ontwerp en eerste route/view op basis van bestaande `MonitoredPage` en `Content` data.
- Inventory filters/statussen definieren.
- Page drawer met score/evidence/metrics.
- Connector pilotplan kiezen: start met GSC of GA4, niet alle providers.

Week 3:

- Inventory actions: refresh, link existing content, activate as content, ignore.
- Page findings naar Recommended Actions MVP.
- Report email delivery technische implementatie starten.
- Public positioning copy plan: AI Visibility als signal layer binnen Agentic Marketing OS.

Week 4:

- Eerste pilot end-to-end: site inventory -> page finding -> recommended action -> draft/refresh plan.
- Operator dashboard voor queue/connector/report delivery basis.
- Product demo script: "from observation to action".
- Roadmap review: welke Fase 2/3 items zijn nu bewezen genoeg voor build sprint.

## Beslissingen die voor uitvoering nodig zijn

| Besluit | Opties | Aanbevolen keuze |
| --- | --- | --- |
| Positionering | AI Visibility tool, content platform, Marketing OS | Marketing OS; AI Visibility als signal layer. |
| Launch mode | Self-serve, invite/approval, internal beta | Controlled pilot met approval. |
| Eerste productwaarde | Social, Campaigns, Inventory, Executive dashboard | Content Inventory + Page Intelligence actions. |
| Eerste connectorpilot | GA4, GSC, LinkedIn, Ads, CRM | GSC of GA4, daarna Ads/CRM. |
| Autonomie | Manual only, guided, autonomous | Guided met human approval; autonomous per site/customer gated. |
| Eerste market pack | Automotive, telecom, generic B2B | Kies 1-2 waar demo/data het sterkst zijn. |
| Email marketing providers | DMT only, Mailchimp, Mailjet | UI beperken tot ondersteund of Mailchimp eerst implementeren. |
| Research layer | Intern, beta, premium | Beta/premium na graph en entity context. |

## Niet bouwen voordat de basis staat

- Geen volledig nieuw WebsiteContent domein naast `MonitoredPage` en `Content`.
- Geen autonome publicatie als default.
- Geen executive dashboard zonder action/evidence-koppeling.
- Geen grote Campaign OS rewrite voordat graph en action queue bestaan.
- Geen negen connectorproviders tegelijk in productie.
- Geen nieuwe AI features zonder cost, queue, credit en fallback observability.

## Succesmetrics

Launch/ops:

- 0 P0 checklist-items open.
- Unit + feature suite groen.
- Queue latency en failed jobs zichtbaar.
- Publish success rate en connector health zichtbaar.

Product:

- Tijd tot eerste inzicht: minder dan 10 minuten na site connect/discovery.
- Tijd tot eerste recommended action: minder dan 15 minuten na inventory scan.
- Minimaal 5 actionable finding types in Page/Content Inventory.
- Minimaal 1 end-to-end flow: observed page -> insight -> action -> draft/refresh -> scheduled/published.

Strategie:

- Public messaging noemt Argusly consequent Agentic Marketing Operating System.
- AI Visibility wordt gepositioneerd als signaalbron, niet als eindproduct.
- Iedere roadmap-feature past in de loop: observation, knowledge, insight, action, execution, measurement, learning.

## Conclusie

De juiste volgende stap is niet "meer bouwen omdat er nog veel kan", maar "het bestaande fundament omzetten in een zichtbaar, betrouwbaar operating system".

Daarom is de volgorde:

1. Bewijsbaarheid en launch hardening.
2. Content Inventory UI als eerste tastbare productwaarde.
3. Page Intelligence omzetten naar acties.
4. Marketing Memory/Graph als verbindende laag.
5. Brand en Entity Intelligence centraliseren.
6. Signal Intelligence 2.0.
7. Guided Agentic Marketing.
8. Campaign OS.
9. Executive Intelligence.

Zo groeit Argusly logisch van een breed gebouwd platform naar een onderscheidend Marketing Operating System.
