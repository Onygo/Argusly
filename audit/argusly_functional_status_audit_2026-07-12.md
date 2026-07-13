# Argusly functional status audit

Datum: 2026-07-12
Scope: lokale code-audit van de repository, route-inventaris, configuratie, documentatie, scheduler, build en testresultaten.

Deze audit is bedoeld als overdraagbaar overzicht voor een ander systeem. Het is geen productie-audit, pentest, loadtest of live integratietest met echte externe accounts.

## Statuslabels

- Actief: code, routes en tests wijzen op een bruikbare functie.
- Gebouwd, gated: aanwezig in code, maar standaard achter feature flag, beta-flow of expliciete operator gate.
- Operationeel afhankelijk: functie bestaat, maar heeft productieconfiguratie, secrets, queue workers, storage links of externe provider-accounts nodig.
- Backlog/placeholder: expliciet TODO, skipped test, not implemented-pad of documentatie die nog werk benoemt.
- Niet bewezen: niet lokaal te bewijzen zonder productieomgeving, echte providers of deployment-infrastructuur.

## Executive summary

Argusly is inmiddels een brede Laravel 12 SaaS met gescheiden public/app/admin/api/track domeinen, een grote client-app, admin-operaties, connector-API, billing, content lifecycle, agentic marketing, page intelligence, signal intelligence, social publishing, research, SEO audits en managed publishing.

De functionele dekking is sterk: de feature-suite is groen met 3639 geslaagde tests en 3 skips. Build en Composer-validatie slagen. De unit-suite is in Phase 0 gerepareerd door `makeDraftIntelligenceContext()` naar gedeelde test support te verplaatsen; de unit-suite slaagt nu met 880 tests en 4747 assertions.

De belangrijkste bouw- en launchrestpunten zijn:

- Productie-readiness moet apart worden afgevinkt: go-live checklist staat nog op Pre-Launch en veel infra/secrets/queue/backup/monitoring items zijn niet bewezen.
- Page Intelligence report delivery heeft in-app delivery, maar email delivery is expliciet een placeholder.
- Network linking staat expliciet uit met TODO om opnieuw te activeren.
- Mailchimp/Mailjet email marketing providers zijn nog niet geimplementeerd.
- Connector packages lijken functioneel breed getest, maar documentatie noemt nog release- en placeholder-vervanging als planned work.
- Feature flags rond research, brief intelligence/templates, content network analysis, link intelligence jobs en MOS canonical migraties vragen productbesluit: aanzetten, beta houden of later bouwen.
- Documentatie moet worden opgeschoond: een oude SEO-audit noemt RankMath/AIOSEO nog stubbed, terwijl huidige tests wijzen op sync-capability/mapping voor die velden.

## Verificatie

| Controle | Resultaat | Betekenis |
| --- | --- | --- |
| `composer validate` | Geslaagd | `composer.json` is valide. |
| `npm run build` | Geslaagd | Vite build werkt. NPM waarschuwt lokaal over onbekende user config `python`. |
| `php artisan about` | Geslaagd | Laravel 12.63.0 / PHP 8.3.14 lokaal. Let op: lokale env heeft `APP_DEBUG=true`, Sentry DSN ontbreekt, `public/storage` is gelinkt, `public/content-images` niet. |
| `php artisan schedule:list` | Geslaagd na sandbox-escalatie | Scheduler is rijk gevuld en operationeel afhankelijk van database/cache locks en queue workers. |
| `php artisan test --testsuite=Feature` | Geslaagd | 3639 passed, 3 skipped, 19831 assertions, 489.76s. |
| `php artisan test --testsuite=Unit` | Geslaagd na Phase 0 update | 880 passed, 4747 assertions. De ontbrekende helper is verplaatst naar gedeelde test support. |

## Phase 0 update

Op 2026-07-12 is het eerste launch-hardening werk gestart:

- `makeDraftIntelligenceContext()` is verplaatst naar gedeelde test support.
- Unit-verificatie is groen: `php artisan test --testsuite=Unit` geeft 880 passed.
- De roadmap heeft een duurzame architectuurcontrole gekregen in `docs/architecture/agentic-marketing-os-execution-guardrails.md`.
- De resterende Phase 0-focus is productie-readiness: omgeving, secrets, queues, monitoring, feature flags, provider smoke tests en stale documentatie.
| `npm run build` git status | Schoon | Build wijzigde geen tracked assets. |

## Inventaris

| Onderdeel | Aantal |
| --- | ---: |
| Controllers | 181 |
| Models | 332 |
| Services | 876 |
| Actions | 22 |
| Jobs | 107 |
| Artisan commands | 185 |
| Migrations | 411 |
| Feature tests | 481 |
| Unit tests | 162 |
| App views | 195 |
| Public views | 43 |
| Admin views | 64 |
| Config files | 59 |
| Docs | 111 |
| Audit docs | 17 |

## Route-inventaris

Totaal geregistreerde routes: 1673, inclusief lokale/test/compatibiliteitsroutes en vendor/debug routes.

| Segment | Routes | Status |
| --- | ---: | --- |
| App domain `app.argusly.local` | 624 | Actief |
| Marketing/public | 179 | Actief |
| Admin domain `admin.argusly.local` | 177 | Actief |
| API domain `api.argusly.local` | 93 | Actief |
| Compat API path routes | 93 | Compatibiliteit |
| Track domain `track.argusly.local` | 4 | Actief |
| Test/legacy/path routes | 496 | Lokaal, test of optioneel legacy |

Architectuur in `bootstrap/app.php`:

- `argusly.local`: public marketing.
- `app.argusly.local`: client app.
- `admin.argusly.local`: platform/admin.
- `api.argusly.local`: API.
- `track.argusly.local`: analytics script en events.
- `/api` compat routes blijven beschikbaar op niet-API domeinen.
- Legacy path routes zijn optioneel via `ARGUSLY_LEGACY_PATH_ROUTES_ENABLED`.

## Feature flags

Default aan:

- `agentic_marketing`
- `signal_intelligence`

Default uit:

- `network_linking`
- `draft_link_suggestions`
- `link_intelligence_jobs`
- `research_layer`
- `brief_intelligence`
- `brief_templates`
- `content_network_analysis`
- MOS/canonical overgangs- en experimentflags

Interpretatie: het platform bevat veel code die bewust gated is. Een volgend systeem moet dit niet automatisch als "ontbrekend" classificeren, maar als "gebouwd, nog product/ops-besluit nodig".

## Functionele domeinen

