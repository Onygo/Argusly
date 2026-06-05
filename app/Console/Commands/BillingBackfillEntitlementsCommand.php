<?php

namespace App\Console\Commands;

use App\Models\CreditWallet;
use App\Models\Subscription;
use App\Services\Entitlements\EntitlementRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingBackfillEntitlementsCommand extends Command
{
    protected $signature = 'billing:backfill-entitlements {--dry-run} {--limit=500}';

    protected $description = 'Backfill workspace-scoped subscription links, wallet workspace links, and workspace entitlements.';

    public function handle(EntitlementRefreshService $entitlements): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $updatedSubscriptions = 0;
        $updatedWallets = 0;
        $refreshedWorkspaces = 0;

        $subscriptions = Subscription::query()->with('clientSite.workspace')->limit($limit)->get();
        foreach ($subscriptions as $subscription) {
            $workspaceId = $subscription->clientSite?->workspace_id;
            if ($workspaceId && $subscription->workspace_id !== $workspaceId) {
                $updatedSubscriptions++;
                if (! $dryRun) {
                    $subscription->workspace_id = $workspaceId;
                    $subscription->save();
                }
            }

            if (! $dryRun) {
                $entitlements->refreshForSubscription($subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription);
            }
            $refreshedWorkspaces++;
        }

        CreditWallet::query()->with('clientSite')->limit($limit)->chunkById(200, function ($wallets) use (&$updatedWallets, $dryRun): void {
            foreach ($wallets as $wallet) {
                $workspaceId = $wallet->clientSite?->workspace_id;
                if (! $workspaceId || $wallet->workspace_id === $workspaceId) {
                    continue;
                }

                $updatedWallets++;

                if (! $dryRun) {
                    $wallet->workspace_id = $workspaceId;
                    $wallet->save();
                }
            }
        }, 'id');

        if (! $dryRun) {
            DB::table('credit_wallet_transactions')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $workspaceId = DB::table('site_credit_allocations')
                            ->where('id', $row->credit_wallet_id)
                            ->value('workspace_id');

                        if ($workspaceId === $row->workspace_id) {
                            continue;
                        }

                        DB::table('credit_wallet_transactions')
                            ->where('id', $row->id)
                            ->update(['workspace_id' => $workspaceId]);
                    }
                }, 'id');
        }

        $this->table(['metric', 'count'], [
            ['subscriptions workspace linked', $updatedSubscriptions],
            ['wallets workspace linked', $updatedWallets],
            ['entitlement refresh runs', $refreshedWorkspaces],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
