# Workspace Credits And Site Allocations

Argusly now treats credits as a two-layer model:

- `workspace_credit_wallets` and `workspace_credit_transactions` are the financial source of truth.
- `site_credit_allocations` are budget assignments inside a workspace.
- `site_credit_allocation_buckets` are the site-scoped spend buckets that map allocated site budget back to workspace credit sources.
- `credit_reservations` and usage flows remain site-scoped, but they now update both the site allocation and the workspace wallet.

Important boundaries:

- Subscription grants, pack purchases, refunds, and manual adjustments land on the workspace wallet first.
- Sites can only spend credits that are currently allocated to that site.
- Unallocated workspace credits stay visible in the workspace pool until an admin allocates them.
- Legacy `credit_wallets` and `credit_ledger_entries` still exist as compatibility read models for site-scoped flows and source-bucket consumption.

Current migration strategy:

1. Backfill workspace wallets from the sum of existing site wallets.
2. Backfill site allocations from existing site wallet balances.
3. Backfill `site_credit_allocation_buckets` from legacy site ledger buckets and zero out their already-allocated workspace bucket remainder.
4. Keep legacy site wallets in sync while new reads and writes move to the workspace wallet plus allocation model.

Temporary compatibility layer:

- `credit_wallets` still mirror site-level cached balances for older site-scoped consumers and legacy ids stored on operational models.
- `credit_ledger_entries` still exist as a compatibility/event mirror, but runtime FIFO allocation, reclaim, and expiry now read from `workspace_credit_transactions` plus `site_credit_allocation_buckets`.
- `CreditWalletService` remains the main facade so older billing and usage paths can move over incrementally.
- New code should treat `workspace_credit_wallets` plus `workspace_credit_transactions` as the financial truth and `site_credit_allocations` as the spend guard.

Regeneration / operational notes:

- Existing billing commands continue to work through `CreditWalletService`.
- Workspace grants can auto-allocate to a single active site for backwards compatibility.
- Multi-site workspaces keep new credits unallocated until a workspace admin assigns them.