| Domein | Bewijs | Status | Wat kan nog gebouwd of besloten worden |
| --- | --- | --- | --- |
| Public marketing | Lokale EN/NL routes, pricing, product pages, markets, solutions, company, contact, early access, legal, blog, RSS, sitemap, robots, llms.txt/full, redirects. Feature tests groen. | Actief | Public roadmap actualiseren op basis van huidige status. |
| Public blog/managed publishing | Local/connector-synchronized blog source, published snapshots, cache invalidation, localized slugs, hreflang, OG/featured image, RSS. | Actief | Live public content source configuratie en cache/SEO smoke in productie. |
| Auth, onboarding en tenant lifecycle | Registratie, approval flow, early access invites, pending/hold users, team invites, workspace/org isolation. | Actief | Productkeuze rond self-serve vs invite/approval mode. |
| Client app shell/UI | Dashboard, settings hub, action/resource registries, drawers, data tables, wide content layouts, localization. | Actief | Eventuele visuele regressie/UX audit met browser screenshots. |
| Admin platform | Admin auth, dashboard, support mode, organizations, users, approvals, queues, webhooks, LLM monitor, content quality, feature flags, billing, plugin releases, product updates. | Actief | Productie-operator runbooks en alerting koppelen. |
| Sites en connector setup | WordPress/Laravel site setup, hashed keys, heartbeat, activity checks, plugin licensing/update/download endpoints, site-token auth. | Actief, operationeel afhankelijk | Package releaseproces en echte WP/Laravel install QA afronden. |
| Connector API | Integration-token API voor briefs, articles, campaigns, drafts, analytics, events, images, webhooks, provenance, audit, SEO, connector content index/sync. | Actief | Contractversies en backward compatibility beleid vastleggen. |
| Content lifecycle | Content index/detail, filters, versions, status separation, deletion, images, OG/featured, translations, family grouping, series, batches, calendar. | Actief | Skipped status test rond scheduled content herbeoordelen. |
| Briefs en draft generation | Brief authoring, URL/source generation, async jobs, draft generation, draft compare, hybrid draft, intelligence, human score, writer/brand context. | Actief | Gedeelde test support is toegevoegd; volgende focus is productie smoke voor LLM- en delivery-paden. |
| Publishing/delivery | WordPress, Laravel, generic publication jobs, scheduling, retries, verification, canonical publication records, webhook emission, remote status handling. | Actief | Live connector smoke tests en failure drills. |
| Automations | Scheduled publish dispatch, content automations, queue-based generation/delivery flows. | Actief | Productie queue monitoring en idempotency dashboards. |
| Billing/credits | Mollie checkout/subscriptions/webhooks, invoices/PDF, credits, reservations, dunning, resets/expiry, credit usage gates. | Actief, operationeel afhankelijk | Mollie live webhook verification, finance reconciliation, alerting. |
| Analytics/tracking | Track subdomain, tracking script, event ingest, rollups, verification flow, learnings metrics, website inventory from observed pages. | Actief | Production traffic validation and privacy/legal signoff. |
| Page Intelligence | Monitored sources, RSS/sitemap/manual discovery, fetch/snapshots/extraction, SERP/GEO observations, scores v1/v2, alerts, market packs, reports/PDF, scheduled briefings. | Actief | Email report delivery implementeren; scheduled delivery SLA bewaken. |
| Scheduled briefings | UI schedule creation/toggle, due-job dispatch, idempotency, in-app notifications, tenant safety, failure metadata. | Actief met email-placeholder | Email kanaal afbouwen naar echte delivery. |
| Signal Intelligence | Schema, ingestion, LLM/competitor adapters, deterministic detection, UI, filters, lifecycle, promotion naar opportunity signals. | Actief | Externe bronnen en alerting policy per markt valideren. |
| Agentic Marketing | Opportunity detection, planning, approval inbox, action execution, autonomy policy, governance, recommended actions, growth autopilot. | Actief | Autonomie-niveaus en risk controls per klant/productplan vastleggen. |
| Programmatic growth | Cluster planning, blueprint review, draft request/generation/review, content conversion, readiness, publication plans/scheduling. | Actief/beta | Controlled beta criteria, limits en rollout playbook bepalen. |
| MOS/canonical opportunity architecture | Canonical bridges, lifecycle sync diagnostics, rollout guards, runtime switch contracts, evidence packages, activation handoff. | Gebouwd, gated | Besluiten wanneer canonical runtime/migration flags aan mogen. |
| Research layer | Projects, source normalization/fetch/extract, LLM findings, summary, billing reservations, UI metadata. | Gebouwd, gated | Feature flag, entitlement en provider-cost model bepalen. |
| Link Intelligence | Editorial suggestions, cross-domain permission, apply/reject, rate limits, rejected management. | Gebouwd, deels gated | `link_intelligence_jobs` en draft link suggestions activeren of faseren. |
| Network linking | Routes en UI hebben expliciete TODO om opnieuw te activeren. | Backlog/placeholder | Product/spec/QA nodig voor heractivatie. |
| Content network analysis | Routes aanwezig achter feature flag. | Gebouwd, gated | Beta criteria en UX-integratie bepalen. |
| SEO audits/indexation | SEO crawler, audit detail dashboard, AI SEO fixes, indexation health, redirect/canonical repairs, provider capability UI. | Actief | Oude SEO-docs reconciliatie; live crawl/load limits valideren. |
| Social distribution | LinkedIn OAuth/share/publishing/scheduling, Instagram OAuth business/creator MVP, campaign variants, media fallback handling. | Actief/MVP, operationeel afhankelijk | LinkedIn/Meta production app review, permissions en media edge cases. |
| Email marketing | Email marketing API/routes bestaan; registry meldt Mailchimp/Mailjet niet geimplementeerd. | Deels gebouwd | Providers buiten DMT implementeren of uit scope halen. |
| LLM providers | Anthropic, Gemini, Mistral tests, fallback/logging, request pricing, generation flows. | Actief, operationeel afhankelijk | Live keys, quota, fallback en cost monitoring. |
| Images | Image presets, content images, generated/featured/OG image handling, WebP/storage concerns. | Actief, operationeel afhankelijk | `public/content-images` storage link en providerconfig in productie. |
| Legal/compliance public pages | Privacy, terms, DPA, cookies, security, subprocessors, realistic posture tests. | Actief | Juridische review voor productie/klantcontracten. |
| Security hardening | Login throttling, suspicious path/query blocking/log-only mode, compact abuse responses, heavy limiter. | Actief | Pentest/security review blijft apart nodig. |
| Scheduler/queues | 39 scheduled tasks voor briefs, drafts, deliveries, publications, social, automations, analytics, billing, LLM tracking, connector health, page intelligence. | Gebouwd, operationeel afhankelijk | Supervisor/systemd/Horizon/worker topology, failed job alerts en queue depth monitoring. |

