# Phase 0 launch hardening status

Datum: 2026-07-12
Scope: eerste uitvoering van het roadmap plan, beperkt tot launch hardening. Geen nieuwe productfeatures.

## Analyse

Argusly heeft een brede functionele basis. De eerste Phase 0-blokkade was testbetrouwbaarheid: de unit-suite faalde doordat `makeDraftIntelligenceContext()` alleen in een feature-testbestand stond. Die blokkade is opgelost door gedeelde test support toe te voegen.

De resterende Phase 0-risico's zitten vooral in productie-operatie: env/secrets, queue workers, scheduler, storage links, provider smoke tests, monitoring, feature flags en stale launchdocumentatie.

## Ontwerp

Phase 0 blijft een hardening-laag bovenop de bestaande architectuur:

- Geen nieuwe domeinmodellen.
- Geen nieuwe productfeatures.
- Geen autonome publishing.
- Geen parallelle queue-, notification-, action- of registry-systemen.
- Bestaande primitives blijven leidend: jobs, scheduler, feature flags, policies, admin health, audit trails en runbooks.

## Implementatieplan

1. Testfundament stabiliseren. Status: afgerond.
2. Go-live checklist actualiseren. Status: gestart.
3. Queue topology en alerting vastleggen. Status: gestart.
4. Feature-flag matrix vastleggen. Status: gestart.
5. Skipped tests triageren. Status: gestart, eigenaars nog open.
6. Productie-env en provider smoke tests uitvoeren. Status: open, vereist echte omgeving/secrets.
7. Stale docs reconciliatie uitvoeren. Status: open.

## Bestanden

Gewijzigd of toegevoegd:

- `tests/Support/DraftIntelligenceTestHelpers.php`
- `tests/Pest.php`
- `tests/Feature/Drafts/DraftIntelligenceFeatureTest.php`
- `docs/architecture/agentic-marketing-os-execution-guardrails.md`
- `docs/launch-operations-runbook.md`
- `audit/go_live_checklist.md`
- `audit/argusly_functional_status_audit_2026-07-12.md`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`
- `audit/phase_0_launch_hardening_status_2026-07-12.md`

## Risico's

| Risico | Status | Volgende actie |
| --- | --- | --- |
| Productie-env niet bewezen | Open | Controleer `APP_ENV`, `APP_DEBUG`, URLs, Redis/database queues, storage links en secrets in productie/staging. |
| Externe providers niet live bewezen | Open | Smoke tests draaien voor Mailgun, Mollie, LLM providers, WordPress, Laravel connector, LinkedIn/Meta en Sentry. |
| Queue topology niet operationeel gevalideerd | Deels gedocumenteerd | Supervisor/systemd configureren en queue heartbeat/depth/failures monitoren. |
| Feature flags kunnen te veel product tegelijk openen | Matrix toegevoegd | Per flag eigenaar, rollout en rollback vastleggen. |
| Skipped tests blijven onduidelijk | Triage gestart | Per skip bepalen: externe vereiste, oude TODO of alsnog activeren. |
| Page Intelligence email delivery is placeholder | Open P1 | Pas na Phase 0 oppakken als action/delivery werk, niet als launch-hardening fix. |

## Tests

Uitgevoerde verificatie voor de codewijzigingen:

```bash
php -l tests/Pest.php
php -l tests/Support/DraftIntelligenceTestHelpers.php
php -l tests/Feature/Drafts/DraftIntelligenceFeatureTest.php
php artisan test tests/Unit/Drafts/Intelligence
php artisan test --testsuite=Unit
composer validate
npm run build
php artisan schedule:list
```

Resultaat:

- Targeted Draft Intelligence unit tests: 17 passed, 72 assertions.
- Unit-suite: 880 passed, 4747 assertions.
- `composer validate`: passed.
- `npm run build`: passed.
- `php artisan schedule:list`: passed after sandbox escalation for local database/cache-lock access.

Nog nodig voor volledige Phase 0-acceptatie:

```bash
php artisan test --testsuite=Feature
```

De feature-suite was in de functionele audit groen met 3639 passed en 3 skipped, maar moet opnieuw worden gedraaid vlak voor release omdat Phase 0 nog niet volledig is afgesloten.

## Documentatie

De roadmap en audit verwijzen nu naar de afgeronde unit-fix. De launch runbook bevat een uitgebreidere worker topology en alertdrempels. De go-live checklist bevat een feature-flag matrix en test-skip triagepunten.

## Architectuurcontrole

De wijzigingen volgen de Agentic Marketing OS-guardrails:

- Geen parallelle domeinen toegevoegd.
- Geen productlaag gebouwd voor Phase 1 of later.
- Geen autonome publishing ingeschakeld.
- Geen bestaande canonical models vervangen.
- De roadmap blijft afhankelijk van de operating loop: observations, knowledge, insights, recommendations, actions, execution, measurement en learning.
