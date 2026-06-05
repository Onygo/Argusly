## Credits & Draft Compare Billing Invariants

### Purpose

This doc defines the **contract** for how credits, reservations, and Draft Compare billing work. It should stay stable across refactors and provider changes. When you touch billing/credits, check changes against these invariants.

---

### Core concepts

- **Wallet**
  - `CreditWallet` holds cached totals per client site:
    - `balance_cached`: total unexpired “owned” credits.
    - `reserved_cached`: credits currently reserved for in‑flight operations.
    - `available = balance_cached - reserved_cached`.
  - Actual stock lives in `CreditLedgerEntry` rows:
    - Positive entries for allowances / pack purchases / refunds (bucket entries with `remaining`).
    - Negative entries for usage, releases, adjustments, expiry.

- **Buckets**
  - Buckets are `CreditLedgerEntry` rows with:
    - `source in ['included_plan', 'addon_pack']`
    - `amount > 0`, `remaining > 0`, and not expired.
  - Consumption policy:
    - Always consume from `included_plan` before `addon_pack`.
    - Within each source, consume the **soonest‑expiring** entries first.
    - Tie‑break by FIFO `created_at`.

- **Reservations**
  - `CreditReservation` models a **temporary hold** on credits for some context (draft, content image, comparison, etc.).
  - Reservation lifecycle:
    - `reserved` → `captured` (usage) **or** `released`/`expired` (no usage).
  - Reservation and ledger are linked via `reservation_ledger_entry_id`, `capture_ledger_entry_id`, `release_ledger_entry_id`.

- **Comparison‑level vs draft‑level credits**
  - **Draft Compare** reserves credits **once at comparison start**, then settles when all variants are terminal:
    - Reservation is managed by `CreditReservationService` via `DraftComparisonCreditManager`.
    - Per‑variant generation still goes through normal draft‑level charging (`GenerateDraftJob` + `CreditWalletService`), but the comparison reservation is the single source of truth for “how many credits were blocked for this run”.
  - **Draft / image generation** (non‑comparison or hybrid output) uses `CreditWalletService`:
    - Reserves on the individual draft/image.
    - Commits or releases when generation succeeds / is skipped / fails.
    - Also creates `CreditReservation` rows for admin visibility, but the public API is on `CreditWalletService`.

---

### Wallet invariants

These must hold in all externally observable states (after any transaction commits).

1. **Non‑negative totals**
   - `balance_cached >= 0`
   - `reserved_cached >= 0`
   - `available = balance_cached - reserved_cached >= 0`

2. **Consistency with buckets**
   - Let `bucket_remaining` = sum of `remaining` on all unexpired `included_plan` + `addon_pack` entries in `CreditLedgerEntry` for this wallet.
   - Then:
     - `bucket_remaining >= 0`
     - Over time, `balance_cached` must equal:
       - `(sum of all positive bucket amounts ever created) - (sum of all negative adjustments/usages/expiry amounts)`
     - `reserved_cached` must equal the sum of:
       - All active `CreditReservation.amount` for status `reserved`, **plus**
       - Any draft/image reservations represented only via `CreditWalletService` (for legacy/transition reasons).

3. **Single‑source updates**
   - No code path should mutate `balance_cached` or `reserved_cached` outside a `DB::transaction`.
   - Any method that changes wallet totals must:
     - Lock the wallet row with `lockForUpdate`.
     - Be idempotent by design or guarded by idempotency keys on the associated `CreditLedgerEntry` / `CreditReservation`.

---

### Reservation invariants

For any `CreditReservation`:

1. **Valid states**
   - `status ∈ {reserved, captured, released, expired}`.
   - `isFinalized()` is `true` iff `status ∈ {captured, released, expired}`.

2. **State transitions**
   - Allowed transitions:
     - `null → reserved` (on creation).
     - `reserved → captured` (capture).
     - `reserved → released` (explicit release).
     - `reserved → expired` (TTL expiry).
   - Forbidden transitions:
     - Changing from any finalized state (`captured`, `released`, `expired`) back to `reserved` or to another finalized state.

