# Phase 2 Page Intelligence action layer status

Datum: 2026-07-12
Scope: eerste uitvoering van Phase 2, beperkt tot Page Intelligence alerts beter vertalen naar bestaande `RecommendedAction` records.

## Analyse

Page Intelligence kon al alerts fire-en, notifications maken en bij high/critical alerts een `RecommendedAction` aanmaken. De action was echter generiek: alle triggers kregen vrijwel dezelfde action anatomy.

Voor de roadmap is dat te vlak. Een negatieve reputatierisico-alert, competitor-pressure alert en PR-value opportunity moeten elk een andere aanbeveling, action category en expected outcome krijgen.

## Ontwerp

De bestaande flow blijft leidend:

`AlertRule -> PageAlert -> Notification -> RecommendedAction`

Er is geen nieuwe action queue of nieuw taakmodel toegevoegd. De verbetering zit in `PageAlertNotificationMapper`, die nu trigger-specifieke blueprints gebruikt.

## Implementatieplan

1. Houd `RecommendedAction` als executable task. Status: afgerond.
2. Voeg trigger-specifieke action blueprints toe. Status: afgerond.
3. Voeg evidence package, action category en recommended next step toe aan metadata. Status: afgerond.
4. Test opportunity en risk-response mappings. Status: afgerond.

## Bestanden

Gewijzigd:

- `app/Services/PageIntelligence/Alerts/PageAlertNotificationMapper.php`
- `tests/Feature/PageIntelligence/PageAlertingTest.php`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`

Toegevoegd:

- `audit/phase_2_page_intelligence_action_layer_status_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Alleen high/critical alerts maken actions | Bewust bestaand gedrag | Later bepalen of medium alerts met hoge business impact ook actions mogen maken. |
| Trigger-blueprints zijn deterministisch en niet market-pack specifiek | Open | Later thresholds/templates per market pack toevoegen. |
| Scheduled briefings tonen nog niet overal concrete next actions | Open | Report/briefing payloads koppelen aan de verrijkte action anatomy. |
| Email delivery voor Page Intelligence blijft placeholder | Open P1/P2 | Mailgun delivery met retry, opt-out en audit apart oppakken. |

## Tests

Uitgevoerd:

```bash
php -l app/Services/PageIntelligence/Alerts/PageAlertNotificationMapper.php
php -l tests/Feature/PageIntelligence/PageAlertingTest.php
php artisan test tests/Feature/PageIntelligence/PageAlertingTest.php
```

Resultaat:

- PageAlerting-test: 7 passed, 27 assertions.
- Gecombineerde Phase 1/2/3-regressie: 26 passed, 140 assertions.

## Documentatie

De roadmap is bijgewerkt met de eerste Phase 2-voortgang. Deze statusfile is de overdracht voor de volgende action-layer slice.

## Architectuurcontrole

- Geen parallel `RecommendedAction`-model of action queue toegevoegd.
- `PageAlert` blijft het Page Intelligence finding-record.
- `RecommendedAction` blijft de uitvoerbare taak.
- Evidence blijft aan de action gekoppeld via metadata en verwijzingen naar alert, rule, page, signal event en signal detection.
