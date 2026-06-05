# Argusly Production Readiness

Date: 2026-06-05
Status: conditionally launch-ready for a controlled MVP launch after environment gates are completed.
Launch readiness score: 78/100.

## 1. Productie gereedheidsrapport

Argusly is a Laravel 13 platform with an account-rooted multi-tenant architecture. Account is the tenant root, Brand is the primary monitored entity, and access is enforced through current account/current brand middleware, policies, permissions, roles, module gates, subscriptions, feature flags and entitlements.

Current platform coverage:

- Models: 129 application models.
- Policies: 41 policy classes registered through `AppServiceProvider`.
- Jobs: 16 queued job classes.
- Migrations: 83 migration files.
- Feature tests: 89 feature test files.
- Queues: `critical`, `ai`, `intelligence`, `publishing`, `webhooks`, `integrations`, `mail`, `maintenance`, `source_sync`.
- Rate limiters: `connector-api`, `analytics-events`, `auth-actions`, `marketing-forms`, `admin-actions`, `tenant-switch`, `ai-actions`.

Production posture by area:

| Area | Status | Notes |
| --- | --- | --- |
| Security | Mostly ready | Tenant boundaries, policies, admin gate and rate limits exist. Requires final secret rotation and production config lock. |
| Permissions | Mostly ready | Broad policy coverage and permission middleware are present. Requires route permission review before public launch. |
| Queues | Mostly ready | Named queues exist. Requires production workers, alert thresholds and retry runbook. |
| Database | Mostly ready | Rich schema and tenant keys exist. Requires production index audit under real data volume. |
| Performance | Partially ready | Views/routes cache correctly. Requires load test and query profiling. |
| Cache | Partially ready | Database/Redis-capable cache config exists. Production should use Redis. |
| Rate limiting | Ready for MVP | Public forms, auth, admin, tenant switch, AI, connector and tracking endpoints are limited. |
| Backup/DR | Needs environment work | Application needs managed DB backups, object storage backups and restore drills. |
| Monitoring/logging | Partially ready | Platform status, queues, scheduler, webhooks and domain events exist. Requires external APM/log sink. |
| Compliance | Partially ready | Activity/domain events provide audit base. Requires retention, DPA, subprocessors and deletion/export process. |
| Release management | Needs process gate | Build/test/cache commands are known. Requires branch protection and rollback procedure. |

## 2. Openstaande risico's

1. Full test suite currently exhausts the local PHP 128MB memory limit during route registration. Targeted suites pass, but release CI must run with production-like memory and prove the complete suite.
2. Mollie integration has checkout and webhook foundation, but production webhook verification should fetch payment status from Mollie before marking invoices/subscriptions paid.
3. Debugbar is installed as a dev dependency. Production must enforce `APP_DEBUG=false` and no debug routes/assets exposed.
4. Production observability is not connected to an external system yet. Internal dashboards are useful, but not enough for incident response.
5. Database performance has not been proven with realistic tenant, mention, signal, LLM request and report volumes.
6. AI cost controls exist, but launch should include billing/credit reconciliation checks against real provider invoices.
7. GDPR/compliance workflows need operational proof: deletion, export, retention, subprocessors and incident notices.
8. Backups are a strategy requirement, not yet verified in code. Launch requires a successful restore drill.

## 3. Openstaande technische schuld

- `routes/web.php` is too large and contributes to memory pressure; split by domain after launch stabilization.
- Some older compatibility routes still exist as deprecated redirects.
- Billing needs fiscal numbering, VAT country rules, credit notes and customer-facing self-service.
- Some placeholder-era views have been replaced across phases, but a final UI sweep should verify every navigation target.
- Queue retry/dead-letter policies are operationally described but not fully enforced per job class.
- Test suite is broad but not yet organized into fast, domain and smoke lanes.

## 4. Aanbevolen MVP scope

MVP should launch with:

- Workspace and brand management.
- Brand Knowledge Center.
- Team, roles, permissions and memberships.
- Source ingestion foundation.
- Intelligence feed, signals, alerts and recommendations.
- AI Visibility dashboard, prompt runs, citation explorer and competitor comparison.
- Brand and competitor intelligence essentials.
- Agentic Marketing orchestration in controlled beta.
- Executive reports and exports where generated reliably.
- Commercial foundation: plans, modules, credits, entitlements, Mollie checkout and invoices.

MVP should not promise:

- Fully automated always-on content publishing without review.
- Self-service billing portal completeness.
- Enterprise SSO/SAML.
- Formal SLA above the proven operational capacity.
- Advanced compliance attestations not yet audited externally.

## 5. Aanbevolen Launch scope

Launch scope should include:

- Production environment with `APP_ENV=production`, `APP_DEBUG=false`, HTTPS-only domains and secure session cookies.
- Redis cache, Redis/database queue with managed workers, scheduler heartbeat and queue alerts.
- External logs/APM/error tracking connected before first public customer.
- Daily database backups, object storage backups and monthly restore drills.
- Mollie live key, webhook verification, payment reconciliation and billing report review.
- Seeded platform roles, plans, modules, credit catalog and feature flags.
- Rate limits enabled for public forms, auth, AI-triggering actions, admin actions and tenant switches.
- Route, config, event and view caches warmed during deploy.
- Smoke test after deploy against login, dashboard, tenant switch, admin status, queue status, billing and one read-only customer workflow.

## 6. Aanbevolen Post Launch roadmap

30 days:

- Split route files by domain.
- Add CI matrix: lint, unit/feature, smoke, browser and migration dry-run.
- Add external APM and uptime monitors.
- Add billing reconciliation dashboard.
- Add automated backup restore verification.

