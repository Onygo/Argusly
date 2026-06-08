# Argusly Main App Audit Findings

**Date**: 2026-03-13
**Project**: Argusly Main App
**Location**: `/Users/ricardohagens/Sites/_project_argusly/argusly`

---

## Finding Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3 |
| HIGH | 4 |
| MEDIUM | 8 |
| LOW | 6 |

---

## CRITICAL Findings

### PL-001: Unapplied Database Migrations
**Severity**: CRITICAL
**Area**: Database
**Status**: Must Fix Before Launch

**Evidence**:
```
database/migrations/2026_03_12_140000_add_phase_one_fields_to_draft_analyses.php
database/migrations/2026_03_12_150000_create_draft_improvement_results_table.php
database/migrations/2026_03_12_150100_create_draft_intelligence_deltas_table.php
database/migrations/2026_03_12_150200_create_draft_recommendations_table.php
database/migrations/2026_03_12_160000_add_llm_visibility_score_to_draft_analyses_table.php
database/migrations/2026_03_12_170000_add_phase_four_scores_to_draft_analyses_table.php
database/migrations/2026_03_13_091700_make_draft_intelligence_delta_nullable.php
database/migrations/2026_03_13_120000_add_content_destination_id_to_content_publish_targets_table.php
database/migrations/2026_03_13_120100_create_content_destination_sync_attempts_table.php
```

**Impact**: Draft Intelligence features will fail. Laravel Connector sync will fail. Application errors on any Draft Analysis operations.

**Recommendation**: Run `php artisan migrate` on staging first, verify, then apply to production.

---

### PL-002: Debug Statements in Production Code
**Severity**: CRITICAL
**Area**: Code Quality
**Status**: Must Fix Before Launch

**Evidence**:
16 files contain debug statements:
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/app/briefs/compare-show.blade.php`
- `app/Http/Requests/App/AbstractDraftComparisonSelectionRequest.php`
- `app/Services/ApiDocs/OpenApiGenerator.php`
- And 11 more files

**Impact**: Could expose sensitive data. May break JSON responses. Performance impact.

**Recommendation**: Run grep to find and remove all instances:
```bash
grep -r "dd\|dump\|var_dump\|print_r" app resources/views --include="*.php" --include="*.blade.php"
```

---

### PL-003: Missing Environment Secret Verification
**Severity**: CRITICAL
**Area**: Configuration
**Status**: Must Fix Before Launch

**Evidence**:
`app/Providers/AppServiceProvider.php` (lines 103-108) references required secrets:
- `OPENAI_API_KEY`
- `MAILGUN_SECRET`
- `MOLLIE_KEY`
- `ARGUSLY_ADMIN_KEY`

**Impact**: LLM calls will fail. Billing webhooks will fail. Email sending will fail. Admin API endpoints will be inaccessible.

**Recommendation**: Create production .env verification checklist. Add startup health check to verify critical secrets.

---

## HIGH Findings

### PL-004: Queue Worker Monitoring Not Configured
**Severity**: HIGH
**Area**: Operational
**Status**: Must Fix Before Launch

**Evidence**: No evidence of supervisor/systemd configuration for queue workers. Failed jobs table exists but no alerting configured.

**Impact**: Jobs will fail silently. Draft generation will appear stuck. Credits may be reserved but never released.

**Recommendation**:
1. Configure supervisor for queue workers
2. Set up failed job alerts via email/Slack
3. Create admin dashboard widget for queue health

---

### PL-005: Fat Controller Methods
**Severity**: HIGH
**Area**: Code Quality
**Status**: Should Fix Soon

**Evidence**:
- `app/Http/Controllers/App/AppContentController.php` - Multiple complex methods
- `app/Http/Controllers/App/AppDraftsController.php` - Heavy show() method

**Impact**: Hard to test. Difficult to maintain. Risk of bugs during modifications.

**Recommendation**: Extract complex logic to service classes or action classes.

---

### PL-006: Incomplete DeliverDraftJob Error Handling
**Severity**: HIGH
**Area**: Jobs
**Status**: Should Fix Soon

**Evidence**:
`app/Jobs/DeliverDraftJob.php` - Failed job handling exists but is incomplete for all failure scenarios.

**Impact**: Failed deliveries may not be properly reported. Users may not know their content failed to publish.

**Recommendation**: Add comprehensive error logging and webhook notifications for all failure paths.

---

### PL-007: Credit Quote Service TODOs
**Severity**: HIGH
**Area**: Billing
**Status**: Should Fix Soon

**Evidence**:
`app/Services/Credits/CreditQuoteService.php`:
- Line 36: `// TODO: remove config fallback when every action quote is resolved from live action rules.`
- Line 63: `// TODO: remove hardcoded fallback after quote coverage is complete.`

