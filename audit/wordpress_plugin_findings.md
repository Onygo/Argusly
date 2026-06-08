# WordPress Plugin Audit Findings

**Date**: 2026-03-13
**Project**: Argusly WordPress Plugin
**Location**: `/Users/ricardohagens/Sites/_project_argusly/wordpress-argusly/wp-content/plugins/argusly`
**Version**: 0.1.12

---

## Finding Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1 |
| HIGH | 1 |
| MEDIUM | 3 |
| LOW | 2 |

---

## CRITICAL Findings

### WP-001: No Uninstall Cleanup Handler
**Severity**: CRITICAL
**Area**: Plugin Lifecycle
**Status**: Must Fix Before Launch

**Evidence**:
- No `uninstall.php` file exists
- No `register_uninstall_hook()` in main plugin file

**Impact**: When users delete the plugin, the following data persists:
- All database options (API base, site token, webhook secret, license key)
- All custom post types (briefs, drafts, knowledge base articles)
- All post metadata (`_pl_*` keys)
- All scheduled cron jobs
- All transients

**User Impact**: Data cleanup burden on users. Privacy concerns. Database bloat.

**Recommendation**: Create `uninstall.php`:
```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

// Delete options
$options = [
    'argusly_api_base',
    'argusly_site_token',
    'argusly_webhook_secret',
    'argusly_sslverify',
    'argusly_debug',
    'argusly_license_key_enc',
    'argusly_updater_last_error',
    'argusly_updater_enabled',
    'argusly_updater_client_secret',
    'argusly_logs',
];
foreach ($options as $opt) {
    delete_option($opt);
}

// Delete posts (optional - ask user)
$post_types = ['argusly_brief', 'argusly_draft', 'kb_article'];
foreach ($post_types as $pt) {
    $posts = get_posts(['post_type' => $pt, 'numberposts' => -1]);
    foreach ($posts as $post) {
        wp_delete_post($post->ID, true);
    }
}

// Clear transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pl_%'");
```

---

## HIGH Findings

### WP-002: REST Endpoints Use __return_true Permission Callback
**Severity**: HIGH
**Area**: Security
**Status**: Review Before Launch

**Evidence**:
`includes/Api/RestRoutes.php` (lines 11, 17, 23, 29):
```php
'permission_callback' => '__return_true'
```

All REST endpoints:
- `POST /argusly/v1/webhook/draft`
- `POST /argusly/v1/posts/{id}/featured-image`
- `GET /argusly/v1/ping`
- `POST /argusly/v1/posts`

**Impact**: WordPress REST API permission system bypassed. Security scanners flag as vulnerable. Custom auth logic is sole protection.

**Mitigation Already In Place**:
- Webhook endpoints verify HMAC signatures
- Posts endpoint requires bearer token
- All use `hash_equals()` for constant-time comparison

**Recommendation**:
1. Add code comments explaining why `__return_true` is used
2. Consider custom permission callback that validates auth headers
3. Document authentication model for security auditors

---

## MEDIUM Findings

### WP-003: /ping Endpoint Unauthenticated
**Severity**: MEDIUM
**Area**: Information Disclosure
**Status**: Should Fix Soon

**Evidence**:
`includes/Api/DirectPostService.php` (lines 10-22):

Returns without authentication:
- `ok: true`
- `plugin: 'argusly'`
- `plugin_version: 0.1.12`
- `site_url: [WordPress URL]`
- `wp_version: [version]`
- `blog_id: [id]`

**Impact**: Minor information disclosure. Version fingerprinting possible.

**Recommendation**: Either:
1. Add optional bearer token authentication
2. Document as intentionally public for health checks
3. Reduce information returned (just `ok: true`)

---

### WP-004: Debug Logging to error_log
**Severity**: MEDIUM
**Area**: Operations
**Status**: Acceptable

**Evidence**:
- Line 179: `error_log($line)` - Conditional on debug enabled
- `includes/Support/Logger.php:34`: `error_log($line)` - Conditional

**Mitigation**: Both are gated behind `is_debug_enabled()` check.

**Impact**: If debug accidentally enabled in production, sensitive data may be logged.

**Recommendation**:
1. Add warning in admin UI when debug is enabled
2. Consider log sanitization for sensitive fields
3. Auto-disable debug after X hours

---

### WP-005: Unreachable Code After Early Return
**Severity**: MEDIUM
**Area**: Code Quality
**Status**: Should Fix Soon

**Evidence**:
`argusly.php` lines 2033-2048 after `handle_create_brief()` early return on line 2031.

**Impact**: Dead code. Maintenance confusion.

**Recommendation**: Remove unreachable code block.

---

## LOW Findings

### WP-006: TODO Comment in CreditsClient
**Severity**: LOW
**Area**: Code Quality
**Status**: Can Wait

**Evidence**:
`includes/Api/CreditsClient.php` line 95:
```php
// TODO: remove fallback values after all installs use server-side quote endpoint.
```

