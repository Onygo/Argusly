# PublishLayer Core Validation Audit

Date: 2026-06-02

Scope: migrated PublishLayer-derived Argusly core before Creator Intelligence work. This audit reviews admin, customer app, marketing site, pilot signup, Content Engine, translation, answer blocks, audits, lifecycle, publishing, connectors, credits, signals, recommendations, approvals, reports/logs, module gating, tenant safety, language support, brand/account context, and graph projection impact.

## Executive Summary

The migrated PL core is broad and partially usable, but not production-ready end to end yet.

Working foundations:

- Authenticated app routes register successfully with `php artisan route:list`.
- Content assets, answer blocks, translation drafts, audits, lifecycle scoring, distribution, connector API callbacks, credits, signals, recommendations, reports, domain events, activity logs, and graph projection foundations exist.
- Connector API has token-scoped middleware, per-request connector logs, idempotent connector events, pending publishing payloads, publish/fail callbacks, language/locale fields, answer blocks, and hreflang payloads.
- Credit deductions exist for content generation, translation, audits, lifecycle checks, publishing actions, newsletter recipients, social publishing, social repurposing, and AI visibility runs.
- Account and brand context is resolved from session/cache/request and checked by most high-value controllers and policies.

Blocking production concerns:

- Validation tests are not green. The PL-core feature slice failed with module/navigation assertions, subscription catalog drift, and an entity domain event tenant-scope error.
- Several top-level product areas route to placeholder module pages rather than real admin/operator workflows.
- Pilot signup is missing. The marketing site advertises trial/demo CTAs but routes them to `/dashboard` or `#demo` without a signup/capture flow.
- Approvals are incomplete across content, newsletters, social, and publishing. Publishing enforces approval, but not every publishable object has a request/decision UI.
- Tenant safety is mostly manual. Tenant global-scope traits exist but are not used by models, so every query must remember account/brand constraints.
- Outbox processing is a stub for non-connector external work; it marks messages completed without actual delivery.
- Graph projection includes Creator-facing projectors/classes and an unsafe `Agent` projection fallback that attaches agents to the first account.

## Validation Run

Commands run:

- `php artisan route:list` passed and showed 250 registered routes.
- `php artisan test --testsuite=Feature --filter='Content|Connector|Credit|Approval|DomainEvent|Outbox|Language|Publishing|Tenant|Module|Report|Recommendation|Signal'`

Result:

- 230 tests, 224 passed, 5 failed, 1 error reported by the JSON test wrapper.

Focused rerun:

- `php artisan test tests/Feature/EntityIntelligenceFoundationTest.php tests/Feature/AudienceFoundationTest.php tests/Feature/SubscriptionArchitectureTest.php`
- 17 tests, 14 passed, 2 failures, 1 error.

Observed failures:

- `AudienceFoundationTest::test_audiences_are_module_gated_and_visible_in_navigation` expected dashboard navigation to include `Audiences`; current sidebar only exposes top-level `Marketing`.
- Same pattern appeared in the broader run for `Newsletters`.
- `SubscriptionArchitectureTest::test_subscription_catalog_seeder_creates_modules_and_monthly_yearly_plans` expected 10 modules, current config seeds 11 modules.
- `EntityIntelligenceFoundationTest::test_entities_are_tenant_safe_and_brand_aware` errors on global entity creation because `Entity::created` always calls `DomainEventService::recordForSubject`, which requires a tenant-scoped subject.

## Route and UI Coverage

Concrete routes/controllers:

- Content Engine: `ContentAssetController`, `AnswerBlockController`, `DistributionController`.
- Marketing/customer side: campaigns, calendar, tasks, audiences, briefings, newsletters, social posts.
- Intelligence: signals, recommendations actions, narratives, graph, notifications.
- Visibility: AI visibility checks/prompts, search performance.
- Connectors: settings connector management and `/api/v1/connector/*`.
- Reports/logs: reports, domain events, source sync history, connector logs route placeholder, outbox route placeholder, activity route placeholder.
- Settings: account, brands, team, modules/billing, integrations, social profiles, email providers, connectors, properties, channels, knowledge graph, knowledge center.

Placeholder or incomplete routes:

- `/intelligence/recommendations` is a module placeholder, while recommendation actions exist via POST routes.
- `/content/audits`, `/content/lifecycle`, `/content/translations` are placeholders, despite per-asset actions existing.
- `/marketing/tasks`, `/agents/tasks`, `/agents/runs`, `/agents/automations`, `/assets/*`, `/reporting/executive`, `/visibility/citations`, `/admin/credits`, `/admin/developer-tools/connector-logs`, `/admin/developer-tools/outbox`, `/admin/developer-tools/activity`, `/admin/developer-tools/queue`, `/admin/developer-tools/system-health`, and `/admin/developer-tools/diagnostics` are placeholders.
- Relationship sub-lanes for journalists, influencers, and experts are placeholders.

