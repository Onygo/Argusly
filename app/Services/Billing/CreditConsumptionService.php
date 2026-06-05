<?php

namespace App\Services\Billing;

use App\Models\SiteCreditAllocationBucket;
use App\Models\WorkspaceCreditTransaction;
use App\ValueObjects\Billing\CreditBucket;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class CreditConsumptionService
{
    /**
     * Keep subscription credits first so purchased credits are preserved longer.
     * Within each source, consume the soonest-expiring and oldest buckets first.
     */
    public function orderedWorkspaceBucketsQuery(string $workspaceId, bool $includeExpired = false): Builder
    {
        $query = WorkspaceCreditTransaction::query()
            ->where('workspace_id', $workspaceId)
            ->where('remaining', '>', 0)
            ->whereIn('source', ['included_plan', 'addon_pack']);

        if (! $includeExpired) {
            $query->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        }

        return $query
            ->orderByRaw("CASE source WHEN 'included_plan' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(expires_at, "9999-12-31") ASC')
            ->orderBy('created_at');
    }

    public function orderedSiteBucketsQuery(string $clientSiteId): Builder
    {
        return SiteCreditAllocationBucket::query()
            ->where('client_site_id', $clientSiteId)
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->where('remaining', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByRaw("CASE source WHEN 'included_plan' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(expires_at, "9999-12-31") ASC')
            ->orderBy('created_at');
    }

    /**
     * @return array<int,array{bucket_entry_id:string,workspace_credit_transaction_id:string,source:string,amount:int}>
     */
    public function consumeSiteCredits(string $clientSiteId, int $amount): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Consumed amount must be positive.');
        }

        $remainingToConsume = $amount;
        $buckets = $this->orderedSiteBucketsQuery($clientSiteId)->lockForUpdate()->get();
        $allocations = [];

        foreach ($buckets as $bucket) {
            $bucketView = $this->toBucket($bucket);

            if ($remainingToConsume <= 0) {
                break;
            }

            $take = min($remainingToConsume, $bucketView->remaining);
            if ($take <= 0) {
                continue;
            }

            $bucket->remaining -= $take;
            $bucket->save();

            $allocations[] = [
                'bucket_entry_id' => (string) $bucket->id,
                'workspace_credit_transaction_id' => (string) ($bucket->workspace_credit_transaction_id ?? ''),
                'source' => (string) $bucket->source,
                'amount' => $take,
            ];

            $remainingToConsume -= $take;
        }

        if ($remainingToConsume > 0) {
            throw new RuntimeException('Insufficient consumable credits after allocation.');
        }

        return $allocations;
    }

    public function toBucket(SiteCreditAllocationBucket|WorkspaceCreditTransaction $bucket): CreditBucket
    {
        return new CreditBucket(
            id: (string) $bucket->id,
            source: (string) ($bucket->source ?? 'unknown'),
            remaining: (int) $bucket->remaining,
            expiresAt: $bucket->expires_at,
            createdAt: $bucket->created_at,
            workspaceCreditTransactionId: $bucket instanceof SiteCreditAllocationBucket
                ? (string) ($bucket->workspace_credit_transaction_id ?? '')
                : (string) $bucket->id,
            referenceType: (string) ($bucket->reference_type ?? ''),
            referenceId: (string) ($bucket->reference_id ?? ''),
        );
    }
}