Has hardcoded fallback credit requirements.

**Impact**: Inconsistent credit quoting between old and new installs.

**Recommendation**: Track server-side quote endpoint rollout. Remove fallback when 100% coverage.

---

### WP-007: Password Field Shows Placeholder Not Value
**Severity**: LOW
**Area**: UX
**Status**: Informational (Correct Behavior)

**Evidence**:
License key field shows empty placeholder instead of encrypted value on edit.

**Impact**: None - this is actually correct security behavior.

**Recommendation**: None needed. This is a strength.

---

## Security Assessment

### Input Sanitization: 9/10
- 154+ uses of WordPress sanitization functions
- `sanitize_text_field()` for text inputs
- `wp_kses_post()` for HTML content
- `esc_url_raw()` for URLs

### Output Escaping: 9/10
- 157+ uses of `esc_html()`
- Proper `esc_attr()` for attributes
- `esc_url()` for links

### Capability Checks: 9/10
- All admin pages check `manage_options` or `edit_posts`
- All admin post actions verify capabilities
- AJAX handlers check capabilities

### Nonce Verification: 9/10
- All admin POST actions use `check_admin_referer()`
- AJAX uses `check_ajax_referer()`
- Forms include `wp_nonce_field()`

### SQL Injection Prevention: 10/10
- NO direct SQL queries found
- All queries via WordPress APIs
- `WP_Query` with sanitized parameters

### Data Encryption: 8/10
- License key AES-256-CBC encrypted
- Random IV per encryption
- Key derived from WordPress salts

---

## Plugin Structure

### Main File
- `argusly.php` - 2,000+ lines
- Boot sequence at end of file
- Class-based organization (`Argusly_WP`)

### Includes Structure
```
includes/
├── Admin/
│   ├── Menu.php
│   ├── SettingsRegistrar.php
│   └── CreateBrief/
├── Api/
│   ├── RestRoutes.php
│   ├── WebhookService.php
│   ├── DirectPostService.php
│   ├── FeaturedImageService.php
│   └── CreditsClient.php
├── Cron/
│   └── PollDraftsJob.php
├── Domain/
│   └── PostTypeRegistrar.php
├── Support/
│   ├── Logger.php
│   └── HttpClient.php
└── Update/
    └── Updater.php
```

### Custom Post Types
- `argusly_brief` - Brief documents
- `argusly_draft` - Draft content
- `kb_article` - Knowledge Base articles (configurable)

---

## Webhook Security

### HMAC Signature Verification
**File**: `includes/Api/WebhookService.php` (lines 301-317)

```php
private static function verifySignature($secret, $rawBody, $timestamp, $signature) {
    if ($timestamp === '' || $signature === '') return false;

    $tsInt = (int) $timestamp;
    $now = time();
    if (abs($now - $tsInt) > 300) return false;  // 5-minute window

    $base = $timestamp . '.' . $rawBody;
    $expected = hash_hmac('sha256', $base, $secret);

    return hash_equals($expected, $signature);  // Constant-time
}
```

**Assessment**: Excellent implementation
- Constant-time comparison prevents timing attacks
- 5-minute replay window
- HMAC-SHA256 for integrity

---

## Content Sync Flow

### Primary: Webhook from Argusly
1. Receives POST to `/argusly/v1/webhook/draft`
2. Validates HMAC signature
3. Parses and sanitizes payload
4. Finds or creates draft post
5. Updates metadata
6. Sends acknowledgment back

### Secondary: Cron Polling
1. Hourly job fetches ready drafts
2. Checks for duplicates
3. Processes via webhook handler
4. Logs results

### Error Handling
- Comprehensive logging with correlation IDs
- Appropriate HTTP status codes
- Admin-visible error messages

---

## Activation/Deactivation

### on_activate()
- Schedules hourly cron job
- Seeds webhook secret if missing
- Registers post types
- Flushes rewrite rules
- Registers domain with Argusly

### on_deactivate()
- Unschedules cron job
- Flushes rewrite rules
- Does NOT remove data

---

## Recommendations

### Before Launch (Must Fix)
1. Create `uninstall.php` with cleanup logic
2. Add comments explaining REST permission callbacks
3. Remove unreachable code (lines 2033-2048)

### After Launch (Should Fix)
1. Add optional auth to `/ping` endpoint
2. Add warning banner when debug enabled
3. Remove CreditsClient TODO fallback
4. Add email notification for persistent sync failures

---

## Security Score: 7.5/10

| Category | Score |
|----------|-------|
| Input/Output Handling | 9/10 |
| Authentication | 8.5/10 |
| Authorization | 9/10 |
| Data Protection | 8/10 |
| Error Handling | 8/10 |
| SQL Security | 10/10 |
| Uninstall/Cleanup | 2/10 |

**Note**: Score reduced primarily due to missing uninstall cleanup.

---

**Report Status**: Complete
**Next Steps**: See prelaunch_fix_plan.md
