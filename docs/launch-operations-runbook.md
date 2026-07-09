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
php artisan queue:work --queue=ai-low,generation,agentic-marketing,intelligence,default,deliveries,billing,markdown,emails,brief-intelligence,research,content-network --timeout=3600 --tries=3
php artisan queue:work --queue=deliveries --timeout=120 --tries=3
```

Restart workers after each deployment:

```bash
php artisan queue:restart
```

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
