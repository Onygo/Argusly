# Pre-Launch Fix Plan

**Date**: 2026-03-13
**Status**: Active

---

## Priority Levels

| Level | Meaning | Timeline |
|-------|---------|----------|
| P0 | Launch Blocker | Fix before any production deployment |
| P1 | Critical | Fix within first week of launch |
| P2 | Important | Fix within first month |
| P3 | Nice to Have | Schedule for future sprint |

---

## P0 - Launch Blockers

### Fix 1: Apply Pending Migrations
**Finding**: PL-001
**Project**: Main App
**Effort**: 30 minutes
**Risk**: Low

**Steps**:
1. Backup staging database
2. Run `php artisan migrate` on staging
3. Verify all migrations applied: `php artisan migrate:status`
4. Test Draft Intelligence features
5. Test Laravel Connector sync
6. Apply to production on launch day

**Verification**:
```bash
php artisan migrate:status | grep -c "Yes" # Should show 193+
```

---

### Fix 2: Remove Debug Statements
**Finding**: PL-002
**Project**: Main App
**Effort**: 1-2 hours
**Risk**: Low

**Steps**:
1. Find all instances:
```bash
grep -rn "dd\|dump\|var_dump\|print_r" app resources/views --include="*.php" --include="*.blade.php"
```

2. Review each occurrence
3. Remove or replace with proper logging
4. Test affected views/controllers
5. Run test suite

