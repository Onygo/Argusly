<?php

namespace App\Console\Commands;

use App\Models\CreditReservation;
use App\Models\SiteCreditAllocation;
use App\Models\SiteCreditAllocationBucket;
use App\Models\WorkspaceCreditTransaction;
use App\Models\WorkspaceCreditWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingRebuildBalancesCommand extends Command
{
    protected $signature = 'billing:rebuild-balances {--dry-run}';

    protected $description = 'Rebuild cached site allocation and workspace wallet balances from buckets and active reservations.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteUpdates = 0;
        $workspaceUpdates = 0;

        SiteCreditAllocation::query()->orderBy('id')->chunkById(200, function ($allocations) use ($dryRun, &$siteUpdates): void {
            foreach ($allocations as $allocation) {
                $allocated = (int) SiteCreditAllocationBucket::query()
                    ->where('site_credit_allocation_id', $allocation->id)
                    ->where('remaining', '>', 0)
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->sum('remaining');

                $reserved = (int) CreditReservation::query()
                    ->where('site_credit_allocation_id', $allocation->id)
                    ->where('status', CreditReservation::STATUS_RESERVED)
                    ->sum('amount');

                $used = (int) abs((int) WorkspaceCreditTransaction::query()
                    ->where('site_credit_allocation_id', $allocation->id)
                    ->where('type', 'commit')
                    ->sum('amount'));

                if (! $dryRun) {
                    $allocation->forceFill([
                        'allocated_credits' => $allocated,
                        'reserved_cached' => $reserved,
                        'used_cached' => $used,
                    ])->save();
                }

                $siteUpdates++;
            }
        }, 'id');

        WorkspaceCreditWallet::query()->orderBy('id')->chunkById(200, function ($wallets) use ($dryRun, &$workspaceUpdates): void {
            foreach ($wallets as $wallet) {
                $allocated = (int) SiteCreditAllocation::query()
                    ->where('workspace_id', $wallet->workspace_id)
                    ->sum('allocated_credits');

                $unallocated = (int) WorkspaceCreditTransaction::query()
                    ->where('workspace_id', $wallet->workspace_id)
                    ->where('remaining', '>', 0)
                    ->whereIn('source', ['included_plan', 'addon_pack'])
                    ->whereNotIn('id', SiteCreditAllocationBucket::query()
                        ->select('workspace_credit_transaction_id')
                        ->whereNotNull('workspace_credit_transaction_id'))
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->sum('remaining');

                $reserved = (int) CreditReservation::query()
                    ->where('workspace_id', $wallet->workspace_id)
                    ->where('status', CreditReservation::STATUS_RESERVED)
                    ->sum('amount');

                if (! $dryRun) {
                    $wallet->forceFill([
                        'balance_cached' => $allocated + $unallocated,
                        'reserved_cached' => $reserved,
                    ])->save();
                }

                $workspaceUpdates++;
            }
        }, 'id');

        $this->line('Site allocations rebuilt: ' . $siteUpdates);
        $this->line('Workspace wallets rebuilt: ' . $workspaceUpdates);

        return self::SUCCESS;
    }
}