## Belangrijkste backlog en risico's

| Prioriteit | Item | Bewijs | Aanbevolen actie |
| --- | --- | --- | --- |
| P0 opgelost | Unit-suite faalde op 3 tests door ontbrekende helper. | `tests/Support/DraftIntelligenceTestHelpers.php` wordt nu geladen via `tests/Pest.php`. | Geen open P0-codewerk; unit-suite blijft onderdeel van launch gate. |
| P0 | Productie-readiness niet bewezen. | `audit/go_live_checklist.md` staat op Pre-Launch met veel open items. | Gebruik checklist als launch gate: env, secrets, queues, storage, backups, monitoring, smoke tests. |
| P1 | Page Intelligence email delivery ontbreekt. | `PageIntelligenceReportDeliveryService` registreert `email_delivery_not_implemented`. | Bouw Mailgun/notification delivery inclusief retries, opt-out en audit logs. |
| P1 | Network linking is expliciet uitgeschakeld. | `routes/app.php`, `routes/app-legacy.php`, content show view bevatten TODO. | Specificatie/QA afronden en feature flag heractiveren. |
| P1 | Mailchimp/Mailjet provider adapters ontbreken. | `EmailMarketingProviderRegistry` gooit `not implemented yet`. | Implementeren of productmatig verwijderen/verbergen. |
| P1 | Connector packaging/release docs noemen nog planned work. | `docs/connectors.md` noemt placeholder command/endpoint vervanging en release candidate stappen. | Documentatie met huidige teststatus reconcilieren en echte package QA uitvoeren. |
| P1 | Externe provider-integraties niet live bewezen. | Lokale tests mocken veel externe calls. | Per provider een production-like smoke suite maken: Mollie, Mailgun, LLMs, WordPress, Laravel connector, LinkedIn, Meta/Instagram, reCAPTCHA, Sentry. |
| P2 | Oude SEO-architectuurdoc lijkt stale. | `docs/seo-architecture-audit-report.md` noemt RankMath/AIOSEO stubbed, huidige feature tests tonen sync-capability states. | Doc updaten of markeren als historisch. |
| P2 | Feature flags vragen roadmap-besluit. | `config/features.php` default-uit flags voor research, brief intelligence/templates, content network, link jobs, MOS. | Matrix maken: GA, beta, intern, deprecate. |
| P2 | Skipped tests moeten triage krijgen. | Feature-suite heeft 3 skipped tests. Expliciete skips staan rond WordPressConnector HTTP-verificatie, content status mapping en cross-locale auth context. | Per skip eigenaar en besluit vastleggen: externe vereiste, oude TODO of alsnog activeren. |
| P2 | Lokale `php artisan about` toont missing Sentry DSN en `content-images` link. | Lokale status, niet productie. | In productie expliciet verifieren via deploy checklist. |