**Known Locations** (16 files):
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/app/briefs/compare-show.blade.php`
- `app/Http/Requests/App/AbstractDraftComparisonSelectionRequest.php`
- `app/Services/ApiDocs/OpenApiGenerator.php`

---

### Fix 3: Create WordPress Uninstall Handler
**Finding**: WP-001
**Project**: WordPress Plugin
**Effort**: 2 hours
**Risk**: Medium (needs testing)

**Steps**:
1. Create `/wp-content/plugins/publishlayer/uninstall.php`:

```php
<?php
/**
 * PublishLayer Uninstall Handler
 *
 * Removes all plugin data when deleted via WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all options
$options = [
    'publishlayer_api_base',
    'publishlayer_site_token',
    'publishlayer_webhook_secret',
    'publishlayer_sslverify',
    'publishlayer_debug',
    'publishlayer_license_key_enc',
    'publishlayer_updater_last_error',
    'publishlayer_updater_enabled',
    'publishlayer_updater_client_secret',
    'publishlayer_logs',
];

foreach ($options as $option) {
    delete_option($option);
}

// Optionally delete posts (user should be warned)
$delete_content = apply_filters('publishlayer_delete_content_on_uninstall', false);

if ($delete_content) {
    $post_types = ['publishlayer_brief', 'publishlayer_draft'];
    foreach ($post_types as $post_type) {
        $posts = get_posts([
            'post_type' => $post_type,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }
    }
}

// Clear cron
wp_clear_scheduled_hook('publishlayer_poll_drafts');

// Clear transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%publishlayer%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_pl_%'");
```

2. Test on staging WordPress
3. Verify options are removed after uninstall
4. Update plugin version

---

### Fix 4: Verify Environment Secrets
**Finding**: PL-003
**Project**: Main App
**Effort**: 30 minutes
**Risk**: None

**Required Secrets**:
```env
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
MAILGUN_SECRET=...
MOLLIE_KEY=...
PUBLISHLAYER_ADMIN_KEY=...
```

**Verification Command**:
```bash
php artisan tinker --execute="
    \$keys = ['OPENAI_API_KEY', 'MAILGUN_SECRET', 'MOLLIE_KEY', 'PUBLISHLAYER_ADMIN_KEY'];
    foreach (\$keys as \$k) {
        echo \$k . ': ' . (env(\$k) ? 'SET' : 'MISSING') . PHP_EOL;
    }
"
```

---

### Fix 5: Configure Queue Monitoring
**Finding**: PL-004
**Project**: Main App
**Effort**: 2-4 hours
**Risk**: Low

**Steps**:
1. Install supervisor or systemd for queue workers
2. Configure worker for each queue:
   - `default` - General jobs
   - `ai-low` - Draft intelligence, analysis
   - `deliveries` - Content sync
   - `billing` - Credit operations

3. Set up failed job alerts:
   - Email notification on X failures/hour
   - Slack webhook for critical failures

4. Add admin dashboard widget showing queue health

**Sample Supervisor Config**:
```ini
[program:publishlayer-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/publishlayer-worker.log
```

---

## P1 - Critical (First Week)

### Fix 6: Add Integration Tests for Laravel Connector
**Finding**: LC-001
**Project**: Main App
**Effort**: 4-8 hours
**Status**: ✅ RESOLVED (2026-03-24)

**Test Scenarios** (all covered):
- ✅ Successful article sync - `LaravelConnectorSyncJobTest.php`
- ✅ Connection timeout handling - HTTP 500 mock in sync tests
- ✅ Auth failure handling - HTTP 422 tests
- ✅ Payload validation errors - Permanent failure tests
- ✅ Partial sync recovery - Retry backoff tests

**Existing Test Files**:
- `tests/Feature/Integrations/LaravelConnectorSyncJobTest.php`
- `tests/Feature/Integrations/LaravelConnectorDestinationConnectionTest.php`
- `tests/Feature/Content/LaravelConnectorPublishFlowTest.php`
- `tests/Feature/Api/LaravelConnectorDraftSeoPayloadTest.php`
- `tests/Unit/Integrations/LaravelConnectorPayloadFactoryTest.php`

---

### Fix 7: Complete Credit Quote System
**Finding**: PL-007
**Project**: Main App
**Effort**: 2-4 hours

**Steps**:
1. Complete action quote resolution in `CreditQuoteService.php`
2. Remove hardcoded fallbacks
3. Add tests for all action types
4. Verify quote matches charge

---

### Fix 8: Improve DeliverDraftJob Error Handling
**Finding**: PL-006
**Project**: Main App
**Effort**: 2-3 hours

**Steps**:
1. Add comprehensive error logging
2. Implement `failed()` method for all paths
3. Add webhook notifications for failures
4. Add admin notification for repeated failures

---

## P2 - Important (First Month)

### Fix 9: Replace Runtime Schema Checks
**Finding**: LC-002
**Project**: Laravel Connector
**Effort**: 3-4 hours
**Status**: ✅ RESOLVED (connector commit 080251d)

**Resolution**: Fixed in connector package via cached schema-state checks. Schema status is now cached after first check.

---

### Fix 10: Extract Fat Controller Methods
**Finding**: PL-005
**Project**: Main App
**Effort**: 8-16 hours

**Target Controllers**:
- `AppContentController.php`
- `AppDraftsController.php`

**Approach**:
1. Extract complex methods to action classes
2. Move business logic to services
3. Keep controllers thin (routing + validation + response)

---

### Fix 11: Document REST Endpoint Auth
**Finding**: WP-002
**Project**: WordPress Plugin
**Effort**: 1-2 hours

**Steps**:
1. Add code comments explaining `__return_true` usage
2. Document HMAC/Bearer auth in README
3. Add security considerations section

---

### Fix 12: Clean Up Legacy Routes
**Finding**: PL-008
**Project**: Main App
**Effort**: 2-3 hours

**Steps**:
1. Add deprecation warnings to legacy routes
2. Update any remaining references
3. Schedule removal in next major version

---

## P3 - Nice to Have (Future)

### Fix 13: Add Circuit Breaker for LLM Calls
**Effort**: 4-8 hours

Implement circuit breaker pattern for external API calls to prevent cascade failures.

---

### Fix 14: Add Retry Logic to Connector Sync
**Finding**: LC-005
**Effort**: 3-4 hours
**Status**: ✅ RESOLVED (connector commit 080251d)

**Resolution**: Fixed in connector package via retrying sync transactions with proper database transaction management.

---

### Fix 15: Improve Error Messages
**Finding**: LC-006
**Effort**: 2-3 hours

Add expected vs actual values in error messages. Include config file references.

---

### Fix 16: Remove /ping Endpoint or Add Auth
**Finding**: WP-003
**Effort**: 1 hour

Either reduce information returned or add optional auth.

---

## Testing Checklist

After completing P0 fixes, run:

### Main App
```bash
php artisan test
php artisan migrate:status
php artisan route:list | grep -c "GET\|POST"
```

### Laravel Connector
```bash
cd publishlayer-laravel-connector
composer test
```

### WordPress Plugin
```bash
# Manual testing in WordPress admin
# Verify all admin pages load
# Test brief creation
# Test draft receipt
# Test KB publishing
```

---

## Rollback Plan

### If Migration Fails
```bash
php artisan migrate:rollback --step=9
```

### If Debug Removal Breaks Something
```bash
git checkout -- app/ resources/views/
```

### If Queue Workers Fail
```bash
php artisan queue:work --once  # Process one job manually
php artisan queue:restart       # Restart workers
```

---

**Document Status**: Active
**Last Updated**: 2026-03-24

## Resolution Summary (Laravel Connector)
| Fix | Finding | Status |
|-----|---------|--------|
| Fix 6 | LC-001 | ✅ Resolved - Integration tests exist |
| Fix 9 | LC-002 | ✅ Resolved - Connector commit 080251d |
| Fix 14 | LC-005 | ✅ Resolved - Connector commit 080251d |
| Fix 15 | LC-006 | Pending - External connector package |