60 days:

- Add customer billing portal and invoice PDFs.
- Add SSO/SAML/OIDC.
- Add tenant data export and deletion workflows.
- Add load-tested ingestion and AI run capacity planning.
- Add query budget dashboards for mentions, signals and visibility runs.

90 days:

- Add enterprise audit reports.
- Add formal incident status page integration.
- Add regional data processing controls.
- Add multi-region DR design if enterprise contracts require it.
- Add security review with dependency, secret and penetration testing.

## 7. Definitieve Argusly architectuurdocumentatie

Core architecture:

- Laravel 13 application using Blade/Tailwind and Vite.
- Account is the tenant root.
- Brand belongs to Account and is the main monitored entity.
- Current tenant context is resolved by `current.account` and `current.brand` middleware.
- Authorization is layered: authentication, tenant context, policy, role, permission, module gate, entitlement and feature flag.
- Admin is isolated with `platform.admin` and now `throttle:admin-actions`.
- Modules are commercial and functional gates: core, visibility, intelligence, competitive intelligence, content, agentic content/social and related capabilities.
- Credits are consumed through cost catalog and overrides, with transaction and usage stat records.
- LLM infrastructure routes by provider/model/settings, logs requests and applies budget/policy guards.
- Domain events are the audit spine for activity logs, signals, recommendations, notifications and graph projection.
- Queues separate critical, AI, intelligence, publishing, webhooks, integrations, mail and maintenance workloads.
- Platform dashboards cover admin status, queues, webhooks, AI runtime, alerts, scheduler and commercial operations.

Data boundaries:

- Every customer object must carry `account_id`.
- Brand-scoped objects must carry both `account_id` and `brand_id`.
- Cross-tenant reads must be denied by query scope, policy or middleware.
- Admin-only data must be reachable only through `platform.admin`.
- Connector and tracking APIs must authenticate or validate origin and be throttled.

Operational architecture:

- Web: PHP-FPM or equivalent Laravel runtime behind HTTPS load balancer.
- Queue: separate workers per queue name with distinct concurrency.
- Scheduler: one scheduler runner only, monitored by heartbeat.
- Cache: Redis in production.
- Database: managed relational database with point-in-time recovery.
- Storage: managed object storage for exports, reports and uploaded assets.
- Logs: centralized structured logs, errors and audit events.

## 8. Definitieve deployment checklist

Pre-deploy:

- Confirm branch is reviewed and CI is green.
- Confirm `.env` production values are set and secrets are rotated.
- Confirm `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
- Confirm `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` or approved database queue.
- Confirm `SESSION_SECURE_COOKIE=true`, secure session domain and HTTPS.
- Confirm `MOLLIE_API_KEY`, LLM provider keys, mail, storage and database credentials.
- Run `composer install --no-dev --prefer-dist --optimize-autoloader`.
- Run `npm ci` and `npm run build`.
- Run `php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`.
- Run `php artisan migrate --force` in maintenance window or blue/green pre-release.

Deploy:

- Put app in maintenance mode only if not using zero-downtime deployment.
- Release new artifact.
- Run migrations.
- Restart PHP workers.
- Restart queue workers with `php artisan queue:restart`.
- Ensure scheduler is active on exactly one node.
- Warm route/config/view caches.
- Disable maintenance mode.

Post-deploy smoke:

- `/up` responds 200.
- Login works.
- Tenant account and brand switch work.
- Dashboard loads.
- Admin platform status loads for platform admin.
- Queue status and scheduler heartbeat are healthy.
- Billing dashboard loads.
- Public signup/contact form accepts valid requests under rate limit.
- Connector API rejects invalid tokens and accepts valid scoped tokens.
- One AI action either completes or is blocked by budget policy with a clear error.

Rollback:

- Keep previous artifact available.
- If migration is backward compatible, rollback code only.
- If migration is not backward compatible, use database snapshot restore only after incident lead approval.
- Restart queues after rollback.
- Run post-rollback smoke test.

## 9. Definitieve testdekking analyse

Current test posture:

- 89 feature test files.
- Targeted commercial, admin, entitlement, queue, AI, intelligence, visibility and reporting tests exist.
- Recent Fase 14 targeted suites passed.
- Full local `php artisan test` currently fails due 128MB PHP memory exhaustion during route registration.

Required CI lanes:

- Fast lane: PHP lint, route load, view cache, config cache, focused feature suites.
- Domain lane: foundation, tenancy, RBAC, module gating, AI runtime, intelligence, billing, reporting.
- Browser lane: login, dashboard, navigation, admin platform, billing, visibility and intelligence pages.
- Migration lane: migrate fresh, seed catalog, route cache, view cache.
- Release lane: full test suite with at least 512MB PHP memory.

Release gate:

- No launch until full CI passes with production-like memory.
- No launch until `composer audit` and `npm audit` are reviewed.
- No launch until backup restore test succeeds.

## 10. Launch readiness score

Score: 78/100.

Rationale:

- Architecture foundation: 9/10.
- Tenant security and authorization: 8/10.
- Commercial readiness: 7/10.
- AI and intelligence readiness: 8/10.
- Observability: 7/10.
- Performance proof: 6/10.
- DR/compliance proof: 5/10.
- Release process: 7/10.

Verdict: Argusly is ready for a controlled MVP launch after production environment gates, external monitoring, backup restore proof and full CI memory configuration are completed. It is not yet ready for a high-SLA enterprise launch without the post-launch hardening items above.
