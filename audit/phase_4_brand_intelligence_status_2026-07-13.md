# Phase 4 Brand Intelligence status

Datum: 2026-07-13
Scope: eerste uitvoering van Phase 4, beperkt tot centrale brand-context injectie voor recommendations en agentic workflows.

## Analyse

De codebase had al een sterke basis voor Brand Intelligence:

- `CompanyIntelligenceProfile` bevat company profile, ICP, persona, tone, positioning, proof points, forbidden phrases, topics, entities en competitors.
- `CompanyIntelligenceContextService` levert prompt-ready context voor actieve profielen.
- `BrandVoice` en `BrandContext` bestaan als aparte merkbronnen.
- Page Intelligence, Content Opportunity en Agentic Marketing gebruiken al delen van deze context, maar nog niet overal via dezelfde centrale snapshot.

Daarom was de juiste eerste stap geen nieuw brand-model, maar een compacte read-service die bestaande goedgekeurde bronnen samenvoegt.

## Ontwerp

De centrale context loopt nu via:

`CompanyIntelligenceProfile -> CompanyIntelligenceContextService -> BrandIntelligenceContextService -> Page Intelligence actions / Agentic shared context`

De snapshot bevat alleen compacte, actiegerichte context:

- company: naam, categorie, positioning, UVP, regio's, locales
- audience: ICP, persona's, buyer roles, pain points
- voice: tone, writing style, preferred/disallowed terminology, messaging rules
- proof: proof points en differentiators
- entities: topics, authority areas, target entities, keywords en competitors
- governance: source ids, status, default flag, completeness en payload hash

## Implementatieplan

1. Maak de bestaande Company Intelligence-profielselectie herbruikbaar. Status: afgerond.
2. Voeg een compacte `BrandIntelligenceContextService` toe. Status: afgerond.
3. Injecteer brand-context in Page Intelligence `RecommendedAction` metadata. Status: afgerond.
4. Laat Agentic Marketing shared context dezelfde snapshot gebruiken. Status: afgerond.
5. Test beschikbaarheid, metadata en backward-compatible `company` context. Status: afgerond.
6. Vervolg: dezelfde snapshot aansluiten op briefs, drafts, social posts en reports. Status: open.

## Bestanden

Toegevoegd:

- `app/Services/BrandIntelligence/BrandIntelligenceContextService.php`
- `tests/Feature/BrandIntelligence/BrandIntelligenceContextServiceTest.php`
- `audit/phase_4_brand_intelligence_status_2026-07-13.md`

Gewijzigd:

- `app/Services/CompanyIntelligence/CompanyIntelligenceContextService.php`
- `app/Services/PageIntelligence/Alerts/PageAlertNotificationMapper.php`
- `app/Services/AgenticMarketing/Orchestration/SharedMarketingContextBuilder.php`
- `tests/Feature/PageIntelligence/PageAlertingTest.php`
- `tests/Feature/AgenticMarketing/AgentOrchestrationLayerTest.php`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Brand Intelligence zit nog niet in alle generatieve functies | Open | Aansluiten op briefs, drafts, social variants, reports en scheduled briefings. |
| Entity Intelligence registry ontbreekt nog als expliciet domein | Open | Eerst identifiers normaliseren vanuit bestaande Page Intelligence entity extraction. |
| Snapshot kan te groot worden als payloads groeien | Beheerst | Service limiteert lijsten en bewaart alleen actiegerichte context. |
| Governance is nog profielstatus-gebaseerd | Deels | Later approval/audit state toevoegen voor entity-links en brand revisions. |

## Tests

Uitgevoerd:

```bash
php -l app/Services/BrandIntelligence/BrandIntelligenceContextService.php
php -l app/Services/CompanyIntelligence/CompanyIntelligenceContextService.php
php -l app/Services/PageIntelligence/Alerts/PageAlertNotificationMapper.php
php -l app/Services/AgenticMarketing/Orchestration/SharedMarketingContextBuilder.php
php artisan test tests/Feature/BrandIntelligence/BrandIntelligenceContextServiceTest.php tests/Feature/PageIntelligence/PageAlertingTest.php tests/Feature/CompanyIntelligence/CompanyIntelligenceProfileTest.php tests/Feature/AgenticMarketing/AgentOrchestrationLayerTest.php
```

Resultaat:

- Syntaxcontrole: groen.
- Gerichte Phase 4-regressie: 20 tests, 123 assertions.

## Documentatie

De roadmap is bijgewerkt met de eerste Phase 4-voortgang.

## Architectuurcontrole

- Geen nieuw brand source-of-truth model toegevoegd.
- `CompanyIntelligenceProfile` blijft de canonical approved company/brand source.
- `BrandVoice` en `BrandContext` worden als aanvullende bronnen gelezen, niet gedupliceerd.
- Page Intelligence actions en Agentic Marketing workflows delen nu dezelfde `brand_intelligence.snapshot.v1`.
