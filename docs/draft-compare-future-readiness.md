# Draft Compare Future Readiness Notes (Phase 20)

This phase keeps current behavior unchanged while adding light extension points for future product scope.

## Additive Extension Points Added

1. Compare scope metadata
- `compare_scope` is now normalized in compare requests and stored on comparison `meta`.
- Current UI uses `full_draft`.
- Reserved normalized scopes:
  - `full_draft`
  - `intro_only`
  - `headline_only`
  - `section_compare`
- Prompt snapshots and status/trust payloads now carry compare-scope context.

2. Tenant winner-weight override hook
- `DraftComparisonWinnerService` now resolves weights through a centralized path.
- Default source remains `config(credits.draft_compare.winner_weights)`.
- Optional workspace override is now supported via feature key:
  - `draft_compare_winner_weights` (JSON entitlement/plan feature).
- Recommendation payload now includes `weights_source` for transparency.

## Why This Is Future-Safe

- Intro/headline/section comparison can be introduced without schema rewrites by using `compare_scope` and scope-aware scoring logic later.
- Custom scoring weights per tenant can be enabled using existing entitlement plumbing, without controller-level plan hardcoding.
- Existing summary JSON structure already supports additive sections for:
  - human review workflow state,
  - export/share report metadata,
  - A/B recommendation payloads,
  - benchmark references,
  - CMS publish-compare metadata.

## Scope Not Added In This Phase

- No new user-facing scope selector yet.
- No new compare-by-section scoring implementation yet.
- No workflow/report/export subsystem added.
- No publish subsystem redesign.
