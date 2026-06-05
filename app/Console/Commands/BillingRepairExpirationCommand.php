<?php

namespace App\Console\Commands;

use App\Services\Billing\CreditExpirationService;
use Illuminate\Console\Command;

class BillingRepairExpirationCommand extends Command
{
    protected $signature = 'billing:repair-expiration {--limit=500} {--dry-run}';

    protected $description = 'Backfill missing credit expiration timestamps for subscription grants and purchased packs.';

    public function handle(CreditExpirationService $expiration): int
    {
        $result = $expiration->repairMissingExpirations(
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run')
        );

        $this->line('Scanned: ' . (int) $result['scanned']);
        $this->line('Repaired: ' . (int) $result['repaired']);

        return self::SUCCESS;
    }
}
