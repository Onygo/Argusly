<?php

namespace App\Services\Credits;

use App\Models\ClientSite;
use App\Models\WorkspaceCreditTransaction;
use App\Models\WorkspaceCreditWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceCreditLedgerService
{
    public const TYPE_GRANT = 'grant';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SUBSCRIPTION_GRANT = 'subscription_grant';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_RESERVE = 'reserve';
    public const TYPE_COMMIT = 'commit';
    public const TYPE_RELEASE = 'release';
    public const TYPE_ALLOCATION_RETURN = 'allocation_return';

    public function getOrCreateWallet(string $workspaceId, ?int $organizationId = null): WorkspaceCreditWallet
    {
        return WorkspaceCreditWallet::query()->firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'organization_id' => $organizationId,
                'balance_cached' => 0,
                'reserved_cached' => 0,
            ]
        );
    }

    public function addCreditsToWorkspace(
        string $workspaceId,
        int $amount,
        string $type,
        ?int $organizationId = null,
        ?string $source = null,
        array $metadata = [],
        ?string $referenceType = null,
        ?string $referenceId = null,
        $expiresAt = null,
        ?string $idempotencyKey = null
    ): WorkspaceCreditTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('Workspace credit amount must be positive.');
        }

        return DB::transaction(function () use (
            $workspaceId,
            $amount,
            $type,
            $organizationId,
            $source,
            $metadata,
            $referenceType,
            $referenceId,
            $expiresAt,
            $idempotencyKey
        ): WorkspaceCreditTransaction {
            if ($idempotencyKey) {
                $existing = WorkspaceCreditTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->getOrCreateWallet($workspaceId, $organizationId);
                $wallet = WorkspaceCreditWallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            }

            $transaction = WorkspaceCreditTransaction::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_credit_wallet_id' => $wallet->id,
                'workspace_id' => $workspaceId,
                'organization_id' => $organizationId,
                'type' => $type,
                'source' => $source,
                'amount' => $amount,
                'remaining' => $amount,
                'expires_at' => $expiresAt,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'idempotency_key' => $idempotencyKey,
            ]);

            $wallet->balance_cached += $amount;
            $wallet->organization_id = $wallet->organization_id ?: $organizationId;
            $wallet->save();

            return $transaction;
        });
    }

    public function recordReservation(string $workspaceId, int $amount, ?string $clientSiteId = null, ?string $allocationId = null, ?string $reservationId = null, array $metadata = []): WorkspaceCreditTransaction
    {
        return $this->recordUsageEvent(
            workspaceId: $workspaceId,
            type: self::TYPE_RESERVE,
            amount: $amount,
            clientSiteId: $clientSiteId,
            allocationId: $allocationId,
            reservationId: $reservationId,
            metadata: $metadata
        );
    }

    public function recordRelease(string $workspaceId, int $amount, ?string $clientSiteId = null, ?string $allocationId = null, ?string $reservationId = null, array $metadata = []): WorkspaceCreditTransaction
    {
        return $this->recordUsageEvent(
            workspaceId: $workspaceId,
            type: self::TYPE_RELEASE,
            amount: -abs($amount),
            clientSiteId: $clientSiteId,
            allocationId: $allocationId,
            reservationId: $reservationId,
            metadata: $metadata
        );
    }

    public function commitUsage(string $workspaceId, int $amount, ?string $clientSiteId = null, ?string $allocationId = null, ?string $reservationId = null, array $metadata = [], ?string $referenceType = null, ?string $referenceId = null, ?string $idempotencyKey = null): WorkspaceCreditTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Committed amount must be positive.');
        }

        return DB::transaction(function () use ($workspaceId, $amount, $clientSiteId, $allocationId, $reservationId, $metadata, $referenceType, $referenceId, $idempotencyKey): WorkspaceCreditTransaction {
            if ($idempotencyKey) {
                $existing = WorkspaceCreditTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet->balance_cached -= $amount;
            $wallet->save();

            return WorkspaceCreditTransaction::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_credit_wallet_id' => $wallet->id,
                'workspace_id' => $workspaceId,
                'organization_id' => $wallet->organization_id,
                'client_site_id' => $clientSiteId,
                'site_credit_allocation_id' => $allocationId,
                'credit_reservation_id' => $reservationId,
                'type' => self::TYPE_COMMIT,
                'source' => 'usage',
                'amount' => -$amount,
                'remaining' => 0,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    public function adjustReserved(string $workspaceId, int $delta): WorkspaceCreditWallet
    {
        return DB::transaction(function () use ($workspaceId, $delta): WorkspaceCreditWallet {
            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached + $delta);
            $wallet->save();

            return $wallet;
        });
    }

    public function summary(string $workspaceId): array
    {
        $wallet = $this->getOrCreateWallet($workspaceId);
        $allocated = (int) DB::table('site_credit_allocations')
            ->where('workspace_id', $workspaceId)
            ->sum('allocated_credits');

        return [
            'workspace_id' => $workspaceId,
            'balance_cached' => (int) $wallet->balance_cached,
            'reserved_cached' => (int) $wallet->reserved_cached,
            'available' => (int) $wallet->available,
            'allocated_credits' => $allocated,
            'unallocated_credits' => max(0, (int) $wallet->balance_cached - $allocated),
            'granted_credits' => (int) WorkspaceCreditTransaction::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', [self::TYPE_GRANT, self::TYPE_PURCHASE, self::TYPE_SUBSCRIPTION_GRANT, self::TYPE_ADJUSTMENT, self::TYPE_REFUND, self::TYPE_ALLOCATION_RETURN])
                ->sum('amount'),
            'used_credits' => (int) abs((int) WorkspaceCreditTransaction::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', self::TYPE_COMMIT)
                ->sum('amount')),
        ];
    }

    public function autoAllocatePreferredSite(string $workspaceId): ?string
    {
        $siteIds = ClientSite::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->pluck('id');

        if ($siteIds->count() !== 1) {
            return null;
        }

        return (string) $siteIds->first();
    }

    private function recordUsageEvent(string $workspaceId, string $type, int $amount, ?string $clientSiteId = null, ?string $allocationId = null, ?string $reservationId = null, array $metadata = []): WorkspaceCreditTransaction
    {
        return DB::transaction(function () use ($workspaceId, $type, $amount, $clientSiteId, $allocationId, $reservationId, $metadata): WorkspaceCreditTransaction {
            $wallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            return WorkspaceCreditTransaction::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_credit_wallet_id' => $wallet->id,
                'workspace_id' => $workspaceId,
                'organization_id' => $wallet->organization_id,
                'client_site_id' => $clientSiteId,
                'site_credit_allocation_id' => $allocationId,
                'credit_reservation_id' => $reservationId,
                'type' => $type,
                'source' => 'usage',
                'amount' => $amount,
                'remaining' => 0,
                'metadata' => $metadata,
            ]);
        });
    }
}
