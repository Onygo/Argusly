# Phase 5 Signal Intelligence status

Datum: 2026-07-13
Scope: eerste uitvoering van Phase 5, beperkt tot impactanalyse, evidence packages en actie-preview rond `SignalDetection` promotie.

## Analyse

Signal Intelligence had al een sterke basis:

- `SignalEvent`, `SignalDetection`, `SignalScore`, `SignalProcessingRun` en lifecycle-statussen.
- Deterministische detection services voor brand monitoring, competitor monitoring, trend detection en risk detection.
- Een bestaande promotieroute van `SignalDetection` naar `OpportunitySignal`.
- UI voor review, dismiss, resolve en promote.

De grootste eerste productgap zat niet in opslag of detectie, maar in explainability: de gebruiker moest nog te veel zelf afleiden wat een signaal betekent en welke actie logisch is.

## Ontwerp

De eerste Signal Intelligence 2.0-slice loopt via:

`SignalEvent -> SignalDetection -> SignalDetectionImpactAnalyzer -> OpportunitySignal`

De nieuwe impactlaag bouwt:

- waarom dit signaal ertoe doet
- business impact, risk en opportunity scores
- affected scope: workspace, site, topic, entity, events
- urgency en confidence labels
- aanbevolen volgende stap
- suggested action routes voor content, campaign, social, sales, governance of monitoring
- evidence package met score breakdown, evidence summary, recommended actions en linked events

## Implementatieplan

1. Bestaande Signal Intelligence-pipeline inventariseren. Status: afgerond.
2. Impactanalyse als additive read-service toevoegen. Status: afgerond.
3. Detailpagina verrijken met dezelfde impactanalyse. Status: afgerond.
4. Promotie naar `OpportunitySignal` verrijken met `signal_impact.v1` en `signal_evidence_package.v1`. Status: afgerond.
5. Dedupe en lifecycle-gedrag ongemoeid laten. Status: afgerond.
6. Vervolg: source packs, trend velocity per entity/market, en directe Recommended Action-materialisatie. Status: open.

## Bestanden

Toegevoegd:

- `app/Services/SignalIntelligence/SignalDetectionImpactAnalyzer.php`
- `audit/phase_5_signal_intelligence_status_2026-07-13.md`

Gewijzigd:

- `app/Services/SignalIntelligence/SignalDetectionToOpportunitySignalMapper.php`
- `app/Http/Controllers/App/SignalIntelligenceController.php`
- `resources/views/app/signal-intelligence/show.blade.php`
- `tests/Feature/SignalIntelligence/SignalIntelligenceUiTest.php`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Suggested actions zijn nog route-labels, geen uitgevoerde workflows | Open | In Fase 6 materialiseren naar gated `RecommendedAction`/agent workflows. |
| Source packs voor news, competitors, market en connectors zijn nog niet geharmoniseerd | Open | Taxonomy en ingestion packs per source type uitwerken. |
| Entity/market grouping is nog beperkt tot bestaande detection fields | Deels | Koppelen aan Phase 4 Entity Intelligence registry zodra die explicieter is. |
| Impactanalyse is deterministisch en generiek | Bewust | Later templates per markt/market pack toevoegen. |

## Tests

Uitgevoerd:

```bash
php -l app/Services/SignalIntelligence/SignalDetectionImpactAnalyzer.php
php -l app/Services/SignalIntelligence/SignalDetectionToOpportunitySignalMapper.php
php -l app/Http/Controllers/App/SignalIntelligenceController.php
php -l tests/Feature/SignalIntelligence/SignalIntelligenceUiTest.php
php artisan test tests/Feature/SignalIntelligence/SignalIntelligenceSchemaTest.php tests/Feature/SignalIntelligence/SignalIntelligenceIngestionTest.php tests/Feature/SignalIntelligence/SignalIntelligenceDetectionTest.php tests/Feature/SignalIntelligence/SignalIntelligenceUiTest.php
```

Resultaat:

- Syntaxcontrole: groen.
- Signal Intelligence-regressie: 46 tests, 256 assertions.

## Documentatie

De roadmap is bijgewerkt met de eerste Phase 5-voortgang.

## Architectuurcontrole

- Geen nieuwe signal-tabellen toegevoegd.
- Geen bestaande detection/promotion lifecycle aangepast.
- `OpportunitySignal` dedupe blijft gebaseerd op workspace + detection.
- De impactlaag is additive en gebruikt bestaande `SignalDetection`/`SignalEvent` evidence.