3. **Capture semantics**
   - `captureAmount` is **strictly positive** and `<= reserved amount`.
   - On capture:
     - Wallet:
       - `reserved_cached` is decremented by **the full reserved amount**.
       - `balance_cached` is decremented by `captureAmount` only.
     - Ledger:
       - A `TYPE_RELEASE` entry offsets the reservation for the full reserved amount.
       - A `TYPE_USAGE` entry charges `captureAmount`, with metadata:
         - `reserved_amount`
         - `captured_amount`
         - `unused_amount_released = reserved_amount - captured_amount`
     - Reservation:
       - `status = captured`
       - `captured_at` set
       - `capture_ledger_entry_id` / `release_ledger_entry_id` set
       - Metadata records `reserved_amount`, `captured_amount`, `unused_amount_released`.

4. **Release / expiry semantics**
   - On release or expiry (no actual usage):
     - Wallet:
       - `reserved_cached` is decremented by the reserved amount.
       - `balance_cached` is unchanged.
     - Ledger:
       - A `TYPE_RELEASE` entry offsets the reservation.
     - Reservation:
       - `status = released` or `expired`
       - `released_at` set
       - `release_ledger_entry_id` set
       - Reason / failure codes stored in metadata.

5. **Idempotency**
   - `reserve` is idempotent per `idempotency_key`:
     - Re‑calls return the same reservation without double‑reserving or changing wallet totals.
   - `capture` is idempotent:
     - Re‑calls on a captured reservation return the same object without additional ledger or wallet changes.
     - Calls on finalized (released/expired) reservations are rejected.
   - `release` / `expire` are idempotent:
     - Re‑calls on a released/expired reservation return the same object without additional ledger or wallet changes.
     - Calls on captured reservations are rejected.

---

### Draft & image charging invariants

These apply to `Draft` and `ContentImage` credit flows via `CreditWalletService`.

1. **Draft reservation**
   - A draft with `credit_status = reserved` represents:
     - Exactly one reservation of `credit_cost` credits in `reserved_cached`.
     - A `CreditLedgerEntry` of `TYPE_RESERVATION` linked via `credit_ledger_entry_id`.
     - A `CreditReservation` row linked to that ledger entry.
   - `reserveForDraft` must:
     - Be idempotent for the same draft (idempotency key `draft:{id}:reserve`).
     - Fail with `InsufficientCreditsException` if `available < credit_cost`.

2. **Draft commit**
   - `commitUsageForDraft` is only valid when:
     - `credit_status = reserved`
     - `credit_wallet_id` and `credit_ledger_entry_id` are set.
   - On commit:
     - Wallet:
       - `reserved_cached` is decremented by `credit_cost`.
       - `balance_cached` is decremented by `credit_cost`.
     - Ledger:
       - A `TYPE_RELEASE` entry offsets the draft reservation.
       - A `TYPE_USAGE` entry charges `credit_cost` with LLM metadata (provider, tokens, etc.).
     - Reservation:
       - The associated `CreditReservation` is marked `captured`.
     - Draft:
       - `credit_status = committed`
       - `credit_ledger_entry_id` points to the usage entry.

3. **Draft release (no generation or failed generation)**
   - `releaseReservationForDraft` is only valid when:
     - `credit_status = reserved`.
   - On release:
     - Wallet:
       - `reserved_cached` is decremented by `credit_cost`.
       - `balance_cached` is unchanged.
     - Ledger:
       - A `TYPE_RELEASE` entry offsets the reservation, with a reason.
     - Reservation:
       - Marked `released`.
     - Draft:
       - `credit_status = released`.

4. **Images**
   - Image reservation / commit / release follow the same invariants as drafts, with one additional case:
     - If an image was **committed** (usage charged) but never produced output (e.g. job failed after charging), `refundCommittedContentImageUsage`:
       - Creates a `TYPE_REFUND` ledger entry, adding positive credits back to `balance_cached`.
       - Updates the image’s `credit_status` to `released` with a refund reason.

---

### Draft Compare billing invariants

These apply to the comparison‑level reservation handled by `DraftComparisonCreditManager` + `CreditReservationService`.