## Broken Flows

1. Entity creation for global/account-wide entities can throw during domain event recording.
   - `Entity::created` records `EntityCreated` through `recordForSubject`.
   - `DomainEventService::tenantForSubject` rejects subjects without an account.
   - This breaks global entity creation in tests and likely breaks seed/admin workflows.

2. Navigation test expectations are out of sync with the redesigned sidebar.
   - Marketing child pages such as audiences/newsletters exist, but the primary sidebar renders only top-level workspace links.
   - This is either a test update need or a UX regression if users need direct access.

3. Subscription module catalog drift is unacknowledged.
   - Config now has 11 modules, while tests expect 10.
   - This may be legitimate, but it makes module-gating validation unreliable.

4. Publishing approval enforcement can block users without a clear request flow.
   - `PublishingService::request` calls `ApprovalService::assertApprovedForPublish`.
   - Content assets have approve/publish actions but no generic approval request route.
   - Social posts can approve directly, but no approval-request route is wired.

5. Newsletter approval is one-way from UI.
   - Newsletter can request approval, but no newsletter approve/reject route is registered.
   - `ApprovalService` supports newsletter approvals, so the backend and UI are mismatched.

6. Outbox does not actually deliver external non-connector work.
   - `OutboxService::process` marks messages completed and comments that external connector execution is intentionally not implemented.
   - This makes admin outbox status misleading for production.

7. Marketing CTAs advertise trial/demo without a capture flow.
   - Home page buttons point to `/dashboard` or `#demo`.
   - There is no register, pilot signup, demo request, or early access route.

## Missing Flows

- Pilot signup / trial account creation / demo request capture.
- Admin credit ledger screen with balance, transactions, manual grant/reversal controls, and deduction audit.
- Real outbox admin screen with retry, cancel, dead-letter/error detail, and idempotency key visibility.
- Connector logs admin screen; route exists but uses a placeholder.
- Queue/system health screens; routes exist but use placeholders.
- Aggregate audit, lifecycle, and translation screens; only per-asset actions are wired.
- Content approval request/reject flow and a consistent approval inbox.
- Newsletter approve/reject/send flow.
- Social approval request flow and scheduled-publish worker path visibility.
- Recommendation index screen with filters and execution audit; current route is placeholder.
- Real asset library/media/generated media screens.
- Pilot/customer onboarding to set account, brand, modules, credits, language, properties, channels, and connectors.
- Billing/subscription self-service. Settings modules explicitly says no payment integration yet.
- Report export/share/download flow; reports are static snapshots in-app only.
- Connector outbound delivery for non-polling targets.
- Explicit graph rebuild/replay admin actions and failed projector retry UI.

## UX Gaps

- Many pages use generic placeholders, which makes the app feel wider than it is.
- Empty states often say future workers or future integrations will populate data; useful for development, but not production customer wording.
- The dashboard exposes a large amount of empty telemetry at once, which can overwhelm a new tenant.
- Admin/developer tools are listed, but most tools do not show real data or recovery actions.
- Approval state is fragmented between content status fields and `approvals`.
- Publishing, connector queue, outbox, and domain event states are not connected into one operator view.
- The redesigned sidebar hides child routes; tests expecting `Audiences`/`Newsletters` failing suggests discoverability was reduced.
- Marketing site uses polished product claims for agentic automation and trials, but the live app still contains fake/static provider flows.
- Error feedback is inconsistent: controllers catch insufficient credits in several places, but approval exceptions and invalid publishing state can surface as generic errors or 403s.

## Security Risks

1. Manual tenant isolation is fragile.
   - `BelongsToAccount` and `BelongsToBrand` global-scope traits exist but no model appears to use them.
   - Most controllers/services do explicit `account_id`/`brand_id` filtering, but any missed query can leak data.

2. Route model binding loads records before tenant filtering.
   - Policies usually catch cross-tenant access, but controllers often call `Gate::authorize` before `findForTenant`.
   - This is acceptable only if every policy remains strict.

3. `GraphProjectionService::agent` assigns agents to `Account::query()->first()`.
   - This can project agent graph nodes into the wrong tenant and should not be production enabled.

4. Account-wide plus brand-scoped data mixing needs stricter rules.
   - Some tenant queries intentionally include `whereNull('brand_id') OR current brand`.
   - This is useful for account-wide signals, but should be explicitly documented per model to avoid accidental cross-brand exposure.

5. Connector tokens are solidly scoped, but token creation/rotation UI must avoid displaying secrets after creation.
   - Confirm existing views do not re-render raw token values beyond the creation moment before production.

6. Connector API rate limiting is token/IP based but lacks payload-size and replay-window controls beyond event idempotency.
   - Large payloads and repeated non-idempotent health/register calls could still create noisy logs or state churn.

