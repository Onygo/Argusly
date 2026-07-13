# Argusly Go-Live Checklist

**Date**: 2026-03-13
**Target Launch**: TBD
**Status**: Pre-Launch

---

## Pre-Launch (1 Week Before)

### Database & Migrations

- [ ] Backup staging database
- [ ] Apply all pending migrations on staging
  ```bash
  php artisan migrate
  ```
- [ ] Verify migration status
  ```bash
  php artisan migrate:status | grep -c "Ran"
  ```
- [ ] Test Draft Intelligence features
- [ ] Test Laravel Connector sync
- [ ] Test WordPress plugin sync
- [ ] Document rollback procedure

### Code Quality

- [ ] Remove all debug statements (dd, dump, var_dump, print_r)
  ```bash
  grep -rn "dd\|dump\|var_dump\|print_r" app resources/views --include="*.php" --include="*.blade.php"
  ```
- [ ] Remove console.log from JavaScript
  ```bash
  grep -rn "console\.log" resources/js
  ```
- [ ] Run full test suite
  ```bash
  php artisan test
  ```
- [ ] Fix any failing tests
- [ ] Triage intentional skipped tests and record owner/decision:
  - [ ] `tests/Feature/UI/ContentDeliveryUITest.php` WordPressConnector HTTP verification skip
  - [ ] `tests/Feature/Status/ContentStatusSeparationTest.php` publish status mapping skip
  - [ ] `tests/Feature/Public/CrossLocaleRedirectTest.php` full auth context skip

### Environment Configuration

- [ ] Verify all required secrets are set:
  - [ ] `OPENAI_API_KEY`
  - [ ] `ANTHROPIC_API_KEY`
  - [ ] `GEMINI_API_KEY`
  - [ ] `MISTRAL_API_KEY`
  - [ ] `MAILGUN_SECRET`
  - [ ] `MAILGUN_DOMAIN`
  - [ ] `MAIL_FROM_ADDRESS`
  - [ ] `MOLLIE_KEY`
  - [ ] `SENTRY_LARAVEL_DSN`
  - [ ] `RECAPTCHA_SITE_KEY`
  - [ ] `RECAPTCHA_SECRET_KEY`
  - [ ] `ARGUSLY_ADMIN_KEY`
- [ ] Verify database credentials
- [ ] Verify Redis/cache configuration
- [ ] Verify queue connection settings
- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`

### Queue Workers

- [ ] Configure supervisor/systemd for queue workers
- [ ] Set up workers for all queues:
  - [ ] `default`
  - [ ] `emails`
  - [ ] `markdown`
  - [ ] `generation`
  - [ ] `ai-low`
  - [ ] `brief-intelligence`
  - [ ] `research`
  - [ ] `deliveries`
  - [ ] `billing`
  - [ ] `agentic-marketing`
  - [ ] `intelligence`
  - [ ] `content-network`
  - [ ] `page_intelligence_discover`
  - [ ] `page_intelligence_fetch`
  - [ ] `page_intelligence_extract`
  - [ ] `page_intelligence_analyze`
  - [ ] `page_intelligence_score`
  - [ ] `page_intelligence_signal`
  - [ ] `page_intelligence_alert`
  - [ ] `page_intelligence_reports`
- [ ] Configure failed job notifications
- [ ] Configure queue depth alerts
- [ ] Verify `queue:worker-heartbeat` updates cache at least every 120 seconds
- [ ] Test job processing end-to-end

### Feature Flag Matrix

Record the production decision before launch. Default-off flags should remain off unless a named owner signs off on pilot scope, rollback and monitoring.

| Feature flag | Env var | Default | Launch decision |
| --- | --- | --- | --- |
| `agentic_marketing` | `ARGUSLY_FEATURE_AGENTIC_MARKETING` | On | Controlled beta/GA candidate. Keep approval gates and autonomy policies enforced. |
| `signal_intelligence` | `ARGUSLY_SIGNAL_INTELLIGENCE_ENABLED` | On | Controlled beta/GA candidate. Keep source and alert policy scoped per pilot market. |
| `network_linking` | `ARGUSLY_FEATURE_NETWORK_LINKING` | Off | Keep off; product/spec/QA required before reactivation. |
| `draft_link_suggestions` | `ARGUSLY_FEATURE_DRAFT_LINK_SUGGESTIONS` | Off | Keep off unless link-intelligence pilot is explicitly selected. |
| `link_intelligence_jobs` | `ARGUSLY_FEATURE_LINK_INTELLIGENCE_JOBS` | Off | Keep off until worker load, idempotency and review UX are approved. |
| `research_layer` | `ARGUSLY_FEATURE_RESEARCH_LAYER` | Off | Internal/beta only; enable only with provider cost model. |
| `brief_intelligence` | `ARGUSLY_FEATURE_BRIEF_INTELLIGENCE` | Off | Beta only; enable after LLM cost and quality gates. |
| `brief_templates` | `ARGUSLY_FEATURE_BRIEF_TEMPLATES` | Off | Product decision needed. |
| `content_network_analysis` | `ARGUSLY_FEATURE_CONTENT_NETWORK_ANALYSIS` | Off | Internal/beta only; depends on content network UX and worker capacity. |
| MOS canonical content opportunity flags | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_*` | Off | Keep off for production runtime unless MOS migration sign-off exists. |
| MOS agentic opportunity flags | `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_*` | Off | Keep off except approved dry-run/apply commands. |
| MOS agentic planner flags | `ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_*` | Off | Keep off; preview/shadow/apply flows require explicit operator command and audit review. |
| MOS execution metadata writer | `ARGUSLY_FEATURE_MOS_AGENTIC_EXECUTION_CANONICAL_METADATA_WRITER` | Off | Keep off unless canonical metadata rollout is approved. |