## Scheduler samenvatting

De scheduler bevat onder meer:

- Elke minuut: brief processing, draft generation/delivery, delivery dispatch, queue heartbeat, scheduled content publish, scheduled social publication, content automations.
- Elke paar minuten: connector sync/health/recovery, page intelligence scheduled briefings, stale generation cleanup, billing diagnostics, reservation/access override maintenance.
- Hourly/daily: credit reset/expiry, dunning, LLM tracking, analytics rollups, website inventory, SEO/canonical/sitemap/indexation jobs.

Interpretatie: het systeem verwacht een serieuze worker/scheduler setup. Zonder productie queue workers lijkt de applicatie wel te laden, maar veel kernfunctionaliteit blijft stil.

## Wat waarschijnlijk nog gebouwd gaat worden

1. Launch hardening: productie-env, storage links, queue topology, backups, monitoring, Sentry, failed job alerts en runbooks. De unit-suite is inmiddels groen; de open launch-gates zitten vooral in productie-operatie.
2. Report delivery: echte email delivery voor Page Intelligence briefings en rapporten.
3. Network/content linking: network linking heractiveren, draft link suggestions en background jobs productiseren.
4. Email marketing: Mailchimp/Mailjet adapters of de UI/API beperken tot ondersteunde providers.
5. Connector release: WordPress/Laravel packages versioneren, release candidates testen, docs/installatieflow afronden.
6. Feature-flag roadmap: research, brief intelligence/templates, content network analysis en MOS canonical runtime gefaseerd naar beta/GA brengen.
7. External integration QA: echte smoke tests met provider accounts en sandbox/live-webhooks.
8. Documentation cleanup: go-live checklist actualiseren, SEO audit doc reconcilieren, connector docs laten aansluiten op huidige implementatie.
9. Visual/UX QA: browser/screenshot regressies voor app/admin/public, zeker na de brede shell/data-table/drawer migraties.
10. Operational analytics: dashboards voor LLM costs, credit reservations, queue depth, connector health, report delivery, publish success rate en social publish attempts.

## Inputvragen voor een volgend systeem

Gebruik deze vragen om op basis van de audit een roadmap te maken:

- Welke functies moeten bij launch zichtbaar zijn, en welke blijven beta/intern?
- Is Argusly eerst een content publishing platform, een intelligence platform, of een agentic marketing platform in de positionering?
- Welke providerintegraties zijn P0 voor eerste klanten: WordPress, Laravel connector, Mollie, Mailgun, LLMs, LinkedIn, Instagram?
- Moet self-serve registratie live, of blijft early access/approval de primaire onboarding?
- Wordt research layer onderdeel van het basisproduct of een premium module?
- Wanneer mag agentic/autonomous publishing verder dan guided/manual approvals?
- Welke operational metrics zijn launch gates: publish success, queue latency, credit accuracy, LLM cost, connector health, report delivery?

## Conclusie

De codebase is functioneel veel verder dan een prototype. Er is een brede, geteste productbasis met sterke coverage op public, app, admin, billing, content, publishing, intelligence, connectors en social flows.

De resterende onzekerheid zit vooral in productie-operatie, externe integraties, enkele bewust gated modules en een paar expliciete placeholders. Voor een ander systeem is de beste vervolgstap: behandel dit als een bijna-productie platform met launch hardening en scopebeslissingen, niet als een blanco bouwproject.