7. Approval bypass logic may be too permissive or unclear.
   - `assertApprovedForPublish` returns early when no account-scoped role assignment exists, which could create edge cases for brand-only role holders.

## Tenant, Brand, Account, and Language Context

Positive:

- `CurrentAccount` and `CurrentBrand` validate active memberships and clear invalid cached context.
- `CurrentBrand` prevents selecting a brand outside current account.
- Most core customer routes use `auth`, `current.account`, and `current.brand`.
- Content, answer blocks, publishing, translation, audits, lifecycle, connectors, and reports carry account/brand/language/locale fields.
- `ContentLanguageService` is used for validation and enabled language lists in content, translation, briefings, newsletters, social posts, distribution, and connector payloads.

Risks:

- Account-only flows and global entities are not consistently compatible with domain events and graph projection.
- Some flows require a brand even where account-wide objects exist.
- Language support creates translation draft copies but does not do real translation.
- Locale defaults are inferred and can drift from brand/market context without an explicit tenant setup flow.

## Jobs, Events, Outbox, Logs, and Graph Impact

Working:

- Jobs exist for content generation, audit, lifecycle, content publishing, social publishing, visibility checks, GA4/Search Console sync, outbox, domain event projection, newsletters.
- `ProjectorRegistry` records projector runs and failures.
- `DomainEventService` records, validates, dispatches, and paginates tenant events.
- `ActivityLogger` records auth/context/publishing/connector activity when the table exists.
- Signal projectors/producers exist for publishing, generation, audits, lifecycle, integrations, low credits, and failures.
- Graph projectors support brand, topic, entity, mention, narrative, campaign, creator, relationship, recommendation, competitor, content, and agent nodes.

Risks:

- Most jobs have no explicit retries, backoff, tags, timeout, or operator-facing failed-job recovery guidance.
- Some job failure paths update local status but do not consistently record domain events/activity logs.
- Domain event projection can block event processing if a projector throws; run rows are recorded, but replay UI is missing.
- Outbox processing currently simulates success.
- Graph projection introduces Creator-facing classes despite the instruction not to implement Creator Intelligence yet. They should be dormant or removed from runtime registration until intentionally enabled.

## Prioritized Fixes

P0 - Make validation green and remove known breakage:

- Fix global/account entity event handling: either skip `recordForSubject` for global entities, record accountless events through a separate path, or require entities to be tenant-scoped.
- Align module/navigation tests and UI intentionally: either restore discoverable child navigation for audiences/newsletters or update tests to the top-level workspace design.
- Update subscription catalog tests or config so the 11-module state is deliberate and documented.

P0 - Close production-blocking workflow gaps:

- Add pilot signup/demo capture or remove trial/demo promises from marketing until signup exists.
- Add a unified approval inbox and approve/reject/request routes for content, newsletters, social posts, generated assets, and publishing actions.
- Replace placeholder admin pages for connector logs, outbox, credits, queue, and activity with read-only operational views at minimum.

P1 - Strengthen tenant safety:

- Decide whether to adopt model global scopes or formalize a `forTenant` contract per tenant model and add tests for every customer/admin route.
- Remove or fix `GraphProjectionService::agent` first-account fallback.
- Audit every route-model-bound controller to ensure `findForTenant` or strict policy checks happen before data is rendered or mutated.

P1 - Make publishing/connectors production-usable:

- Implement real outbox delivery semantics or clearly separate polling connector queues from outbound webhook queues.
- Add retry/cancel/requeue and error views for outbox and publishing actions.
- Add connector health/log pages with token, installation, channel, and recent event context.
- Make connector payload limits, idempotency behavior, and replay windows explicit.

P1 - Improve customer-ready UX:

- Replace placeholder pages with either real minimal list/detail views or hide them from navigation.
- Improve empty states for new tenants with setup actions: brand profile, languages, properties, channels, credits, connectors, first content asset.
- Add status timelines on content assets for generation, translation, audit, lifecycle, approval, publishing, connector callback, and outbox state.

P2 - Operational polish:

- Add retries/backoff/tags/timeouts to queue jobs.
- Record domain events/activity logs consistently on job failure.
- Add report export/share/download.
- Add aggregate Content Engine screens for audits, lifecycle, translations, and answer blocks with filters.
- Add language setup and translation coverage screens.

## Production Readiness Verdict

The PL-derived Argusly core has enough backend foundation to continue hardening, but it should not be treated as production-usable yet. The next milestone should be a stabilization pass over existing PL core only: make validation green, remove misleading placeholders/promises, complete approval and pilot/signup paths, expose operator logs/outbox/credits, and tighten tenant/graph safety. Creator Intelligence should stay out of scope until those fixes land.