### WordPress Plugin

- [ ] Create `uninstall.php` cleanup handler
- [ ] Test plugin activation on fresh WordPress
- [ ] Test plugin deactivation
- [ ] Test plugin uninstallation (data cleanup)
- [ ] Verify auto-update mechanism
- [ ] Test brief creation flow
- [ ] Test draft receipt flow

### Laravel Connector

- [ ] Run connector test suite
  ```bash
  cd argusly-laravel-connector && composer test
  ```
- [ ] Test webhook signature verification
- [ ] Test knowledge base sync
- [ ] Verify health check endpoint
- [ ] Document installation steps

---

## Pre-Launch (1 Day Before)

### Final Staging Verification

- [ ] Deploy latest code to staging
- [ ] Run smoke tests on all features:
  - [ ] User registration/login
  - [ ] Workspace creation
  - [ ] Brief creation
  - [ ] Draft generation
  - [ ] Draft editing
  - [ ] Content publishing (WordPress)
  - [ ] Content publishing (Laravel Connector)
  - [ ] Credit purchase
  - [ ] Credit usage
  - [ ] Billing webhooks

### Load Testing

- [ ] Test draft generation under load
- [ ] Test concurrent credit operations
- [ ] Test webhook receipt rate
- [ ] Verify queue processing speed

### Backup Verification

- [ ] Verify database backup procedure
- [ ] Test backup restoration
- [ ] Document backup schedule
- [ ] Verify backup retention policy

### Monitoring Setup

- [ ] Configure error tracking (Sentry/Bugsnag)
- [ ] Set up uptime monitoring
- [ ] Configure queue health alerts
- [ ] Set up disk space alerts
- [ ] Configure database connection alerts

---

## Launch Day

### Pre-Deploy (Morning)

- [ ] Final backup of production database
- [ ] Notify team of deployment window
- [ ] Prepare rollback commands
- [ ] Clear application caches on staging

### Deployment

- [ ] Deploy code to production
- [ ] Run migrations
  ```bash
  php artisan migrate --force
  ```
- [ ] Recreate public storage links for generated content images
  ```bash
  mkdir -p storage/app/public/content-images
  php artisan storage:link --force
  php artisan argusly:diagnostics
  ```
- [ ] Clear and rebuild caches
  ```bash
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```
- [ ] Restart queue workers
  ```bash
  php artisan queue:restart
  ```
- [ ] Verify application is responding

### Post-Deploy Verification

- [ ] Check error logs for immediate issues
- [ ] Verify homepage loads
- [ ] Verify login works
- [ ] Verify API endpoints respond
- [ ] Check queue workers are processing
- [ ] Verify LLM calls succeed (test draft generation)
- [ ] Verify billing webhooks work
- [ ] Test WordPress plugin sync
- [ ] Test Laravel Connector sync

### Monitoring (First 4 Hours)

- [ ] Monitor error rates
- [ ] Monitor queue depth
- [ ] Monitor database connections
- [ ] Monitor memory usage
- [ ] Monitor response times
- [ ] Check for failed jobs
- [ ] Verify credit transactions completing

---

## Rollback Plan

### If Critical Issues Found

1. **Immediate rollback trigger criteria**:
   - 500 errors > 5% of requests
   - Queue backup > 1000 jobs
   - Payment processing failures
   - Data corruption detected

2. **Rollback steps**:
   ```bash
   # Stop queue workers
   supervisorctl stop argusly-worker:*

   # Rollback migrations (if needed)
   php artisan migrate:rollback --step=9

   # Deploy previous release
   # (deployment-specific commands)

   # Restart queue workers
   supervisorctl start argusly-worker:*
   ```

3. **Communication**:
   - Notify team immediately
   - Post status page update
   - Document issue for post-mortem

---

## Post-Launch (Week 1)

### Daily Checks

- [ ] Review error logs
- [ ] Check failed jobs table
- [ ] Verify queue processing
- [ ] Monitor credit balance accuracy
- [ ] Check sync success rates

### Week 1 Tasks

- [ ] Address any P1 issues discovered
- [ ] Complete integration tests for Laravel Connector
- [ ] Review user feedback
- [ ] Optimize slow queries if found
- [ ] Document any undocumented features

---

## Emergency Contacts

| Role | Contact | Availability |
|------|---------|--------------|
| Lead Developer | TBD | 24/7 launch week |
| Database Admin | TBD | On-call |
| DevOps | TBD | On-call |

---

## Sign-Off

| Area | Owner | Signed Off | Date |
|------|-------|------------|------|
| Code Quality | | [ ] | |
| Database | | [ ] | |
| Security | | [ ] | |
| Infrastructure | | [ ] | |
| Testing | | [ ] | |
| Documentation | | [ ] | |

---

**Checklist Status**: Ready for Use
**Last Updated**: 2026-03-13
