# FAQ Intelligence Engine

Datum: 2026-06-19

## Capability

FAQ Intelligence Engine is een kernonderdeel van Argusly voor Detect -> Prioritize -> Create -> Publish -> Measure. De engine detecteert ontbrekende buyer questions, prioriteert impact voor AI Visibility, Semantic SEO en conversie, genereert productieklare FAQ's, publiceert ze vanuit een centrale knowledge base en meet impact via audit snapshots.

## Wat nu werkt

- Centrale FAQ Knowledge Base via `faq_questions`.
- Herbruikbare page assignments via `faq_page_assignments`.
- Audit snapshots via `faq_opportunity_audits`.
- Workflowstatussen: `pending`, `analyzing`, `generated`, `review_required`, `published`, `failed`.
- Deterministische opportunity engine met coverage, opportunity, AI Visibility, SEO en conversion scores.
- Productieklare FAQ-generatie met intent, funnel stage, CTA en interne linkadvies.
- FAQPage JSON-LD generatie en validatie.
- Public rendering via `<x-public.faq-section />`.
- Admin dashboard, analyseflow, acceptatieflow, publishflow en unlinkflow.
- CMS-tabpartial voor marketingpage beheer, nu gekoppeld aan pricing page management.
- Background jobs voor analyse, opportunitygeneratie, schema-validatie en coverage-herberekening.
- Duplicate-risk detectie voor exacte duplicaten, semantisch vergelijkbare vragen, intent-overlap en verkeerde type assignment.

## Geintegreerde Templates

De centrale FAQ Knowledge Base rendert nu automatisch op:

- Homepage: `page_type=homepage`, `page_slug=landing`.
- Solution pages: `page_type=solution`, `page_slug={solutionKey}`.
- Market pages: `page_type=market`, `page_slug={marketKey}`.
- Product/platform pages: `page_type=platform`, `page_slug=product.overview|product.platform`.
- Pricing: `page_type=pricing`, `page_slug=pricing`.
- Contact: `page_type=contact`, `page_slug=company.contact`.
- Security: `page_type=security`, `page_slug=legal.security`.
- Marketing topic pages: `page_type=resource`, `page_slug={page key}`.

Statische FAQ's op pricing en marketing topic pages blijven fallback-content wanneer er nog geen centrale gepubliceerde FAQ's gekoppeld zijn. Daarmee ontstaat geen tweede FAQ-systeem en geen dubbele FAQPage schema-output.

## Database Design

### faq_questions

Centrale FAQ Knowledge Base. FAQ's zijn niet exclusief aan pagina's gekoppeld.

Belangrijke velden:
- `question`
- `answer`
- `language`
- `faq_type`
- `search_intent`
- `funnel_stage`
- `priority`
- `seo_score`
- `ai_visibility_score`
- `conversion_score`
- `is_global`
- `status`
- `internal_links`
- `recommended_cta`
- `source_context`
- `created_by`
- `updated_by`

### faq_page_assignments

Een FAQ kan aan meerdere pagina's hangen met eigen prioriteit.

Belangrijke velden:
- `faq_id`
- `page_type`
- `page_slug`
- `locale`
- `weight`

Public rendering sorteert op page `weight` en FAQ `priority`, en toont alleen `published` FAQ's in de gevraagde locale.

### faq_opportunity_audits

Meetbare audit output voor dashboard, CMS-tab en workflow jobs.

Belangrijke velden:
- `page_type`
- `page_slug`
- `locale`
- `page_title`
- `sector`
- `solution_type`
- `status`
- `faq_coverage_score`
- `faq_opportunity_score`
- `ai_visibility_impact_score`
- `seo_impact_score`
- `conversion_impact_score`
- `score_rationale`
- `missing_questions`
- `generated_faqs`
- `suggested_internal_links`
- `suggested_ctas`
- `error_message`
- `completed_at`

## Services en Classes

### FaqOpportunityService

- Analyseert pagina input.
- Controleert bestaande FAQ's op pagina- en siteniveau.
- Detecteert ontbrekende buyer, AI Visibility, ROI, implementatie, governance, comparison, competitive en vertical questions.
- Berekent scores met rationale.
- Genereert productieklare FAQ candidates.
- Publiceert geaccepteerde FAQ's naar `faq_questions`.
- Maakt page assignments aan.

### FaqSchemaService

- Genereert FAQPage JSON-LD vanuit actieve FAQ's.
- Valideert `FAQPage`, `Question`, `name` en `acceptedAnswer.text`.

### FaqIntelligenceRenderer

- Haalt gepubliceerde FAQ's op voor public templates.
- Levert `items` en `schema` voor Blade rendering.

### FaqDuplicateDetectionService

- Detecteert exacte dubbele vragen.
- Detecteert semantisch vergelijkbare vragen met deterministische normalisatie.
- Detecteert overlappende intent/funnel combinaties.
- Signaleert verkeerde FAQ type assignments.
- Adviseert `hergebruiken`, `herschrijven`, `verplaatsen` of `verwijderen`.

## Public Rendering en Schema

Gebruik:

```blade
<x-public.faq-section
    page-type="solution"
    :page-slug="$solutionKey"
    :locale="app()->getLocale()"
/>
```

Optioneel:

```blade
<x-public.faq-section
    :items="$faq['items']"
    :schema="$faq['schema']"
    heading="Veelgestelde vragen"
    intro="Antwoorden op evaluatievragen rond AI Visibility, implementatie en ROI."
/>
```

Het component:
- haalt automatisch FAQ's op via `page_type`, `page_slug` en `locale`;
- blijft leeg als er geen actieve FAQ's zijn;
- toont alleen gepubliceerde FAQ's;
- ondersteunt NL en EN;
- injecteert FAQPage JSON-LD wanneer er actieve FAQ's zijn;
- voorkomt dubbele schema-output door static fallback FAQ's te onderdrukken wanneer centrale FAQ's aanwezig zijn.

Bestaande Organization, WebSite, SoftwareApplication en Breadcrumb schema blijven in hun huidige partials/views staan. FAQPage schema wordt aanvullend gerenderd vanuit de FAQ Knowledge Base.

## Admin en CMS Flow

Admin routes:
- `GET /faq-intelligence`
- `POST /faq-intelligence/analyze`
- `POST /faq-intelligence/publish`
- `POST /faq-intelligence/accept`
- `PATCH /faq-intelligence/questions/{faqQuestion}`
- `POST /faq-intelligence/questions/{faqQuestion}/publish`
- `DELETE /faq-intelligence/assignments/{assignment}`

Workflow:
1. Admin analyseert een pagina.
2. Engine detecteert ontbrekende vragen.
3. Engine prioriteert kansen en genereert FAQ-voorstellen.
4. Admin accepteert een voorstel of publiceert alle gegenereerde FAQ's.
5. FAQ's worden centraal opgeslagen en via assignment aan de pagina gekoppeld.
6. Public templates renderen FAQ en FAQPage schema automatisch.
7. Dashboard toont coverage, opportunity, impact en duplicate risks.

CMS-tab:
- Partial: `resources/views/admin/faq-intelligence/_cms-tab.blade.php`.
- Eerste integratie: pricing page management.
- Toont scores, gekoppelde FAQ's, ontbrekende vragen en concept FAQ's.
- Bevat actie om de pagina opnieuw te analyseren.

## Autonomous Jobs

Beschikbare jobs:
- `AnalyzeFaqPageJob`
- `GenerateFaqOpportunitiesJob`
- `ValidateFaqSchemaJob`
- `RecalculateFaqCoverageScoresJob`

Jobs zetten auditstatussen en loggen fouten via `report($exception)` zonder de gebruiker te blokkeren. Queue workers kunnen standaard via Laravel queue draaien.

## Dashboard

Het dashboard toont nu:
- totaal FAQ's;
- gepubliceerde FAQ's;
- gemiddelde AI Visibility, SEO en Conversion scores;
- coverage per page type;
- coverage per market;
- coverage per solution;
- top pages without FAQ coverage;
- top FAQ opportunities;
- top duplicate risks;
- latest audits.

Filters:
- locale;
- page type;
- status;
- score range;
- market;
- solution.

## Tests

Unit:
- `tests/Unit/Faq/FaqOpportunityServiceTest.php`
- `tests/Unit/Faq/FaqSchemaServiceTest.php`
- `tests/Unit/Faq/FaqDuplicateDetectionServiceTest.php`

Feature:
- `tests/Feature/Admin/FaqIntelligenceAdminTest.php`
- `tests/Feature/Public/FaqIntelligenceRenderingTest.php`
- `tests/Feature/Faq/FaqWorkflowJobTest.php`

Dekking:
- FAQ rendering op publieke pagina's.
- FAQPage JSON-LD output.
- Geen schema output zonder actieve FAQ's.
- NL en EN rendering.
- CMS/admin analyseflow.
- Acceptatie van gegenereerde FAQ.
- Publicatie en ontkoppeling.
- Background job flow.
- Dashboard breakdowns.
- Duplicate detectie.
- JSON-LD voor solution, market, pricing en contact assignments.

## Verificatie

Uitgevoerde verificatie:

```bash
php artisan test tests/Unit/Faq tests/Feature/Admin/FaqIntelligenceAdminTest.php tests/Feature/Public/FaqIntelligenceRenderingTest.php tests/Feature/Faq/FaqWorkflowJobTest.php
php artisan route:list --name=faq-intelligence
php artisan view:cache
```

Resultaat:
- 16 tests geslaagd.
- 7 FAQ Intelligence admin routes geregistreerd.
- Blade templates cachen succesvol.

## Open Vervolgfases

- CMS-tab uitbreiden naar alle marketing page beheerinterfaces zodra die per page type aparte admin-schermen hebben.
- Impact measurement koppelen aan analytics, Search Console en AI Visibility tracking snapshots.
- Engagement events op FAQ open/click meten.
- LLM-provider adapter toevoegen als kwaliteitslaag boven de deterministische generator, met dezelfde validatie en review gates.
- Duplicate detectie verrijken met embeddings wanneer infrastructuur daarvoor beschikbaar is.
- Blog template migratie alleen uitvoeren wanneer blog FAQ's naar de centrale marketing knowledge base mogen verhuizen.

## Notes

`StructuredAnswerBlock` blijft bewust gescheiden. Bestaande answer blocks blijven content/article-specifiek. `FaqQuestion` is de centrale marketing FAQ Knowledge Base die over pagina's heen herbruikbaar is.
