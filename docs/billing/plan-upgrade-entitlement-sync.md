# Plan Upgrade + Entitlement Sync Notes

## Current Source of Truth
- Workspace feature enforcement reads from `workspace_entitlements` first and falls back to `plan_features` for the active subscription.
- Draft Compare and Hybrid gates are now resolved through `App\Services\Billing\PlanEntitlementService`.

## Root Cause of Stale State
- Immediate paid upgrades could leave stale workspace entitlement snapshots visible during/after plan transitions.
- Entitlement refresh logic did not prune old plan-sourced keys when plan capabilities changed.
- Refresh runs were not consistently propagated across all organization workspaces.

## Fix Points
1. `App\Services\PlanChangeService`
   - Immediate paid upgrades now stay in `pending_payment` until provider payment is paid.
   - Plan metadata for payment intents now includes safe upgrade context.
   - Entitlements are refreshed via a dedicated action after apply.
2. `App\Services\Entitlements\EntitlementRefreshService`
   - Refresh covers all organization workspaces.
   - Removes stale plan-sourced keys not present in current derived capability set.
3. `App\Services\Entitlements\FeatureGate`
   - Ignores plan-sourced workspace entitlement rows bound to an old plan id.
4. `App\Services\Billing\PlanEntitlementService`
   - Single resolver for compare/hybrid capability checks.
5. `App\Actions\Billing\RefreshWorkspaceEntitlements`
   - Central post-change refresh path that refreshes and invalidates entitlement snapshots.

## UI Consistency
- Billing now shows a pending immediate-upgrade state and does not imply activation until payment confirmation.

## Status Integrity Follow-up
- `subscription_plan_changes.status` is now backed by `App\Enums\Billing\SubscriptionPlanChangeStatus`.
- Transition rules are enforced at application level through model lifecycle checks and explicit `transitionTo(...)`.
- DB-level enum/check constraints were not added yet to avoid risky cross-engine schema changes on existing production databases; app-level enforcement is now authoritative and covered by tests.

## Upgrade Polling Endpoint
- New authenticated endpoint: `GET /api/workspaces/{workspace}/billing/upgrade-status`
- Route name: `app.api.billing.upgrade-status`
- Returns authoritative status, polling flags, plan identities, and effective entitlements (`compare_max_models`, `hybrid_drafts_enabled`, `monthly_credits`).