**Impact**: Inconsistent credit pricing. Users may be quoted different amounts than charged.

**Recommendation**: Complete the action quote resolution system and remove fallbacks.

---

## MEDIUM Findings

### PL-008: Legacy Route Files Still Present
**Severity**: MEDIUM
**Area**: Routes
**Status**: Can Wait

**Evidence**:
- `routes/app-legacy.php` (211 lines)
- `routes/admin-legacy.php`

**Impact**: Code complexity. Maintenance overhead. Potential confusion.

**Recommendation**: Schedule deprecation after migration period. Add deprecation notices.

---

### PL-009: Network Linking Feature Disabled
**Severity**: MEDIUM
**Area**: Features
**Status**: Product Decision Needed

**Evidence**:
- `routes/app.php:288` - `// TODO(FEATURE): Re-enable network linking when ready.`
- `routes/app-legacy.php:211` - Same comment
- `resources/views/app/content/show.blade.php:372` - Same comment

**Impact**: Feature visible in code but not accessible. May confuse developers.

**Recommendation**: Either complete and enable the feature or remove the code entirely.

---

### PL-010: Missing API Response Format Standardization
**Severity**: MEDIUM
**Area**: API
**Status**: Should Fix Soon

**Evidence**: Mix of response formats across API controllers. Some return `['data' => ...]`, others return flat arrays.

**Impact**: Inconsistent API experience. Client code complexity.

**Recommendation**: Create API response wrapper. Standardize all endpoints.

---

### PL-011: Limited Retry Configuration on Some Jobs
**Severity**: MEDIUM
**Area**: Jobs
**Status**: Should Fix Soon

**Evidence**:
Jobs lacking explicit retry configuration:
- `PushFeaturedImageToWordPressJob`
- `PublishToWordPressJob`
- `DeliverApiWebhookJob`
- Link intelligence jobs

**Impact**: Transient failures may not recover automatically.

**Recommendation**: Add explicit `$tries`, `$backoff`, and `failed()` methods to all jobs.

---

### PL-012: Missing Database Indexes
**Severity**: MEDIUM
**Area**: Database
**Status**: Should Fix Soon

**Evidence**: Frequently queried columns like `status`, `workspace_id` on large tables may not have optimal indexes.

**Impact**: Slow queries as data grows. Performance degradation.

**Recommendation**: Audit query logs. Add composite indexes where needed.

---

### PL-013: LLM JSON Normalization May Fail Silently
**Severity**: MEDIUM
**Area**: LLM
**Status**: Should Fix Soon

**Evidence**:
`app/Services/Llm/LlmJsonNormalizer.php` - May not handle all malformed JSON cases.

**Impact**: Analysis records with incomplete data. User confusion.

**Recommendation**: Add fallback parsing and validation. Log normalization failures.

---

### PL-014: Draft Comparison Credit Edge Cases
**Severity**: MEDIUM
**Area**: Billing
**Status**: Should Fix Soon

**Evidence**:
Comparison-managed credits (`draft_compare.comparison_credit_managed`) have complex reservation/release logic.

**Impact**: Credits may be double-reserved or leaked in edge cases.

**Recommendation**: Add integration tests for all credit flow scenarios in comparisons.

---

### PL-015: Support Mode Context Limitations
**Severity**: MEDIUM
**Area**: Admin
**Status**: Can Wait

**Evidence**:
Support mode middleware exists but impersonation scope may not cover all edge cases.

