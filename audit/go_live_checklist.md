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

### Environment Configuration

- [ ] Verify all required secrets are set:
  - [ ] `OPENAI_API_KEY`
  - [ ] `ANTHROPIC_API_KEY`
  - [ ] `GEMINI_API_KEY`
  - [ ] `MAILGUN_SECRET`
  - [ ] `MOLLIE_KEY`
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
  - [ ] `ai-low`
  - [ ] `deliveries`
  - [ ] `billing`
- [ ] Configure failed job notifications
- [ ] Test job processing end-to-end

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

