<?php

namespace App\Services\Credits;

use App\Exceptions\InvalidCreditAllocationException;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\SiteCreditAllocation;
use App\Models\SiteCreditAllocationBucket;
use App\Models\SiteCreditAllocationLog;
use App\Models\WorkspaceCreditTransaction;
use App\Models\WorkspaceCreditWallet;
use App\Services\Billing\CreditConsumptionService;
use App\Services\Billing\CreditExpirationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SiteCreditAllocationService
{
    private const LEGACY_BUCKET_SOURCES = ['included_plan', 'addon_pack'];

    public function __construct(
        private readonly WorkspaceCreditLedgerService $workspaceCredits,
        private readonly CreditConsumptionService $creditConsumption,
        private readonly CreditExpirationService $creditExpiration
    ) {}

    public function getOrCreateAllocation(string $clientSiteId): SiteCreditAllocation
    {
        $site = ClientSite::query()->findOrFail($clientSiteId);

        return SiteCreditAllocation::query()->firstOrCreate(
            ['client_site_id' => $clientSiteId],
            [
                'workspace_id' => $site->workspace_id,
                'allocated_credits' => 0,
                'reserved_cached' => 0,
                'used_cached' => 0,
            ]
        );
    }

    public function allocateToSite(string $clientSiteId, int $amount, ?int $userId = null, array $metadata = []): SiteCreditAllocation
    {
        if ($amount <= 0) {
            throw new InvalidCreditAllocationException('Allocation amount must be positive.');
        }

        return DB::transaction(function () use ($clientSiteId, $amount, $userId, $metadata): SiteCreditAllocation {
            $site = ClientSite::query()->with('workspace')->findOrFail($clientSiteId);
            $workspaceWallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $site->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->first();

            if (! $allocation) {
                $allocation = $this->getOrCreateAllocation($clientSiteId);
                $allocation = SiteCreditAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            }

            $allocatedTotal = (int) SiteCreditAllocation::query()
                ->where('workspace_id', $site->workspace_id)
                ->lockForUpdate()
                ->sum('allocated_credits');

            $unallocated = max(0, (int) $workspaceWallet->balance_cached - $allocatedTotal);
            if ($unallocated < $amount) {
                throw new InvalidCreditAllocationException(sprintf(
                    'Cannot allocate %d credits. Workspace has %d unallocated credits left.',
                    $amount,
                    $unallocated
                ));
            }

            $buckets = $this->workspaceAllocationBuckets(
                (string) $site->workspace_id,
                (bool) ($metadata['allow_expired_source_buckets'] ?? false)
            )->get();

            $remainingToAllocate = $amount;
            $legacyWallet = $this->getOrCreateLegacyWallet($site->id, $site->workspace_id);

            foreach ($buckets as $bucket) {
                if ($remainingToAllocate <= 0) {
                    break;
                }

                $take = min($remainingToAllocate, (int) $bucket->remaining);
                if ($take <= 0) {
                    continue;
                }

                $bucket->remaining -= $take;
                $bucket->save();

                SiteCreditAllocationBucket::query()->create([
                    'id' => (string) Str::uuid(),
                    'site_credit_allocation_id' => $allocation->id,
                    'workspace_credit_transaction_id' => $bucket->id,
                    'workspace_id' => $site->workspace_id,
                    'client_site_id' => $site->id,
                    'source' => $bucket->source,
                    'amount' => $take,
                    'remaining' => $take,
                    'expires_at' => $bucket->expires_at,
                    'reference_type' => $bucket->reference_type,
                    'reference_id' => $bucket->reference_id,
                    'metadata' => array_merge($metadata, [
                        'event' => 'site_allocation',
                        'workspace_credit_transaction_id' => $bucket->id,
                    ]),
                ]);

                $this->createLegacyAllocationEntry(
                    legacyWallet: $legacyWallet,
                    site: $site,
                    bucket: $bucket,
                    amount: $take,
                    metadata: $metadata
                );

                $remainingToAllocate -= $take;
            }

            if ($remainingToAllocate > 0) {
                throw new InvalidCreditAllocationException('Workspace allocation buckets are insufficient for this allocation.');
            }

            $allocation->allocated_credits += $amount;
            $allocation->updated_by_user_id = $userId;
            $allocation->metadata = array_merge($allocation->metadata ?? [], $metadata);
            $allocation->save();

            $this->log($site->workspace_id, $site->id, 'allocate', $amount, $userId, $metadata);

            return $allocation;
        });
    }

    public function reclaimFromSite(string $clientSiteId, int $amount, ?int $userId = null, array $metadata = []): SiteCreditAllocation
    {
        if ($amount <= 0) {
            throw new InvalidCreditAllocationException('Reclaim amount must be positive.');
        }

        return DB::transaction(function () use ($clientSiteId, $amount, $userId, $metadata): SiteCreditAllocation {
            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->firstOrFail();

            $site = ClientSite::query()->with('workspace')->findOrFail($clientSiteId);
            $reclaimable = (int) $allocation->allocated_credits - (int) $allocation->reserved_cached;

            if ($reclaimable < $amount) {
                throw new InvalidCreditAllocationException(sprintf(
                    'Cannot reclaim %d credits. Site has only %d reclaimable credits.',
                    $amount,
                    max(0, $reclaimable)
                ));
            }

            $legacyWallet = $this->getOrCreateLegacyWallet($site->id, $site->workspace_id);
            $remainingToReclaim = $amount;

            $buckets = $this->allocationBucketsForReclaim($allocation->id)->get();

            foreach ($buckets as $bucket) {
                if ($remainingToReclaim <= 0) {
                    break;
                }

                $take = min($remainingToReclaim, (int) $bucket->remaining);
                if ($take <= 0) {
                    continue;
                }

                $bucket->remaining -= $take;
                $bucket->save();

                $this->returnReclaimedCreditsToWorkspace(
                    site: $site,
                    bucket: $bucket,
                    amount: $take,
                    metadata: $metadata
                );

                $remainingToReclaim -= $take;
            }

            if ($remainingToReclaim > 0) {
                throw new InvalidCreditAllocationException('Failed to reclaim enough source buckets from site allocation.');
            }

            $allocation->allocated_credits -= $amount;
            $allocation->updated_by_user_id = $userId;
            $allocation->metadata = array_merge($allocation->metadata ?? [], $metadata);
            $allocation->save();

            $this->log($site->workspace_id, $site->id, 'reclaim', $amount, $userId, $metadata);

            return $allocation;
        });
    }

    public function transfer(string $fromClientSiteId, string $toClientSiteId, int $amount, ?int $userId = null, array $metadata = []): void
    {
        if ($fromClientSiteId === $toClientSiteId) {
            throw new InvalidCreditAllocationException('Transfer requires two different sites.');
        }

        DB::transaction(function () use ($fromClientSiteId, $toClientSiteId, $amount, $userId, $metadata): void {
            $fromSite = ClientSite::query()->findOrFail($fromClientSiteId);
            $toSite = ClientSite::query()->findOrFail($toClientSiteId);

            if ((string) $fromSite->workspace_id !== (string) $toSite->workspace_id) {
                throw new InvalidCreditAllocationException('Credits can only be transferred inside the same workspace.');
            }

            $this->reclaimFromSite($fromClientSiteId, $amount, $userId, array_merge($metadata, [
                'transfer_to_site_id' => $toClientSiteId,
            ]));

            $this->allocateToSite($toClientSiteId, $amount, $userId, array_merge($metadata, [
                'transfer_from_site_id' => $fromClientSiteId,
            ]));

            $this->log($fromSite->workspace_id, $fromClientSiteId, 'transfer_out', $amount, $userId, array_merge($metadata, [
                'to_client_site_id' => $toClientSiteId,
            ]), $fromClientSiteId, $toClientSiteId);
            $this->log($toSite->workspace_id, $toClientSiteId, 'transfer_in', $amount, $userId, array_merge($metadata, [
                'from_client_site_id' => $fromClientSiteId,
            ]), $fromClientSiteId, $toClientSiteId);
        });
    }

    public function reserve(string $clientSiteId, int $amount): SiteCreditAllocation
    {
        return DB::transaction(function () use ($clientSiteId, $amount): SiteCreditAllocation {
            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->firstOrFail();

            if (((int) $allocation->allocated_credits - (int) $allocation->reserved_cached) < $amount) {
                throw new RuntimeException('Insufficient site allocation for reservation.');
            }

            $allocation->reserved_cached += $amount;
            $allocation->save();

            return $allocation;
        });
    }

    public function releaseReserved(string $clientSiteId, int $amount): SiteCreditAllocation
    {
        return DB::transaction(function () use ($clientSiteId, $amount): SiteCreditAllocation {
            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->firstOrFail();

            $allocation->reserved_cached = max(0, (int) $allocation->reserved_cached - $amount);
            $allocation->save();

            return $allocation;
        });
    }

    public function captureUsage(string $clientSiteId, int $reservedAmount, int $captureAmount): SiteCreditAllocation
    {
        return DB::transaction(function () use ($clientSiteId, $reservedAmount, $captureAmount): SiteCreditAllocation {
            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->firstOrFail();

            $allocation->reserved_cached = max(0, (int) $allocation->reserved_cached - $reservedAmount);
            $allocation->allocated_credits = max(0, (int) $allocation->allocated_credits - $captureAmount);
            $allocation->used_cached += $captureAmount;
            $allocation->save();

            return $allocation;
        });
    }

    public function restoreCredits(string $clientSiteId, int $amount, ?string $source = 'addon_pack', ?string $referenceType = null, ?string $referenceId = null, array $metadata = []): SiteCreditAllocation
    {
        $site = ClientSite::query()->with('workspace')->findOrFail($clientSiteId);

        $this->workspaceCredits->addCreditsToWorkspace(
            workspaceId: (string) $site->workspace_id,
            amount: $amount,
            type: WorkspaceCreditLedgerService::TYPE_REFUND,
            organizationId: $site->workspace?->organization_id,
            source: $source,
            metadata: array_merge($metadata, [
                'event' => 'site_restore',
            ]),
            referenceType: $referenceType,
            referenceId: $referenceId
        );

        return $this->allocateToSite($clientSiteId, $amount, null, array_merge($metadata, [
            'event' => 'site_restore',
        ]));
    }

    public function syncLegacyUsage(string $clientSiteId, int $amount): SiteCreditAllocation
    {
        return DB::transaction(function () use ($clientSiteId, $amount): SiteCreditAllocation {
            $allocation = SiteCreditAllocation::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->firstOrFail();

            $allocation->allocated_credits = max(0, (int) $allocation->allocated_credits - $amount);
            $allocation->used_cached += $amount;
            $allocation->save();

            return $allocation;
        });
    }

    public function workspaceSiteBreakdown(string $workspaceId): Collection
    {
        return SiteCreditAllocation::query()
            ->with('clientSite')
            ->where('workspace_id', $workspaceId)
            ->orderBy('client_site_id')
            ->get()
            ->map(function (SiteCreditAllocation $allocation): array {
                return [
                    'site_id' => (string) $allocation->client_site_id,
                    'site_name' => (string) ($allocation->clientSite?->name ?? $allocation->client_site_id),
                    'allocated_credits' => (int) $allocation->allocated_credits,
                    'reserved_credits' => (int) $allocation->reserved_cached,
                    'used_credits' => (int) $allocation->used_cached,
                    'remaining_credits' => (int) $allocation->remaining,
                ];
            });
    }

    public function consumableCreditsForSite(string $clientSiteId): int
    {
        return (int) $this->activeAllocationBuckets($clientSiteId)->sum('remaining');
    }

    public function remainingBySource(string $clientSiteId, string $source): int
    {
        return (int) SiteCreditAllocationBucket::query()
            ->where('client_site_id', $clientSiteId)
            ->whereIn('source', self::LEGACY_BUCKET_SOURCES)
            ->where('remaining', '>', 0)
            ->where('source', $source)
            ->sum('remaining');
    }

    public function consumeAllocatedCredits(string $clientSiteId, int $amount): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Consumed amount must be positive.');
        }

        $allocationId = SiteCreditAllocation::query()
            ->where('client_site_id', $clientSiteId)
            ->value('id');

        if (! $allocationId) {
            throw new RuntimeException('Site allocation not found for consumption.');
        }

        return $this->creditConsumption->consumeSiteCredits($clientSiteId, $amount);
    }

    public function expireAddonCredits(int $limit = 200): int
    {
        $summary = $this->creditExpiration->expireCredits($limit);

        return (int) ($summary['expired_site_buckets'] + $summary['expired_workspace_buckets']);
    }

    private function getOrCreateLegacyWallet(string $clientSiteId, string $workspaceId): CreditWallet
    {
        return CreditWallet::query()->firstOrCreate(
            ['client_site_id' => $clientSiteId],
            [
                'workspace_id' => $workspaceId,
                'balance_cached' => 0,
                'reserved_cached' => 0,
            ]
        );
    }

    private function workspaceAllocationBuckets(string $workspaceId, bool $includeExpired = false)
    {
        $query = $this->creditConsumption
            ->orderedWorkspaceBucketsQuery($workspaceId, $includeExpired)
            ->whereIn('source', self::LEGACY_BUCKET_SOURCES);

        return $query->lockForUpdate();
    }

    private function allocationBucketsForReclaim(string $allocationId)
    {
        return SiteCreditAllocationBucket::query()
            ->where('site_credit_allocation_id', $allocationId)
            ->whereIn('source', self::LEGACY_BUCKET_SOURCES)
            ->where('remaining', '>', 0)
            ->orderByRaw("CASE source WHEN 'addon_pack' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(expires_at, "9999-12-31") DESC')
            ->orderByDesc('created_at')
            ->lockForUpdate();
    }

    private function activeAllocationBuckets(string $clientSiteId)
    {
        return $this->creditConsumption
            ->orderedSiteBucketsQuery($clientSiteId)
            ->whereIn('source', self::LEGACY_BUCKET_SOURCES);
    }

    private function createLegacyAllocationEntry(CreditWallet $legacyWallet, ClientSite $site, WorkspaceCreditTransaction $bucket, int $amount, array $metadata): void
    {
        CreditLedgerEntry::query()->create([
            'id' => (string) Str::uuid(),
            'credit_wallet_id' => $legacyWallet->id,
            'type' => $this->legacyLedgerTypeForBucket($bucket),
            'source' => (string) ($bucket->source ?: 'addon_pack'),
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => $bucket->expires_at,
            'source_type' => (string) ($bucket->reference_type ?: WorkspaceCreditTransaction::class),
            'source_id' => (string) ($bucket->reference_id ?: $bucket->id),
            'client_site_id' => $site->id,
            'organization_id' => $site->workspace?->organization_id,
            'meta' => array_merge($metadata, [
                'event' => 'site_allocation',
                'workspace_credit_transaction_id' => $bucket->id,
            ]),
            'idempotency_key' => $metadata['legacy_entry_idempotency_key'] ?? null,
        ]);
    }

    private function legacyLedgerTypeForBucket(WorkspaceCreditTransaction $bucket): string
    {
        return match ((string) data_get($bucket->metadata, 'credit_type', '')) {
            'allowance' => 'allowance',
            'pack_purchase' => 'pack_purchase',
            'refund' => 'refund',
            'adjustment' => 'adjustment',
            default => ((string) ($bucket->source ?: 'addon_pack')) === 'included_plan' ? 'allowance' : 'adjustment',
        };
    }

    private function returnReclaimedCreditsToWorkspace(ClientSite $site, SiteCreditAllocationBucket $bucket, int $amount, array $metadata): void
    {
        $workspaceTransactionId = $bucket->workspace_credit_transaction_id
            ?: data_get($bucket->metadata, 'workspace_credit_transaction_id');
        if ($workspaceTransactionId) {
            $workspaceBucket = WorkspaceCreditTransaction::query()
                ->whereKey($workspaceTransactionId)
                ->lockForUpdate()
                ->first();

            if ($workspaceBucket) {
                $workspaceBucket->remaining += $amount;
                $workspaceBucket->save();

                return;
            }
        }

        $workspaceWallet = WorkspaceCreditWallet::query()
            ->where('workspace_id', $site->workspace_id)
            ->lockForUpdate()
            ->firstOrFail();

        WorkspaceCreditTransaction::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_credit_wallet_id' => $workspaceWallet->id,
            'workspace_id' => $site->workspace_id,
            'organization_id' => $site->workspace?->organization_id,
            'type' => WorkspaceCreditLedgerService::TYPE_ALLOCATION_RETURN,
            'source' => (string) ($bucket->source ?: 'addon_pack'),
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => $bucket->expires_at,
            'reference_type' => SiteCreditAllocationBucket::class,
            'reference_id' => $bucket->id,
            'metadata' => array_merge($metadata, [
                'event' => 'site_allocation_return',
                'returned_from_site_id' => $site->id,
            ]),
        ]);
    }

    private function log(string $workspaceId, string $clientSiteId, string $action, int $amount, ?int $userId = null, array $metadata = [], ?string $fromClientSiteId = null, ?string $toClientSiteId = null): void
    {
        SiteCreditAllocationLog::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'client_site_id' => $clientSiteId,
            'from_client_site_id' => $fromClientSiteId,
            'to_client_site_id' => $toClientSiteId,
            'action' => $action,
            'amount' => $amount,
            'user_id' => $userId,
            'metadata' => $metadata,
        ]);
    }
}
