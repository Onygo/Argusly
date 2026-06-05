<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\ClientSite;
use App\Models\ContentCreditLog;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\SiteCreditAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;

class CreditReservationService
{
    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_RELEASE = 'release';

    public const TYPE_USAGE = 'usage';

    public function __construct(
        protected readonly SubscriptionService $subscriptions,
        protected readonly SiteCreditAllocationService $siteAllocations,
        protected readonly WorkspaceCreditLedgerService $workspaceCredits
    ) {}

    /**
     * Reserve credits for an operation.
     *
     * Idempotent: if a reservation with the same idempotency_key exists, returns it.
     * Concurrency safe: uses row-level locking on wallet.
     *
     * @param  string  $clientSiteId  The client site for wallet lookup
     * @param  int  $amount  Credits to reserve
     * @param  string  $idempotencyKey  Unique key to prevent double reservations
     * @param  string  $purpose  Purpose of reservation (e.g., 'draft_generate', 'image_generate')
     * @param  Model|null  $context  Polymorphic context (Draft, ContentImage, etc.)
     * @param  array  $options  Additional options: provider, userId, ttlMinutes, metadata
     * @return CreditReservation The created or existing reservation
     *
     * @throws InsufficientCreditsException When available credits are less than amount
     * @throws RuntimeException When wallet not found or other errors
     */
    public function reserve(
        string $clientSiteId,
        int $amount,
        string $idempotencyKey,
        string $purpose,
        ?Model $context = null,
        array $options = []
    ): CreditReservation {
        if ($amount <= 0) {
            throw new RuntimeException('Reservation amount must be positive.');
        }

        $userId = $options['userId'] ?? null;
        $provider = $options['provider'] ?? null;
        $ttlMinutes = $options['ttlMinutes'] ?? CreditReservation::defaultTtlMinutes();
        $metadata = $options['metadata'] ?? [];

        // Check if reservation already exists (idempotency)
        $existing = CreditReservation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            Log::info('Credit reservation already exists', [
                'reservation_id' => $existing->id,
                'idempotency_key' => $idempotencyKey,
                'status' => $existing->status,
            ]);

            return $existing;
        }

