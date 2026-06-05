# Argusly Deployment Checklist

Date: 2026-06-05

## Required Production Environment

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://app.argusly.com`
- `ARGUSLY_MARKETING_DOMAIN=argusly.com`
- `ARGUSLY_APP_DOMAIN=app.argusly.com`
- `ARGUSLY_API_DOMAIN=api.argusly.com`
- `ARGUSLY_TRACK_DOMAIN=track.argusly.com`
- `SESSION_DOMAIN=.argusly.com`
- `SESSION_SECURE_COOKIE=true`
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis` or approved managed database queue
- `LOG_CHANNEL=stack` with centralized production log sink
- `LOG_LEVEL=warning`
- `MOLLIE_API_KEY` set to live key
- LLM provider keys set only for approved providers

## Build

1. `composer install --no-dev --prefer-dist --optimize-autoloader`
2. `npm ci`
3. `npm run build`
4. `php artisan config:cache`
5. `php artisan route:cache`
6. `php artisan view:cache`
7. `php artisan event:cache`

## Database

1. Confirm latest backup exists.
2. Confirm rollback snapshot is available.
3. Run `php artisan migrate --force`.
4. Confirm seed/catalog data exists for roles, permissions, plans, modules and credit costs.

## Workers

Run separate workers for:

- `critical`
- `ai`
- `intelligence`
- `publishing`
- `webhooks`
- `integrations`
- `mail`
- `maintenance`

Each worker group must have:

- Restart policy.
- Memory limit.
- Max execution timeout.
- Failed job alert.
- Queue depth alert.

## Scheduler

- Exactly one scheduler runner is active.
- Scheduler heartbeat is visible in platform status.
- `visibility:run-due` and `reports:generate-scheduled` are reviewed before enabling high frequency schedules.

## Monitoring

- `/up` uptime monitor.
- Login page uptime monitor.
- Queue failed jobs monitor.
- Queue depth monitor.
- Scheduler heartbeat monitor.
- Database CPU/storage/connection monitor.
- Redis memory monitor.
- HTTP 5xx monitor.
- Mollie webhook failure monitor.
- LLM provider failure and budget monitor.

## Backup And Restore

- Database point-in-time recovery enabled.
- Daily logical backup retained.
- Object storage backup enabled.
- Monthly restore drill scheduled.
- Restore runbook owner assigned.

## Security

- No debug mode.
- Secrets rotated before launch.
- Admin users reviewed.
- Platform admin list approved.
- Public forms throttled.
- Auth actions throttled.
- Admin actions throttled.
- AI actions throttled.
- Connector API token scopes reviewed.
- Mollie webhook verification enabled before full public billing.

## Smoke Test

1. `/up` returns OK.
2. Marketing homepage loads.
3. Signup/contact form submits under rate limit.
4. Login succeeds.
5. Dashboard loads.
6. Tenant account switch succeeds.
7. Tenant brand switch succeeds.
8. Admin platform page loads for platform admin.
9. Admin page is forbidden for non-admin.
10. Billing dashboard loads.
11. Queue monitor loads.
12. LLM monitor loads.
13. Intelligence feed loads.
14. AI Visibility page loads.
15. One queue job can be processed.

## Rollback

1. Announce incident internally.
2. Pause queue workers if jobs could amplify the issue.
3. Revert to previous artifact.
4. Restart PHP runtime.
5. Run `php artisan queue:restart`.
6. Resume workers.
7. Run smoke test.
8. Record incident summary and follow-up tasks.
