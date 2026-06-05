<?php

namespace App\Services\Billing;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPackPurchase;
use App\Models\SiteCreditAllocation;
use App\Models\SiteCreditAllocationBucket;
use App\Models\Subscription;
use App\Models\WorkspaceCreditTransaction;
use App\Models\WorkspaceCreditWallet;
use App\Services\Credits\WorkspaceCreditLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreditExpirationService
{
    public function __construct(
        private readonly CreditPolicyService $policy
    ) {
    }

    /**
     * @return array{expired_site_buckets:int,expired_workspace_buckets:int,expired_credits:int}
     */
    public function expireCredits(int $limit = 200): array
    {
        $summary = [
            'expired_site_buckets' => 0,
            'expired_workspace_buckets' => 0,
            'expired_credits' => 0,
        ];

        $siteBuckets = SiteCreditAllocationBucket::query()
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        foreach ($siteBuckets as $bucket) {
            $expiredAmount = $this->expireSiteBucket($bucket);
            if ($expiredAmount <= 0) {
                continue;
            }

            $summary['expired_site_buckets']++;
            $summary['expired_credits'] += $expiredAmount;
        }

        $remainingLimit = max(0, $limit - $summary['expired_site_buckets']);
        if ($remainingLimit === 0) {
            return $summary;
        }

        $workspaceBuckets = WorkspaceCreditTransaction::query()
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($remainingLimit)
            ->get();

        foreach ($workspaceBuckets as $bucket) {
            $expiredAmount = $this->expireWorkspaceBucket($bucket);
            if ($expiredAmount <= 0) {
                continue;
            }

            $summary['expired_workspace_buckets']++;
            $summary['expired_credits'] += $expiredAmount;
        }

        return $summary;
    }

    /**
     * @return array{scanned:int,repaired:int}
     */
    public function repairMissingExpirations(int $limit = 500, bool $dryRun = false): array
    {
        $summary = ['scanned' => 0, 'repaired' => 0];

        $workspaceTransactions = WorkspaceCreditTransaction::query()
            ->where('amount', '>', 0)
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->whereNull('expires_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($workspaceTransactions as $transaction) {
            $summary['scanned']++;
            $expiresAt = $this->resolveExpectedExpiryForTransaction($transaction);
            if (! $expiresAt) {
                continue;
            }

            if (! $dryRun) {
                $this->applyExpiryToTransactionFamily($transaction, $expiresAt);
            }

            $summary['repaired']++;
        }

        return $summary;
    }

    private function expireSiteBucket(SiteCreditAllocationBucket $bucket): int
    {
        return DB::transaction(function () use ($bucket): int {
            $lockedBucket = SiteCreditAllocationBucket::query()->whereKey($bucket->id)->lockForUpdate()->first();
            if (! $lockedBucket || (int) $lockedBucket->remaining <= 0) {
                return 0;
            }

            $allocation = SiteCreditAllocation::query()
                ->whereKey($lockedBucket->site_credit_allocation_id)
                ->lockForUpdate()
                ->first();

            $workspaceWallet = WorkspaceCreditWallet::query()
                ->where('workspace_id', $lockedBucket->workspace_id)
                ->lockForUpdate()
                ->first();

            if (! $allocation || ! $workspaceWallet) {
                return 0;
            }

            $amountToExpire = (int) $lockedBucket->remaining;
            $lockedBucket->remaining = 0;
            $lockedBucket->save();

            $allocation->allocated_credits = max(0, (int) $allocation->allocated_credits - $amountToExpire);
            $allocation->save();

            $workspaceWallet->balance_cached = max(0, (int) $workspaceWallet->balance_cached - $amountToExpire);
            $workspaceWallet->save();

            WorkspaceCreditTransaction::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_credit_wallet_id' => $workspaceWallet->id,
                'workspace_id' => $lockedBucket->workspace_id,
                'organization_id' => $workspaceWallet->organization_id,
                'client_site_id' => $lockedBucket->client_site_id,
                'site_credit_allocation_id' => $lockedBucket->site_credit_allocation_id,
                'type' => WorkspaceCreditLedgerService::TYPE_EXPIRE,
                'source' => $lockedBucket->source,
                'amount' => -$amountToExpire,
                'remaining' => 0,
                'reference_type' => SiteCreditAllocationBucket::class,
                'reference_id' => $lockedBucket->id,
                'metadata' => [
                    'reason' => 'expiry',
                    'expired_bucket_id' => $lockedBucket->id,
                    'expired_source' => $lockedBucket->source,
                ],
            ]);

            return $amountToExpire;
        });
    }

    private function expireWorkspaceBucket(WorkspaceCreditTransaction $bucket): int
    {
        return DB::transaction(function () use ($bucket): int {
            $lockedBucket = WorkspaceCreditTransaction::query()->whereKey($bucket->id)->lockForUpdate()->first();
            if (! $lockedBucket || (int) $lockedBucket->remaining <= 0) {
                return 0;
            }

            $hasAllocationBuckets = SiteCreditAllocationBucket::query()
                ->where('workspace_credit_transaction_id', $lockedBucket->id)
                ->where('remaining', '>', 0)
                ->exists();

            if ($hasAllocationBuckets) {
                return 0;
            }

            $workspaceWallet = WorkspaceCreditWallet::query()
                ->whereKey($lockedBucket->workspace_credit_wallet_id)
                ->lockForUpdate()
                ->first();

            if (! $workspaceWallet) {
                return 0;
            }

            $amountToExpire = (int) $lockedBucket->remaining;
            $lockedBucket->remaining = 0;
            $lockedBucket->save();

            $workspaceWallet->balance_cached = max(0, (int) $workspaceWallet->balance_cached - $amountToExpire);
            $workspaceWallet->save();

            WorkspaceCreditTransaction::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_credit_wallet_id' => $workspaceWallet->id,
                'workspace_id' => $lockedBucket->workspace_id,
                'organization_id' => $workspaceWallet->organization_id,
                'type' => WorkspaceCreditLedgerService::TYPE_EXPIRE,
                'source' => $lockedBucket->source,
                'amount' => -$amountToExpire,
                'remaining' => 0,
                'reference_type' => WorkspaceCreditTransaction::class,
                'reference_id' => $lockedBucket->id,
                'metadata' => [
                    'reason' => 'expiry',
                    'expired_workspace_bucket_id' => $lockedBucket->id,
                    'expired_source' => $lockedBucket->source,
                ],
            ]);

            return $amountToExpire;
        });
    }

    private function resolveExpectedExpiryForTransaction(WorkspaceCreditTransaction $transaction): ?Carbon
    {
        return match ((string) $transaction->source) {
            'addon_pack' => $this->resolvePackExpiry($transaction),
            'included_plan' => $this->resolveSubscriptionExpiry($transaction),
            default => null,
        };
    }

    private function resolvePackExpiry(WorkspaceCreditTransaction $transaction): ?Carbon
    {
        $purchaseId = (string) ($transaction->reference_id ?: data_get($transaction->metadata, 'purchase_id', ''));
        if ($purchaseId === '') {
            return null;
        }

        $purchase = CreditPackPurchase::query()->with('creditPack')->find($purchaseId);
        if (! $purchase || ! $purchase->creditPack) {
            return null;
        }

        return $purchase->purchased_credit_expires_at
            ?: $this->policy->resolvePackExpiryAt($purchase->creditPack, $purchase->paid_at?->copy());
    }

    private function resolveSubscriptionExpiry(WorkspaceCreditTransaction $transaction): ?Carbon
    {
        $subscriptionId = (string) ($transaction->reference_id ?: data_get($transaction->metadata, 'subscription_id', ''));
        if ($subscriptionId === '') {
            return null;
        }

        $subscription = Subscription::query()->with('plan')->find($subscriptionId);
        if (! $subscription) {
            return null;
        }

        $periodStart = $this->parseCarbon(data_get($transaction->metadata, 'period_start'));
        $periodEnd = $this->parseCarbon(data_get($transaction->metadata, 'period_end'));

        return $this->policy->resolveSubscriptionGrantExpiryAt($subscription, $periodStart, $periodEnd);
    }

    private function applyExpiryToTransactionFamily(WorkspaceCreditTransaction $transaction, Carbon $expiresAt): void
    {
        DB::transaction(function () use ($transaction, $expiresAt): void {
            WorkspaceCreditTransaction::query()
                ->whereKey($transaction->id)
                ->update(['expires_at' => $expiresAt, 'updated_at' => now()]);

            SiteCreditAllocationBucket::query()
                ->where('workspace_credit_transaction_id', $transaction->id)
                ->update(['expires_at' => $expiresAt, 'updated_at' => now()]);

            $legacyReferenceType = (string) ($transaction->reference_type ?? '');
            $legacyReferenceId = (string) ($transaction->reference_id ?? '');
            $legacyType = $transaction->source === 'included_plan' ? 'allowance' : 'pack_purchase';

            if ($legacyReferenceType !== '' && $legacyReferenceId !== '') {
                CreditLedgerEntry::query()
                    ->where('source_type', $legacyReferenceType)
                    ->where('source_id', $legacyReferenceId)
                    ->where('type', $legacyType)
                    ->where('amount', '>', 0)
                    ->update(['expires_at' => $expiresAt, 'updated_at' => now()]);
            }

            if ($transaction->source === 'addon_pack') {
                CreditPackPurchase::query()
                    ->where('id', $legacyReferenceId)
                    ->update(['purchased_credit_expires_at' => $expiresAt, 'updated_at' => now()]);
            }
        });
    }

    private function parseCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $exception) {
            Log::warning('billing_credit_expiry_parse_failed', [
                'value' => $value,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
