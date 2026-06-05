# Draft Compare Phase 17 Maintenance Notes

## Scope
This phase intentionally avoided product-scope changes and focused on maintainability and consistency of the existing Draft Compare implementation.

## Cleanup Applied

### 1. Duplicated metrics resolution was centralized
- Added `App\Services\DraftComparison\DraftComparisonMetricResolver`.
- This service now owns:
  - score-row to metrics conversion (`draft_comparison_scores` first),
  - legacy fallback normalization (`draft_comparison_items.metrics`),
  - provider/model composite key normalization.
- Consumers updated:
  - `DraftComparisonSummaryBuilder`
  - `DraftComparisonWinnerService`
  - `AppDraftComparisonsController` (variant row view-model path)

Why:
- removes repeated metric-mapping code paths,
- prevents drift between winner/summary/UI scoring views,
- keeps precedence explicit in one place.

### 2. Controller-to-service consistency improved
- `AppDraftComparisonsController` now uses the shared resolver in the compare results composition path.
- This keeps controller presentation logic aligned with business-level metric precedence.

## Verification Notes

### Model relationships
- Existing draft/variant/comparison linkage relationships were retained and validated by current model tests.
- No relationship semantics changed.

### Queue safety and idempotency
- Existing unique-job behavior and credit settlement idempotency behavior retained.
- Finalization idempotency coverage already exists and remains passing.

### Status recalculation reliability
- Existing status aggregation logic in `DraftComparison` / `DraftComparisonVariant` unchanged.
- Existing status flow tests continue to pass.

### Migrations and indexes
- No migration behavior was changed in this phase.
- Existing Draft Compare schema/index strategy remains intact.

### UI consistency
- No UX redesign in this phase.
- UI data wiring now uses consistent metric derivation paths through the shared resolver.