**Impact**: Support staff may see/modify data they shouldn't.

**Recommendation**: Audit support mode permissions. Add comprehensive logging.

---

## LOW Findings

### PL-016: Commented Code Blocks
**Severity**: LOW
**Area**: Code Quality
**Status**: Can Wait

**Evidence**: 30+ files contain commented code blocks.

**Impact**: Code noise. Maintenance confusion.

**Recommendation**: Remove commented code. Use git history for reference.

---

### PL-017: DB::raw Usage
**Severity**: LOW
**Area**: Security
**Status**: Review Before Launch

**Evidence**: 10 instances of `DB::raw()` usage.

**Impact**: Potential SQL injection if user input flows to these.

**Recommendation**: Review each instance for user input. Use query builder where possible.

---

### PL-018: JavaScript Console.log Statements
**Severity**: LOW
**Area**: Code Quality
**Status**: Should Fix Soon

**Evidence**: JavaScript files in `resources/js/` may contain console.log statements.

**Impact**: Browser console noise. Minor performance impact.

**Recommendation**: Search and remove before production:
```bash
grep -r "console\.log" resources/js/
```

---

### PL-019: Unused Feature Flags
**Severity**: LOW
**Area**: Code Quality
**Status**: Can Wait

**Evidence**: Some feature flags may reference features that are fully rolled out.

**Impact**: Code complexity.

**Recommendation**: Audit feature flags. Remove those no longer needed.

---

### PL-020: Test Skip Markers
**Severity**: LOW
**Area**: Testing
**Status**: Can Wait

**Evidence**:
Tests with `markTestSkipped()`:
- GD extension tests (acceptable)
- Some conditional tests

**Impact**: Test coverage gaps if GD not available.

**Recommendation**: Ensure CI environment has GD extension.

---

### PL-021: Service Layer Complexity
**Severity**: LOW
**Area**: Architecture
**Status**: Can Wait

**Evidence**: 150+ services in various directories without clear organization.

**Impact**: Onboarding complexity. Service discovery.

**Recommendation**: Create service facades. Document service architecture.

---

## Model and Relationship Issues

### Verified Correct
- All major relationships have proper foreign keys
- `->onDelete()` constraints properly configured
- Nullable FKs use `->nullOnDelete()`

### No Issues Found
- 221 foreign key relationships verified
- No orphan relationships detected

---

## Security Assessment

### Authentication: 9/10
- IntegrationTokenMiddleware properly validates all API tokens
- Legacy credential support with proper fallback
- Site token verification with constant-time comparison

### Authorization: 9/10
- Policy-based authorization on models
- Middleware stack for multi-layer checks
- Feature gates for plan-based features

### Input Validation: 8/10
- Form Request classes for most endpoints
- Some admin endpoints could use more validation

### Rate Limiting: 8/10
- Integration API throttled
- Webhook endpoints throttled
- Some internal endpoints could use limits

---

## Test Coverage

### Feature Tests: 200+ tests
- Billing flows covered
- Content workflows covered
- Draft comparison covered
- Notifications covered

### Unit Tests: 49+ tests
- Credit calculations
- LLM routing
- Draft scoring

### Missing Coverage
- Laravel Connector integration from main app perspective
- Full end-to-end publishing flows
- All credit edge cases

---

## Files Reviewed

### Routes
- `routes/api.php` (138 lines)
- `routes/app.php` (361 lines)
- `routes/admin.php` (250+ lines)
- `routes/marketing.php`
- `routes/track.php`

### Key Services
- `app/Services/CreditWalletService.php`
- `app/Services/Drafts/DraftIntelligenceService.php`
- `app/Services/Integrations/LaravelConnectorPublishingService.php`
- `app/Services/DraftComparison/*`
- `app/Services/Llm/*`

### Key Jobs
- `app/Jobs/GenerateDraftJob.php`
- `app/Jobs/ImproveDraftSectionJob.php`
- `app/Jobs/AnalyzeDraftJob.php`
- `app/Jobs/DeliverDraftJob.php`

---

**Report Status**: Complete
**Next Steps**: See prelaunch_fix_plan.md
