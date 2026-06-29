# Agentic Opportunity Bridge Writer

Phase 3D adds a guarded bridge writer for selected existing `AgenticMarketingOpportunity` rows. It is dry-run-first, feature-flagged for apply and not called by default app flows.

## Command

```bash
php artisan mos:link-agentic-opportunities
php artisan mos:link-agentic-opportunities --apply
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

Dry-run works regardless of the feature flag. Apply requires:

- `--apply`
- `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER=true`

The command reports inspected, would-create, would-link, created, linked, already-linked, duplicate-risk, execution-blocked, missing-context, blocked and failed counts, plus skipped reasons and canonical id samples.

## Service Contract

`AgenticOpportunityBridgeWriter` accepts:

- legacy `AgenticMarketingOpportunity`;
- optional existing canonical `Opportunity`;
- dry-run/apply intent;
- optional operator context.

It calls `AgenticOpportunityBridgeEligibilityService` and only considers `canonical_link_ready` and `signal_and_canonical_ready` rows. It blocks `missing_context`, `duplicate_risk`, `blocked` and `execution_blocked` rows.

## Write Rules

- Existing bridge rows are reported as `already_linked`.
- New canonical `Opportunity` rows are created only when Phase 3B preview fields are complete and no safe existing row exists.
- The writer sets `opportunities.agentic_marketing_opportunity_id`.
- The writer preserves workspace, site, content and objective context from the preview.
- Metadata/evidence preserve the legacy Agentic id, objective id, detector key, Agentic type/status, source-scoped dedupe key, payload snapshot and execution continuity note.
- No `OpportunitySignal` rows are created in Phase 3D.
- No Agentic opportunities, actions, action runs or execution pipelines are updated.

## Rollback

Disable the feature flag and stop invoking the command. If no downstream canonical consumers were enabled, clear `opportunities.agentic_marketing_opportunity_id` only for rows created or linked by this writer. Do not delete `agentic_marketing_opportunities` or execution records.

## Phase 3F Interaction With Signal Validation

Phase 3E signal promotion and Phase 3D bridge writing remain independent:

- bridge writer: optionally creates or links canonical `Opportunity` rows through `opportunities.agentic_marketing_opportunity_id`;
- signal promotion: optionally creates or updates canonical `OpportunitySignal` rows;
- signal validation: inspects promoted signals and existing canonical links without writing.

The Phase 3F validator may report that a promoted signal is already linked to a canonical opportunity through `opportunity_signal_links`. That link is created only by the existing `OpportunityIntelligenceEngine` normal run path, not by the validator and not by this bridge writer.

## Phase 3G Read-Model Interaction

`AgenticOpportunityCanonicalReadService` reads the bridge written by this Phase 3D writer through `opportunities.agentic_marketing_opportunity_id`. It enriches selected read-only display fields only when exactly one safe canonical row points to the legacy Agentic opportunity.

Phase 3G does not invoke `AgenticOpportunityBridgeWriter`, does not create missing bridges and does not repair duplicate bridges. Multiple linked canonical rows or workspace mismatches are reported as blocked read-model reasons and fall back to legacy Agentic fields.

## Phase 3H Action Dedupe Interaction

Phase 3H consumes bridge links only for diagnostics. `AgenticOpportunityActionSignatureService` treats `opportunities.agentic_marketing_opportunity_id` as the canonical-to-legacy bridge so linked legacy and canonical rows can produce the same canonical-equivalent action signature.

This phase does not call `AgenticOpportunityBridgeWriter`, does not create or repair bridge rows and does not add canonical action metadata to existing `AgenticMarketingAction` payloads. Multiple canonical rows linked to one legacy Agentic opportunity block safe signature inspection and are reported by `php artisan mos:inspect-agentic-action-dedupe`.

## Phase 3I Execution Continuity Interaction

Phase 3I uses bridge links only as read-only context. `AgenticOpportunityExecutionContinuityService` accepts a legacy `AgenticMarketingOpportunity`, resolves exactly one safe linked canonical `Opportunity` when available, and reports whether execution rows would break under canonical-parent-only lookup.

The bridge writer still must not update actions, action runs, execution pipelines, execution assets, approvals, feedback, audit logs or rollback snapshots. Any future writer that stores canonical opportunity ids in execution payloads must do so as additive metadata for new rows only, after the Phase 3I diagnostics show payload compatibility and route/parent dependencies are understood.
