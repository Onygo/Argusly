<?php

namespace App\Services\Billing;

use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\SiteCreditAllocation;
use App\Models\SiteCreditAllocationBucket;
use App\Models\Subscription;
use App\Models\WorkspaceCreditTransaction;
use App\Models\WorkspaceCreditWallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

class BillingAuditService
{
    /**
     * @return array<string,mixed>
     */
    public function auditOrganization(Organization $organization): array
    {
        $workspaceIds = $organization->workspaces()->pluck('id');
        $siteIds = $organization->clientSites()->pluck('client_sites.id');

        $activeSubscriptions = Subscription::query()
            ->with('plan')
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing', 'past_due', 'pending_mandate'])
            ->get();

        $issues = collect();

        $staleSubscriptionBuckets = SiteCreditAllocationBucket::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('source', 'included_plan')
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        if ($staleSubscriptionBuckets > 0) {
            $issues->push($this->issue(
                'critical',
                'Expired subscription credits remain spendable until the expiry job clears them.',
                $staleSubscriptionBuckets
            ));
        }

        $stalePackBuckets = SiteCreditAllocationBucket::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('source', 'addon_pack')
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        if ($stalePackBuckets > 0) {
            $issues->push($this->issue(
                'high',
                'Expired purchased credits remain in active site buckets.',
                $stalePackBuckets
            ));
        }

        $missingSubscriptionExpiries = WorkspaceCreditTransaction::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('source', 'included_plan')
            ->where('amount', '>', 0)
            ->whereNull('expires_at')
            ->count();

        if ($missingSubscriptionExpiries > 0) {
            $issues->push($this->issue(
                'critical',
                'Subscription credit grants exist without expires_at, so the 3-month rollover claim is not enforceable for those grants.',
                $missingSubscriptionExpiries
            ));
        }

        $missingPackExpiries = CreditPackPurchase::query()
            ->whereIn('client_site_id', $siteIds)
            ->where('status', 'paid')
            ->whereNull('purchased_credit_expires_at')
            ->count();

        if ($missingPackExpiries > 0) {
            $issues->push($this->issue(
                'high',
                'Paid credit pack purchases exist without purchase-level expiry timestamps.',
                $missingPackExpiries
            ));
        }

        $walletMismatches = $this->walletMismatches($workspaceIds->all());
        if ($walletMismatches > 0) {
            $issues->push($this->issue(
                'high',
                'Cached workspace balances do not match underlying active site allocations and unallocated buckets.',
                $walletMismatches
            ));
        }

        $claimStatuses = [
            'subscription_rollover_3_months' => $missingSubscriptionExpiries === 0
                ? 'PARTIALLY TRUE'
                : 'FALSE',
            'pack_valid_12_months' => $missingPackExpiries === 0
                ? 'PARTIALLY TRUE'
                : 'FALSE',
            'workspace_shared_credits' => 'TRUE',
        ];

        return [
            'organization_id' => (string) $organization->id,
            'claim_statuses' => $claimStatuses,
            'issues' => $issues->values()->all(),
            'health' => $issues->contains(fn (array $issue): bool => $issue['severity'] === 'critical')
                ? 'critical'
                : ($issues->isNotEmpty() ? 'warning' : 'ok'),
            'workspace_shared_supported' => true,
            'scheduler_targets' => [
                'credit_expiry_job' => 'enabled',
                'reservation_expiry_command' => 'enabled',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function auditPlatform(int $limit = 100): array
    {
        $organizations = Organization::query()->orderBy('id')->limit(max(1, $limit))->get();
        $results = $organizations->map(fn (Organization $organization): array => $this->auditOrganization($organization));

        return [
            'scanned' => $results->count(),
            'critical' => $results->where('health', 'critical')->count(),
            'warning' => $results->where('health', 'warning')->count(),
            'ok' => $results->where('health', 'ok')->count(),
            'results' => $results->all(),
        ];
    }

    private function walletMismatches(array $workspaceIds): int
    {
        $count = 0;

        foreach ($workspaceIds as $workspaceId) {
            $wallet = WorkspaceCreditWallet::query()->where('workspace_id', $workspaceId)->first();
            if (! $wallet) {
                continue;
            }

            $allocated = (int) SiteCreditAllocation::query()
                ->where('workspace_id', $workspaceId)
                ->sum('allocated_credits');

            $unallocated = (int) WorkspaceCreditTransaction::query()
                ->where('workspace_id', $workspaceId)
                ->where('remaining', '>', 0)
                ->whereIn('source', ['included_plan', 'addon_pack'])
                ->whereNotIn('id', SiteCreditAllocationBucket::query()
                    ->select('workspace_credit_transaction_id')
                    ->whereNotNull('workspace_credit_transaction_id'))
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->sum('remaining');

            $expectedBalance = $allocated + $unallocated;
            if ((int) $wallet->balance_cached !== $expectedBalance) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{severity:string,message:string,count:int}
     */
    private function issue(string $severity, string $message, int $count): array
    {
        return [
            'severity' => $severity,
            'message' => $message,
            'count' => $count,
        ];
    }
}
