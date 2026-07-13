# Phase 1 Content Inventory status

Datum: 2026-07-12
Scope: eerste uitvoering van Phase 1, beperkt tot bestaande Content Inventory backend zichtbaar en bruikbaar maken. Geen parallel website-inventory domein.

## Analyse

De bestaande Page Intelligence / Website Content Inventory-laag bevat al de kern:

- `MonitoredPage` voor observed website pages.
- `Content` voor owned marketing assets.
- `ContentPageLink` als bridge.
- Fetch, extraction, eligibility, include/exclude en activate flows.
- UI-tab binnen Page Intelligence.

De concrete ontbrekende actie in de eerste MVP was: een observed page kunnen koppelen aan bestaand content zonder nieuw content te maken.

## Ontwerp

De implementatie gebruikt de bestaande bridge:

`MonitoredPage -> ContentPageLink -> Content`

De link-flow is toegevoegd aan `WebsiteContentActivationService`, zodat activatie en bestaande-content-koppeling dezelfde workspace/site regels, eligibility en idempotentie delen.

## Implementatieplan

1. Voeg service-methode toe voor bestaand content linken. Status: afgerond.
2. Voeg POST-route en controller action toe. Status: afgerond.
3. Toon link-control in Content Inventory voor eligible ongekoppelde pagina's. Status: afgerond.
4. Filter kandidaten per rij op dezelfde site of site-neutraal content. Status: afgerond.
5. Test idempotentie en UI-flow. Status: afgerond.

## Bestanden

Gewijzigd:

- `app/Services/WebsiteContentInventory/WebsiteContentActivationService.php`
- `app/Http/Controllers/App/AppMonitoredPageController.php`
- `routes/app.php`
- `resources/views/app/page-intelligence/partials/content-inventory-table.blade.php`
- `tests/Feature/WebsiteContentInventory/ContentInventoryUiTest.php`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`

Toegevoegd:

- `audit/phase_1_content_inventory_status_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Grote contentselectie kan onhandig worden bij veel assets | Open | Later vervangen door searchable picker of drawer action. |
| Alleen eerste 75 recente contentitems worden aangeboden | Bewust MVP | Voor pilots voldoende; later server-side search toevoegen. |
| Ineligible pages kunnen niet gelinkt worden via UI | Bewust conservatief | Alleen force/operator flow overwegen na productbesluit. |
| Refresh recommendation nog open | Open | Phase 2-kandidaat via `RecommendedAction`. |

## Tests

Uitgevoerd:

```bash
php -l app/Http/Controllers/App/AppMonitoredPageController.php
php -l app/Services/WebsiteContentInventory/WebsiteContentActivationService.php
php -l tests/Feature/WebsiteContentInventory/ContentInventoryUiTest.php
php -l routes/app.php
php artisan test tests/Feature/WebsiteContentInventory
php artisan test tests/Feature/WebsiteContentInventory/ContentInventoryUiTest.php
php artisan test tests/Feature/PageIntelligence/PageIntelligenceUiTest.php tests/Feature/PageIntelligence/MonitoredPageInteractionTest.php
```

Resultaat:

- Website Content Inventory-suite: 18 passed, 105 assertions.
- Content Inventory UI-test: 1 passed, 19 assertions.
- Omliggende Page Intelligence UI/interactie-tests: 10 passed, 61 assertions.
- Gecombineerde Phase 1/2/3-regressie: 26 passed, 140 assertions.

## Documentatie

De roadmap is bijgewerkt met de Phase 1-voortgang. Deze statusfile is de overdracht voor de volgende Phase 1-slice.

## Architectuurcontrole

- Geen `WebsiteContent`, `InventoryPage`, `ObservedContent` of ander parallel model toegevoegd.
- `ContentPageLink` blijft de bridge.
- `MonitoredPage` blijft observed external page.
- `Content` blijft owned marketing asset.
- Geen nieuwe queue, registry, workflow runner of permission layer toegevoegd.
