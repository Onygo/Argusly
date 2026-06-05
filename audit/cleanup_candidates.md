# PublishLayer Cleanup Candidates

**Date**: 2026-03-13
**Status**: Ready for Review

This document lists safe cleanup candidates that can be removed or refactored without risk to functionality.

---

## Safe to Remove Immediately

### 1. Debug Statements (16 files)

**Risk**: None (improves security)
**Effort**: 1-2 hours

| File | Type | Line(s) |
|------|------|---------|
| `resources/views/layouts/admin.blade.php` | dd/dump | Various |
| `resources/views/layouts/app.blade.php` | dd/dump | Various |
| `resources/views/app/briefs/compare-show.blade.php` | dd/dump | Various |
| `app/Http/Requests/App/AbstractDraftComparisonSelectionRequest.php` | dd/dump | Various |
| `app/Services/ApiDocs/OpenApiGenerator.php` | dd/dump | Various |

**Command to find all**:
```bash
grep -rn "dd\|dump\|var_dump\|print_r" app resources/views --include="*.php" --include="*.blade.php"
```

---

### 2. Unreachable Code - WordPress Plugin

**File**: `publishlayer.php`
**Lines**: 2033-2048
**Risk**: None
**Effort**: 5 minutes

Code block after early return in `handle_create_brief()` that can never execute.

---

### 3. Legacy Route Files

**Files**:
- `routes/app-legacy.php` (211 lines)
- `routes/admin-legacy.php`

**Risk**: Low (verify no active references first)
**Effort**: 30 minutes

**Verification before removal**:
```bash
grep -r "app-legacy\|admin-legacy" routes config
```

---

### 4. TODO/FIXME Comments with Completed Work

**Risk**: None
**Effort**: 1 hour

Review and remove completed TODO comments:
```bash
grep -rn "TODO\|FIXME" app --include="*.php" | head -50
```

---

## Safe to Refactor

### 5. Fat Controller Methods

**Files**:
- `app/Http/Controllers/App/AppContentController.php`
- `app/Http/Controllers/App/AppDraftsController.php`

**Risk**: Low (with tests)
**Effort**: 8-16 hours

Extract to action classes:
- `ShowDraftAction`
- `UpdateDraftStatusAction`
- `PublishContentAction`

---

### 6. Duplicate Service Logic

**Area**: Draft comparison credit handling
**Risk**: Medium
**Effort**: 4-8 hours

Consolidate credit reservation logic into single service.

---

### 7. Schema Runtime Checks - Laravel Connector

**Count**: 28 instances
**Files**: Various controllers and services
**Risk**: Low
**Effort**: 3-4 hours

Replace with:
1. Install-time schema verification
2. Config-cached schema status
3. Remove runtime `Schema::hasTable` calls

---

## Commented Code Blocks (30+ files)

**Risk**: None
**Effort**: 2-3 hours

Search pattern:
```bash
grep -rn "^[[:space:]]*//.*{" app --include="*.php" | head -30
```

Remove commented code blocks - use git history for reference if needed.

---

## Unused Configuration

### Config Files to Audit

| File | Check |
|------|-------|
| `config/draft_compare.php` | Verify all keys used |
| `config/draft_intelligence.php` | Verify all keys used |
| `config/llm.php` | Verify all providers active |

**Command**:
```bash
# Find config keys
grep -oh "config('[^']*'" app --include="*.php" -r | sort | uniq -c | sort -rn
```

---

## Unused Dependencies

**Command to check**:
```bash
composer why-not unused-package-name
```

Review `composer.json` for packages not imported anywhere.

---

## Database Cleanup

### Potential Orphan Records

After launch, monitor for:
- `drafts` without `briefs`
- `content_destinations` without `contents`
- `credit_transactions` with status 'reserved' older than 24h

**Cleanup Query** (run after investigation):
```sql
-- Example: Find orphan draft analyses
SELECT da.id FROM draft_analyses da
LEFT JOIN drafts d ON da.draft_id = d.id
WHERE d.id IS NULL;
```

---

## JavaScript Cleanup

### Console.log Statements

**Command**:
```bash
grep -rn "console\.log" resources/js --include="*.js" --include="*.vue"
```

Remove before production.

---

## Test Cleanup

### Skipped Tests

Review tests with `markTestSkipped()`:
- Ensure CI environment has required extensions (GD)
- Remove skip markers where possible

---

## Feature Flag Cleanup

After full rollout, remove feature flags for:
- Features at 100% rollout
- Features launched > 30 days ago

---

## Cleanup Priority

| Priority | Item | Effort | Impact |
|----------|------|--------|--------|
| P0 | Debug statements | 1-2h | Security |
| P0 | Unreachable WP code | 5min | Clarity |
| P1 | Legacy routes | 30min | Maintenance |
| P1 | Console.log | 30min | Performance |
| P2 | Fat controllers | 8-16h | Testability |
| P2 | Schema checks | 3-4h | Performance |
| P3 | Commented code | 2-3h | Clarity |
| P3 | Config audit | 1-2h | Maintenance |

---

## Safe Cleanup Checklist

Before removing any code:

- [ ] Search for all references (`grep -r "pattern" .`)
- [ ] Check git blame for context
- [ ] Verify tests still pass
- [ ] Check for dynamic references (string concatenation)
- [ ] Review related config files
- [ ] Test affected features manually

---

**Document Status**: Complete
**Next Steps**: Begin with P0 items, then P1 after launch

