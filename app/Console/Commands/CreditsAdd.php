<?php

namespace App\Console\Commands;

use App\Services\CreditWalletService;
use Illuminate\Console\Command;

class CreditsAdd extends Command
{
    protected $signature = 'credits:add {client_site_id} {amount} {--type=adjustment}';
    protected $description = 'Add credits to a wallet (allowance, pack_purchase, refund, adjustment).';

    public function handle(CreditWalletService $credits): int
    {
        $clientSiteId = (string) $this->argument('client_site_id');
        $amount = (int) $this->argument('amount');
        $type = (string) $this->option('type');

        $entry = $credits->addCredits(
            clientSiteId: $clientSiteId,
            amount: $amount,
            type: $type,
            meta: ['source' => 'cli']
        );

        $this->info('Added credits.');
        $this->line('ledger_entry_id: ' . $entry->id);

        return self::SUCCESS;
    }
}