1. **Single reservation per comparison**
   - Each `DraftComparison` has at most one active reservation, with idempotency key:
     - `draft_compare:{comparison_id}:reserve`
   - `reserveForComparison`:
     - Is a no‑op if amount <= 0.
     - Is idempotent: multiple calls with the same comparison re‑use the same `CreditReservation`.
     - Increments `wallet.reserved_cached` by `amount` on first call only.

2. **Cost estimation vs actual cost**
   - `estimated_credit_cost` (or legacy equivalents) is an **upper bound** on expected usage, used purely for reservation.
   - `calculateActualCost` computes actual cost as:
     - Sum of `credit_cost` on completed variants, or
     - Legacy fallback: sum of `charged_credits` or `credit_cost` on generated `DraftComparisonItem` rows.
   - `final_credit_cost` and `credits_used` on `DraftComparison` are always set to the **actual captured amount**, not the reserved amount.

3. **Settlement rules**
   - Settlement only runs when the comparison is in a “settleable” status (completed, partially failed, failed, cancelled) or when explicitly forced.
   - Cases:
     - **No reservation found**:
       - `final_credit_cost = max(0, actualCost)`; no wallet changes from settlement.
     - **Reservation already finalized (captured/released/expired)**:
       - Settlement is idempotent and just mirrors reservation metadata into the comparison:
         - `final_credit_cost` derived from captured amount or 0.
     - **Reservation reserved, actualCost <= 0**:
       - Entire reservation is released via `CreditReservationService::release`.
       - Wallet:
         - `reserved_cached` decremented by reserved amount.
         - `balance_cached` unchanged.
       - Comparison:
         - `final_credit_cost = 0`, `credits_used = 0`.
     - **Reservation reserved, actualCost > 0**:
       - `captureAmount = min(actualCost, reservedAmount)`.
       - Reservation is captured via `CreditReservationService::capture` with `captureAmount`.
       - Wallet:
         - `reserved_cached` decremented by reserved amount.
         - `balance_cached` decremented by `captureAmount`.
       - Any difference `reservedAmount - captureAmount` is treated as **unused** and released.
       - Comparison:
         - `final_credit_cost = captureAmount`.
         - `credits_used = captureAmount`.

4. **Partial failure**
   - If some variants succeed and some fail:
     - `calculateActualCost` only counts successful variants/items.
     - Settlement **never** charges for failed variants.
     - Unused reserved credits are released via the capture metadata:
       - `unused_amount_released = reserved_amount - captured_amount`.

5. **Idempotency and retries**
   - `settleComparison` can be called multiple times and from multiple jobs:
     - Once a reservation is captured/released/expired, further calls do not change wallet totals.
     - `comparison.comparison_summary_json.billing` is overwritten with the latest billing state but remains logically consistent with the reservation.

---

### Safety checks & logging guidelines

When working in this area, prefer **failing loudly** over silently drifting from invariants.

1. **Defensive assertions (safe to add behind env flags)**
   - After saving a wallet:
     - Assert `balance_cached >= 0`, `reserved_cached >= 0`, `available >= 0`.
   - After capture / release / expire:
     - Assert that the associated `CreditReservation` has a status matching the operation.
   - In settlement logic:
     - Assert that `final_credit_cost` on `DraftComparison` equals the captured amount stored in reservation metadata (when a reservation exists and is captured).

2. **Logging**
   - Keep or extend structured logs in:
     - `CreditReservationService::reserve`, `capture`, `release`, `expire`.
     - `DraftComparisonCreditManager::reserveForComparison`, `settleComparison`, `refundComparison`.
   - Include:
     - `wallet_id`, `client_site_id`, `reservation_id`, `comparison_id`, `reserved_amount`, `captured_amount`, `unused_amount_released`, and `balance_cached` / `reserved_cached` snapshots where feasible.

3. **Tests as contracts**
   - Tests around:
     - Partial failure comparisons.
     - Fully failed comparisons.
     - Draft/image reservation/commit/release flows.
   - Should be treated as **behavioral contracts** for:
     - Wallet balances.
     - Reservation statuses.
     - Final billed amounts.

When changing credit or Draft Compare behavior, update this doc, then update or add tests so that these invariants are enforced in CI.

