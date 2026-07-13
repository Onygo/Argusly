# Phase 3 Marketing Memory status

Datum: 2026-07-12
Scope: eerste uitvoering van Phase 3, beperkt tot een additive read-model voor Content Inventory graph context.

## Analyse

Er bestaan al intelligence graph primitives:

- `IntelligenceGraphProjector`
- `IntelligenceGraphNode`
- `IntelligenceGraphEdge`
- `IntelligenceGraphReference`

Daarom is de juiste eerste stap geen nieuwe graph database en geen nieuw domeinmodel, maar een service die bestaande relaties projecteert naar deze interfaces.

## Ontwerp

De eerste graph-context loopt via:

`Content -> ContentPageLink -> MonitoredPage -> PageAlert -> RecommendedAction`

Dit verbindt owned content assets, observed page evidence, Page Intelligence findings en executable actions.

## Implementatieplan

1. Gebruik bestaande graph interfaces. Status: afgerond.
2. Projecteer `Content` en gelinkte `MonitoredPage` nodes. Status: afgerond.
3. Projecteer `ContentPageLink` als `derives_from` edge. Status: afgerond.
4. Projecteer Page Alert evidence en bijbehorende actions. Status: afgerond.
5. Toon Marketing Memory context in de Page Intelligence drawer. Status: afgerond.
6. Test graph output zonder bronmodellen te wijzigen. Status: afgerond.

## Bestanden

Toegevoegd:

- `app/Support/Intelligence/InMemoryIntelligenceGraphProjector.php`
- `app/Services/MarketingMemory/ContentInventoryGraphContextBuilder.php`
- `tests/Feature/MarketingMemory/ContentInventoryGraphContextBuilderTest.php`
- `audit/phase_3_marketing_memory_status_2026-07-12.md`

Gewijzigd:

- `app/Support/Interaction/MonitoredPageMetadataProvider.php`
- `tests/Feature/PageIntelligence/MonitoredPageInteractionTest.php`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Graph-context is zichtbaar in de Page Intelligence drawer | Deels afgerond | Later ook tonen op content detail en action detail. |
| Alleen Content Inventory-relaties worden geprojecteerd | Bewust MVP | Later uitbreiden met campaigns, social, publications, topics, entities en metrics. |
| Geen persistent graph storage | Bewust | Eerst read-model bewijzen; opslag pas toevoegen als performance of productvraag dat vereist. |
| Entity normalisatie nog open | Open | Phase 4/5 afhankelijk van Brand/Entity Intelligence. |

## Tests

Uitgevoerd:

```bash
php -l app/Services/MarketingMemory/ContentInventoryGraphContextBuilder.php
php -l app/Support/Intelligence/InMemoryIntelligenceGraphProjector.php
php -l app/Support/Interaction/MonitoredPageMetadataProvider.php
php -l tests/Feature/MarketingMemory/ContentInventoryGraphContextBuilderTest.php
php artisan test tests/Feature/MarketingMemory/ContentInventoryGraphContextBuilderTest.php
php artisan test tests/Feature/PageIntelligence/MonitoredPageInteractionTest.php tests/Feature/MarketingMemory/ContentInventoryGraphContextBuilderTest.php
php artisan test tests/Feature/PageIntelligence/PageIntelligenceUiTest.php
php artisan test tests/Feature/WebsiteContentInventory tests/Feature/PageIntelligence/PageAlertingTest.php tests/Feature/MarketingMemory/ContentInventoryGraphContextBuilderTest.php tests/Feature/PageIntelligence/MonitoredPageInteractionTest.php tests/Feature/PageIntelligence/PageIntelligenceUiTest.php
```

Resultaat:

- Marketing Memory graph context test: 1 passed, 8 assertions.
- Page Intelligence drawer + graph context tests: 5 passed, 50 assertions.
- Page Intelligence UI regression: 6 passed, 23 assertions.
- Full targeted Phase 1/2/3 UI/action/memory regression: 36 passed, 205 assertions.
- Gecombineerde Phase 1/2/3-regressie: 26 passed, 140 assertions.

## Documentatie

De roadmap is bijgewerkt met de eerste Phase 3-voortgang.

## Architectuurcontrole

- Geen parallelle `Content`, `MonitoredPage`, `PageAlert` of `RecommendedAction` modellen toegevoegd.
- Geen nieuwe graph database toegevoegd.
- Geen bron-domeinen herschreven.
- De service is additive en projecteert bestaande relaties naar bestaande intelligence graph primitives.
