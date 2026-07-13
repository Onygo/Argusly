# Argusly Production Operations Runbook

This runbook covers the minimum operating posture for a public SaaS launch.

## Release Gates

- `APP_ENV=production`, `APP_DEBUG=false`, and a non-local `APP_URL`.
- `php artisan optimize:clear` before deployment, `php artisan storage:link --force` after code is in place, and `php artisan config:cache`, `route:cache`, and `view:cache` after environment variables are final.
- `ARGUSLY_WP_REQUIRE_TS_NONCE=true` unless a named legacy connector migration window is active.
- `ARGUSLY_LEGACY_PATH_ROUTES_ENABLED=false` unless a measured compatibility window is active.
- Sentry DSN configured for application errors and queue worker exceptions.
- Mailgun, Mollie, OpenAI, Anthropic, Mistral, Google AI, and Sentry credentials verified from the production environment.

## Workers And Scheduler

Run the scheduler every minute:

```bash
* * * * * cd /var/www/argusly/current && php artisan schedule:run >> /dev/null 2>&1
```

Run persistent queue workers under Supervisor or systemd:

```bash
php artisan queue:work --queue=default,emails,markdown --timeout=300 --tries=3
php artisan queue:work --queue=generation,ai-low,brief-intelligence,research --timeout=3600 --tries=2
php artisan queue:work --queue=deliveries --timeout=180 --tries=3
php artisan queue:work --queue=billing --timeout=300 --tries=3
php artisan queue:work --queue=agentic-marketing,intelligence,content-network --timeout=1800 --tries=2
php artisan queue:work --queue=page_intelligence_discover,page_intelligence_fetch,page_intelligence_extract,page_intelligence_analyze,page_intelligence_score,page_intelligence_signal,page_intelligence_alert,page_intelligence_reports --timeout=1800 --tries=3
```

Restart workers after each deployment:

```bash
php artisan queue:restart
```

Keep worker timeouts below the queue connection `retry_after` value. The default database and Redis retry window is currently 3900 seconds.

### Queue Topology

| Queue group | Queues | Critical flows | Launch posture |
| --- | --- | --- | --- |
| Core | `default`, `emails`, `markdown` | Connector sync dispatch, onboarding scans, analytics events, markdown artifacts, email jobs. | Always on. Alert when heartbeat is stale or depth keeps rising. |
| Generation and AI | `generation`, `ai-low`, `brief-intelligence`, `research` | Briefs, drafts, comparisons, image generation, draft intelligence, research extraction. | Always on for pilots. Research may stay gated by feature flag. |
| Deliveries | `deliveries` | WordPress/Laravel connector publish, draft delivery, webhooks, knowledge sync. | Always on. Treat sustained failures as launch-blocking. |
| Billing | `billing` | Mollie, credits, dunning, invoices, mandates, monthly credit backfill. | Always on. Treat any failed payment or credit job as P0 until inspected. |
| Intelligence | `intelligence`, `agentic-marketing`, `content-network` | Opportunity intelligence, agentic tasks, social/campaign planning, learning optimization, content network analysis. | Keep autonomous publishing off unless policy and approvals are verified. |
| Page Intelligence | `page_intelligence_discover`, `page_intelligence_fetch`, `page_intelligence_extract`, `page_intelligence_analyze`, `page_intelligence_score`, `page_intelligence_signal`, `page_intelligence_alert`, `page_intelligence_reports` | Discovery, fetch, extraction, scoring, alerts, report artifacts and scheduled briefings. | Needed for Content Inventory and Page Intelligence pilots. Rate-limit by host and monitor report failures. |

### Scheduler Checks

Launch environments must prove that these scheduled groups run:

- Every minute: brief processing, draft generation/delivery, scheduled content/social dispatch, content automations, worker heartbeat.
- Every 5-15 minutes: connector sync/health/recovery, page intelligence briefings, stale generation reconciliation, credit reservation expiry, Mollie webhook diagnostics.
- Hourly/daily: billing maintenance, LLM tracking, analytics rollups, website inventory discovery/refresh, AI visibility, SEO/indexation checks, canonical diagnostics.

### Alert Thresholds

Use these as starting thresholds for production pilots:

| Signal | Initial threshold | Severity |
| --- | --- | --- |
| Worker heartbeat | No heartbeat for more than 120 seconds. | P0 if jobs are pending; P1 otherwise. |
| Failed jobs | Any `billing`, `deliveries`, `generation`, `agentic-marketing` or `page_intelligence_reports` failure. | P0 until inspected during launch week. |
| Queue depth | `billing` or `deliveries` above 25 for 10 minutes. | P1. |
| Queue depth | `generation`, `ai-low`, `research` or Page Intelligence group above 100 for 30 minutes. | P2, P1 if customer-facing SLAs are affected. |
| Connector health | Health check failure for an active pilot site for 2 consecutive runs. | P1. |
| LLM providers | Error/timeout rate above 10% over 15 minutes or fallback exhausted. | P1. |
| Publish success | Any scheduled publish stuck beyond its intended window by 15 minutes. | P1. |
| Mailgun/Mollie | Webhook delivery failures, bounces for operational mail, or payment activation lag. | P0 for billing, P1 for non-billing mail. |

## Content Images

Content images are served from `/content-images/...` and must be backed by the configured public storage link after every production update:

```bash
mkdir -p storage/app/public/content-images
php artisan storage:link --force
php artisan argusly:diagnostics
```

`argusly:diagnostics` should report `images.public_link` as `linked` and `images.storage_dir` as `exists`. Do not use `storage:link --relative` unless `symfony/filesystem` is installed in the production build.

## Monitoring

Check these at least daily during launch week:

- `/up` returns 200.
- Admin system health page shows no stuck critical queues.
- `failed_jobs` count and trend by queue.
- Sentry issue count, new issue rate, and unresolved production regressions.
- Mollie webhook delivery and subscription activation lag.
- Mailgun delivery, bounce, and complaint rate.
- AI provider error rate, latency, timeout rate, and fallback usage.
- Storage usage for generated images, markdown artifacts, and page intelligence snapshots.

## Backups And Restore Tests

- Database: encrypted daily backups with at least 30 days retention.
- Files: backup `storage/app` disks that hold content images, plugin archives, PDFs, and generated artifacts.
- Secrets: store production env values in a managed secret store, not only on the server.
- Restore test: perform a full database restore into an isolated environment before launch, then monthly.
- Restore acceptance: login works, one workspace loads, one content record renders, one invoice PDF renders, and one backup file can be retrieved.

## Incident Procedure

1. Set an incident owner and severity.
2. Preserve evidence: timestamp, deploy SHA, Sentry issue, logs, affected routes, affected workspace IDs.
3. Stop the blast radius: maintenance mode, feature flag, queue pause, provider failover, or rollback.
4. Communicate status to affected customers when data, billing, publishing, or availability is impacted.
5. Record a post-incident note with cause, mitigation, prevention, and owner.

## Launch Week Cadence

- Morning: health page, failed jobs, Sentry, billing webhooks, email health.
- Midday: smoke test critical paths and review support inbox.
- End of day: backup completion, queue depth, unresolved production errors, release notes.