        return DB::transaction(function () use (
            $clientSiteId,
            $amount,
            $idempotencyKey,
            $purpose,
            $context,
            $userId,
            $provider,
            $ttlMinutes,
            $metadata
        ) {
            // Double-check idempotency inside transaction
            $existing = CreditReservation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Lock wallet for update
            $wallet = CreditWallet::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->createWallet($clientSiteId);
            }

            // Calculate available credits
            $consumable = $this->getConsumableCreditsForWallet($wallet->id);
            $available = $consumable - (int) $wallet->reserved_cached;

            if ($available < $amount) {
                throw new InsufficientCreditsException($amount, max(0, $available));
            }

            // Resolve organization and workspace
            $site = ClientSite::query()->with('workspace')->find($clientSiteId);
            $organizationId = $site?->workspace?->organization_id;
            $workspaceId = $site?->workspace_id;
            $allocation = $this->siteAllocations->getOrCreateAllocation($clientSiteId);

            if ((int) $allocation->remaining < $amount) {
                throw new InsufficientCreditsException($amount, max(0, (int) $allocation->remaining));
            }

            // Create ledger entry for reservation
            $ledgerEntry = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RESERVATION,
                'source' => 'usage',
                'amount' => $amount,
                'remaining' => 0,
                'source_type' => $context ? get_class($context) : null,
                'source_id' => $context?->id,
                'client_site_id' => $clientSiteId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'meta' => array_merge($metadata, [
                    'purpose' => $purpose,
                    'provider' => $provider,
                ]),
                'idempotency_key' => 'reservation:' . $idempotencyKey,
            ]);

            // Create reservation record
            $reservation = CreditReservation::create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'workspace_id' => $workspaceId,
                'workspace_credit_wallet_id' => $workspaceId
                    ? $this->workspaceCredits->getOrCreateWallet((string) $workspaceId, $organizationId ? (int) $organizationId : null)->id
                    : null,
                'client_site_id' => $clientSiteId,
                'site_credit_allocation_id' => $allocation->id,
                'credit_wallet_id' => $wallet->id,
                'user_id' => $userId,
                'amount' => $amount,
                'currency_unit' => 'credits',
                'status' => CreditReservation::STATUS_RESERVED,
                'context_type' => $context ? get_class($context) : null,
                'context_id' => $context?->id,
                'provider' => $provider,
                'purpose' => $purpose,
                'idempotency_key' => $idempotencyKey,
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes($ttlMinutes),
                'reservation_ledger_entry_id' => $ledgerEntry->id,
                'metadata' => $metadata,
            ]);

            // Update wallet reserved_cached
            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached += $amount;
                $wallet->save();
            }
            $this->siteAllocations->reserve($clientSiteId, $amount);
            $this->workspaceCredits->adjustReserved((string) $workspaceId, $amount);
            $workspaceReservation = $this->workspaceCredits->recordReservation(
                workspaceId: (string) $workspaceId,
                amount: $amount,
                clientSiteId: $clientSiteId,
                allocationId: $allocation->id,
                reservationId: (string) $reservation->id,
                metadata: [
                    'purpose' => $purpose,
                    'provider' => $provider,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            $reservation->forceFill([
                'reservation_workspace_transaction_id' => $workspaceReservation->id,
            ])->save();

            Log::info('Credit reservation created', [
                'reservation_id' => $reservation->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'purpose' => $purpose,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $reservation->expires_at,
            ]);

            return $reservation;
        });
    }

    /**
     * Capture a reservation (convert to actual usage).
     *
     * Only works on reservations in 'reserved' status.
     * Idempotent: if already captured, returns the reservation.
     *
     * @param  CreditReservation  $reservation  The reservation to capture
     * @param  array  $options  Additional options: userId, metadata, captureAmount
     * @return CreditReservation The captured reservation
     *
     * @throws RuntimeException When reservation is not in reserved status
     */
    public function capture(CreditReservation $reservation, array $options = []): CreditReservation
    {
        $userId = $options['userId'] ?? null;
        $metadata = $options['metadata'] ?? [];
        $captureAmountOption = isset($options['captureAmount']) ? (int) $options['captureAmount'] : null;

        // Already captured - idempotent return
        if ($reservation->isCaptured()) {
            Log::info('Credit reservation already captured', [
                'reservation_id' => $reservation->id,
            ]);

            return $reservation;
        }

        // Cannot capture if released or expired
        if ($reservation->isFinalized()) {
            throw new RuntimeException(sprintf(
                'Cannot capture reservation %s: status is %s',
                $reservation->id,
                $reservation->status
            ));
        }

        return DB::transaction(function () use ($reservation, $userId, $metadata, $captureAmountOption) {
            // Lock reservation
            $freshReservation = CreditReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check status after locking
            if ($freshReservation->isCaptured()) {
                return $freshReservation;
            }

            if ($freshReservation->isFinalized()) {
                throw new RuntimeException(sprintf(
                    'Cannot capture reservation %s: status is %s',
                    $freshReservation->id,
                    $freshReservation->status
                ));
            }

            // Lock wallet
            $wallet = CreditWallet::query()
                ->whereKey($freshReservation->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (int) $freshReservation->amount;
            $captureAmount = $captureAmountOption ?? $amount;
            if ($captureAmount <= 0) {
                throw new RuntimeException('Capture amount must be positive.');
            }
            if ($captureAmount > $amount) {
                throw new RuntimeException(sprintf(
                    'Capture amount %d cannot exceed reserved amount %d for reservation %s.',
                    $captureAmount,
                    $amount,
                    $freshReservation->id
                ));
            }

            // Create release entry (to offset reservation)
            $releaseEntry = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$amount,
                'remaining' => 0,
                'source_type' => $freshReservation->context_type,
                'source_id' => $freshReservation->context_id,
                'client_site_id' => $freshReservation->client_site_id,
                'organization_id' => $freshReservation->organization_id,
                'user_id' => $userId,
                'meta' => [
                    'reservation_id' => $freshReservation->id,
                    'reservation_entry_id' => $freshReservation->reservation_ledger_entry_id,
                    'reason' => 'capture',
                    'reserved_amount' => $amount,
                    'captured_amount' => $captureAmount,
                    'unused_amount_released' => max(0, $amount - $captureAmount),
                ],
                'idempotency_key' => sprintf('capture-release:%s', $freshReservation->id),
            ]);

            // Consume from buckets
            $allocations = $this->consumeFromBuckets($wallet, $captureAmount);

            // Create usage entry
            $usageEntry = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_USAGE,
                'source' => 'usage',
                'amount' => -$captureAmount,
                'remaining' => 0,
                'source_type' => $freshReservation->context_type,
                'source_id' => $freshReservation->context_id,
                'client_site_id' => $freshReservation->client_site_id,
                'organization_id' => $freshReservation->organization_id,
                'user_id' => $userId,
                'meta' => array_merge($metadata, [
                    'reservation_id' => $freshReservation->id,
                    'release_entry_id' => $releaseEntry->id,
                    'purpose' => $freshReservation->purpose,
                    'provider' => $freshReservation->provider,
                    'allocations' => $allocations,
                    'consumption_policy' => 'included_first_then_addon',
                    'reserved_amount' => $amount,
                    'captured_amount' => $captureAmount,
                    'unused_amount_released' => max(0, $amount - $captureAmount),
                ]),
                'idempotency_key' => sprintf('capture-usage:%s', $freshReservation->id),
            ]);

            // Update wallet
            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached - $amount);
                $wallet->balance_cached -= $captureAmount;
                $wallet->save();
            }
            $this->siteAllocations->captureUsage((string) $freshReservation->client_site_id, $amount, $captureAmount);
            $this->workspaceCredits->adjustReserved((string) $freshReservation->workspace_id, -$amount);
            $workspaceCapture = $this->workspaceCredits->commitUsage(
                workspaceId: (string) $freshReservation->workspace_id,
                amount: $captureAmount,
                clientSiteId: (string) $freshReservation->client_site_id,
                allocationId: $freshReservation->site_credit_allocation_id,
                reservationId: (string) $freshReservation->id,
                metadata: array_merge($metadata, [
                    'purpose' => $freshReservation->purpose,
                    'provider' => $freshReservation->provider,
                    'captured_amount' => $captureAmount,
                ]),
                referenceType: $freshReservation->context_type,
                referenceId: $freshReservation->context_id,
                idempotencyKey: sprintf('workspace-capture:%s', $freshReservation->id)
            );

            // Update reservation
            $freshReservation->update([
                'status' => CreditReservation::STATUS_CAPTURED,
                'captured_at' => now(),
                'capture_ledger_entry_id' => $usageEntry->id,
                'capture_workspace_transaction_id' => $workspaceCapture->id,
                'release_ledger_entry_id' => $releaseEntry->id,
                'metadata' => array_merge($freshReservation->metadata ?? [], $metadata, [
                    'reserved_amount' => $amount,
                    'captured_amount' => $captureAmount,
                    'unused_amount_released' => max(0, $amount - $captureAmount),
                ]),
            ]);

            // Create content credit log if context is available
            $this->createCreditLogForCapture($freshReservation, $usageEntry);

            Log::info('Credit reservation captured', [
                'reservation_id' => $freshReservation->id,
                'reserved_amount' => $amount,
                'captured_amount' => $captureAmount,
                'wallet_id' => $wallet->id,
            ]);

            return $freshReservation;
        });
    }

    /**
     * Release a reservation (refund the reserved credits).
     *
     * Only works on reservations in 'reserved' status.
     * Idempotent: if already released, returns the reservation.
     *
     * @param  CreditReservation  $reservation  The reservation to release
     * @param  string  $reason  Reason for release (e.g., 'generation_failed', 'timeout', 'admin_release')
     * @param  array  $options  Additional options: userId, adminUserId, failureCode, failureMessage, metadata
     * @return CreditReservation The released reservation
     *
     * @throws RuntimeException When reservation is not in reserved status
     */
    public function release(
        CreditReservation $reservation,
        string $reason = 'release',
        array $options = []
    ): CreditReservation {
        $userId = $options['userId'] ?? null;
        $adminUserId = $options['adminUserId'] ?? null;
        $failureCode = $options['failureCode'] ?? null;
        $failureMessage = $options['failureMessage'] ?? null;
        $metadata = $options['metadata'] ?? [];

        // Already finalized (released, expired, or captured) - idempotent return
        // Captured reservations are treated as no-op for release: credits were already
        // consumed, so there's nothing to release. This handles race conditions where
        // a stale model shows 'reserved' but DB is 'captured'.
        if ($reservation->isReleased() || $reservation->isExpired() || $reservation->isCaptured()) {
            Log::info('Credit reservation already finalized, release is no-op', [
                'reservation_id' => $reservation->id,
                'status' => $reservation->status,
            ]);

            return $reservation;
        }

        return DB::transaction(function () use (
            $reservation,
            $reason,
            $userId,
            $adminUserId,
            $failureCode,
            $failureMessage,
            $metadata
        ) {
            // Lock reservation
            $freshReservation = CreditReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check status after locking - idempotent for all finalized states
            if ($freshReservation->isReleased() || $freshReservation->isExpired() || $freshReservation->isCaptured()) {
                return $freshReservation;
            }

            // Lock wallet
            $wallet = CreditWallet::query()
                ->whereKey($freshReservation->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (int) $freshReservation->amount;

            // Create release ledger entry
            $releaseEntry = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$amount,
                'remaining' => 0,
                'source_type' => $freshReservation->context_type,
                'source_id' => $freshReservation->context_id,
                'client_site_id' => $freshReservation->client_site_id,
                'organization_id' => $freshReservation->organization_id,
                'user_id' => $userId ?? $freshReservation->user_id,
                'meta' => array_merge($metadata, [
                    'reservation_id' => $freshReservation->id,
                    'reservation_entry_id' => $freshReservation->reservation_ledger_entry_id,
                    'reason' => $reason,
                    'failure_code' => $failureCode,
                    'admin_user_id' => $adminUserId,
                ]),
                'idempotency_key' => sprintf('release:%s', $freshReservation->id),
            ]);

            // Update wallet
            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached - $amount);
                $wallet->save();
            }
            $this->siteAllocations->releaseReserved((string) $freshReservation->client_site_id, $amount);
            $this->workspaceCredits->adjustReserved((string) $freshReservation->workspace_id, -$amount);
            $workspaceRelease = $this->workspaceCredits->recordRelease(
                workspaceId: (string) $freshReservation->workspace_id,
                amount: $amount,
                clientSiteId: (string) $freshReservation->client_site_id,
                allocationId: $freshReservation->site_credit_allocation_id,
                reservationId: (string) $freshReservation->id,
                metadata: [
                    'reason' => $reason,
                    'failure_code' => $failureCode,
                ]
            );

            // Update reservation
            $freshReservation->update([
                'status' => CreditReservation::STATUS_RELEASED,
                'released_at' => now(),
                'release_ledger_entry_id' => $releaseEntry->id,
                'release_workspace_transaction_id' => $workspaceRelease->id,
                'reason' => $reason,
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage ? Str::limit($failureMessage, 5000) : null,
                'admin_user_id' => $adminUserId,
                'metadata' => array_merge($freshReservation->metadata ?? [], $metadata),
            ]);

            // Create content credit log if context is available
            $this->createCreditLogForRelease($freshReservation, $releaseEntry);

            Log::info('Credit reservation released', [
                'reservation_id' => $freshReservation->id,
                'amount' => $amount,
                'wallet_id' => $wallet->id,
                'reason' => $reason,
            ]);

            return $freshReservation;
        });
    }

    /**
     * Expire stale reservations that have passed their TTL.
     *
     * @param  int  $limit  Maximum number of reservations to expire in one run
     * @return int Number of reservations expired
     */
    public function expireStaleReservations(int $limit = 100): int
    {
        $staleReservations = CreditReservation::query()
            ->stale()
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $expiredCount = 0;

        foreach ($staleReservations as $reservation) {
            try {
                $this->expire($reservation);
                $expiredCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to expire reservation', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($expiredCount > 0) {
            Log::info('Expired stale credit reservations', [
                'count' => $expiredCount,
            ]);
        }

        return $expiredCount;
    }

    /**
     * Expire a single reservation.
     */
    public function expire(CreditReservation $reservation): CreditReservation
    {
        return DB::transaction(function () use ($reservation) {
            $freshReservation = CreditReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Already finalized
            if ($freshReservation->isFinalized()) {
                return $freshReservation;
            }

            // Lock wallet
            $wallet = CreditWallet::query()
                ->whereKey($freshReservation->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (int) $freshReservation->amount;

            // Create release ledger entry for expiry
            $releaseEntry = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$amount,
                'remaining' => 0,
                'source_type' => $freshReservation->context_type,
                'source_id' => $freshReservation->context_id,
                'client_site_id' => $freshReservation->client_site_id,
                'organization_id' => $freshReservation->organization_id,
                'user_id' => $freshReservation->user_id,
                'meta' => [
                    'reservation_id' => $freshReservation->id,
                    'reservation_entry_id' => $freshReservation->reservation_ledger_entry_id,
                    'reason' => 'expired',
                ],
                'idempotency_key' => sprintf('expire:%s', $freshReservation->id),
            ]);

            // Update wallet
            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached - $amount);
                $wallet->save();
            }
            $this->siteAllocations->releaseReserved((string) $freshReservation->client_site_id, $amount);
            $this->workspaceCredits->adjustReserved((string) $freshReservation->workspace_id, -$amount);
            $workspaceRelease = $this->workspaceCredits->recordRelease(
                workspaceId: (string) $freshReservation->workspace_id,
                amount: $amount,
                clientSiteId: (string) $freshReservation->client_site_id,
                allocationId: $freshReservation->site_credit_allocation_id,
                reservationId: (string) $freshReservation->id,
                metadata: [
                    'reason' => 'expired',
                ]
            );

            // Update reservation
            $freshReservation->update([
                'status' => CreditReservation::STATUS_EXPIRED,
                'released_at' => now(),
                'release_ledger_entry_id' => $releaseEntry->id,
                'release_workspace_transaction_id' => $workspaceRelease->id,
                'reason' => 'expired',
            ]);

            Log::info('Credit reservation expired', [
                'reservation_id' => $freshReservation->id,
                'amount' => $amount,
            ]);

            return $freshReservation;
        });
    }

    /**
     * Find a reservation by idempotency key.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?CreditReservation
    {
        return CreditReservation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Find active (reserved) reservation for a context.
     */
    public function findActiveForContext(Model $context): ?CreditReservation
    {
        return CreditReservation::query()
            ->forContext(get_class($context), $context->id)
            ->reserved()
            ->first();
    }

    /**
     * Get all reservations for a wallet.
     */
    public function getForWallet(string $walletId, array $filters = []): Collection
    {
        $query = CreditReservation::query()->forWallet($walletId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['purpose'])) {
            $query->forPurpose($filters['purpose']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Get total reserved amount for a wallet.
     */
    public function getTotalReservedForWallet(string $walletId): int
    {
        return (int) CreditReservation::query()
            ->forWallet($walletId)
            ->reserved()
            ->sum('amount');
    }

    /**
     * Admin: Force capture a reservation (with guard rails).
     *
     * Only allowed if context has valid content (e.g., draft has output).
     */
    public function adminCapture(
        CreditReservation $reservation,
        int $adminUserId,
        string $reason = 'admin_capture'
    ): CreditReservation {
        if (! $reservation->isReserved()) {
            throw new RuntimeException(sprintf(
                'Cannot admin capture reservation %s: status is %s',
                $reservation->id,
                $reservation->status
            ));
        }

        // Validate that context has content
        $context = $reservation->context;
        if ($context && ! $this->validateContextHasContent($context)) {
            throw new RuntimeException(
                'Cannot capture: context does not have valid content. Release instead.'
            );
        }

        return $this->capture($reservation, [
            'adminUserId' => $adminUserId,
            'metadata' => [
                'admin_action' => true,
                'admin_reason' => $reason,
            ],
        ]);
    }

    /**
     * Admin: Force release a reservation.
     */
    public function adminRelease(
        CreditReservation $reservation,
        int $adminUserId,
        string $reason = 'admin_release'
    ): CreditReservation {
        if ($reservation->isCaptured()) {
            throw new RuntimeException(sprintf(
                'Cannot admin release reservation %s: already captured',
                $reservation->id
            ));
        }

        if ($reservation->isReleased() || $reservation->isExpired()) {
            return $reservation;
        }

        return $this->release($reservation, $reason, [
            'adminUserId' => $adminUserId,
            'metadata' => [
                'admin_action' => true,
            ],
        ]);
    }

    /**
     * Check if context has valid content for capture.
     */
    protected function validateContextHasContent(Model $context): bool
    {
        // Draft: must have output/content
        if (method_exists($context, 'hasOutput')) {
            return $context->hasOutput();
        }

        // ContentImage: must have image path
        if ($context instanceof \App\Models\ContentImage) {
            return $context->hasOutput();
        }

        // Draft: check for output or content
        if ($context instanceof \App\Models\Draft) {
            return ! empty($context->output) || ! empty($context->content);
        }

        // Default: allow capture
        return true;
    }

    /**
     * Get consumable credits for a wallet (unexpired buckets).
     */
    protected function getConsumableCreditsForWallet(string $walletId): int
    {
        $clientSiteId = CreditWallet::query()->whereKey($walletId)->value('client_site_id');

        return $clientSiteId
            ? $this->siteAllocations->consumableCreditsForSite((string) $clientSiteId)
            : 0;
    }

    /**
     * Create wallet if it doesn't exist.
     */
    protected function createWallet(string $clientSiteId): CreditWallet
    {
        $site = ClientSite::query()->find($clientSiteId);

        $wallet = CreditWallet::create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $clientSiteId,
            'workspace_id' => $site?->workspace_id,
            'balance_cached' => 0,
            'reserved_cached' => 0,
        ]);

        return $wallet->fresh();
    }

    /**
     * Consume credits from buckets (FIFO, included_plan first).
     */
    protected function consumeFromBuckets(CreditWallet $wallet, int $amount): array
    {
        return $this->siteAllocations->consumeAllocatedCredits((string) $wallet->client_site_id, $amount);
    }

    /**
     * Create ContentCreditLog for capture.
     */
    protected function createCreditLogForCapture(CreditReservation $reservation, CreditLedgerEntry $usageEntry): void
    {
        if (! $reservation->context_id) {
            return;
        }

        // Determine content_id and draft_id based on context
        $contentId = null;
        $draftId = null;

        if ($reservation->context_type === \App\Models\Draft::class) {
            $draft = \App\Models\Draft::find($reservation->context_id);
            $contentId = $draft?->content_id;
            $draftId = $reservation->context_id;
        } elseif ($reservation->context_type === \App\Models\ContentImage::class) {
            $image = \App\Models\ContentImage::find($reservation->context_id);
            $contentId = $image?->content_id;
        } elseif ($reservation->context_type === \App\Models\Content::class) {
            $contentId = $reservation->context_id;
        }

        if (! $contentId) {
            return;
        }

        ContentCreditLog::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $contentId,
            'draft_id' => $draftId,
            'credit_ledger_entry_id' => $usageEntry->id,
            'workspace_credit_transaction_id' => $reservation->capture_workspace_transaction_id,
            'event' => 'commit',
            'credits_used' => abs($usageEntry->amount),
            'mode_multiplier' => 1.0,
            'meta' => [
                'reservation_id' => $reservation->id,
                'purpose' => $reservation->purpose,
                'provider' => $reservation->provider,
            ],
        ]);
    }

    /**
     * Create ContentCreditLog for release.
     */
    protected function createCreditLogForRelease(CreditReservation $reservation, CreditLedgerEntry $releaseEntry): void
    {
        if (! $reservation->context_id) {
            return;
        }

        $contentId = null;
        $draftId = null;

        if ($reservation->context_type === \App\Models\Draft::class) {
            $draft = \App\Models\Draft::find($reservation->context_id);
            $contentId = $draft?->content_id;
            $draftId = $reservation->context_id;
        } elseif ($reservation->context_type === \App\Models\ContentImage::class) {
            $image = \App\Models\ContentImage::find($reservation->context_id);
            $contentId = $image?->content_id;
        } elseif ($reservation->context_type === \App\Models\Content::class) {
            $contentId = $reservation->context_id;
        }

        if (! $contentId) {
            return;
        }

        ContentCreditLog::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $contentId,
            'draft_id' => $draftId,
            'credit_ledger_entry_id' => $releaseEntry->id,
            'workspace_credit_transaction_id' => $reservation->release_workspace_transaction_id,
            'event' => 'release',
            'credits_used' => $reservation->amount,
            'mode_multiplier' => 1.0,
            'meta' => [
                'reservation_id' => $reservation->id,
                'purpose' => $reservation->purpose,
                'reason' => $reservation->reason,
            ],
        ]);
    }

    protected function walletBackedBySiteAllocation(CreditWallet $wallet): bool
    {
        return $wallet->getTable() === 'site_credit_allocations';
    }
}
