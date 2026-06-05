<?php

namespace App\Console\Commands;

use App\Models\CreditPackPurchase;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditWalletService;
use Illuminate\Console\Command;

class PacksMarkPendingPaid extends Command
{
    protected $signature = 'packs:mark-paid-pending {--limit=1000} {--dry-run}';
    protected $description = 'Mark all pending credit pack purchases as paid and add credits.';

    public function handle(
        CreditPackPurchaseService $packs,
        CreditWalletService $wallets
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $pending = CreditPackPurchase::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending purchases found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d pending purchase(s)%s',
            $dryRun ? 'Found' : 'Processing',
            $pending->count(),
            $dryRun ? ' (dry run)' : ''
        ));

        $rows = [];
        $processed = 0;
        $failed = 0;

        foreach ($pending as $purchase) {
            if ($dryRun) {
                $rows[] = [
                    (string) $purchase->id,
                    (string) $purchase->client_site_id,
                    (string) $purchase->credits_amount,
                    'pending',
                    'dry-run',
                ];
                continue;
            }

            try {
                $updated = $packs->markPaid($purchase, $wallets, (string) ($purchase->provider_payment_id ?? null));
                $processed++;

                $rows[] = [
                    (string) $updated->id,
                    (string) $updated->client_site_id,
                    (string) $updated->credits_amount,
                    (string) $updated->status,
                    'ok',
                ];
            } catch (\Throwable $exception) {
                $failed++;
                $rows[] = [
                    (string) $purchase->id,
                    (string) $purchase->client_site_id,
                    (string) $purchase->credits_amount,
                    (string) $purchase->status,
                    'error: ' . $exception->getMessage(),
                ];
            }
        }

        $this->table(
            ['purchase_id', 'client_site_id', 'credits', 'status', 'result'],
            $rows
        );

        if ($dryRun) {
            $this->comment('Dry run only. No records were changed.');
            return self::SUCCESS;
        }

        $this->info("Done. Processed: {$processed}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

