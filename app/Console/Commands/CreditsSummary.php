<?php

namespace App\Console\Commands;

use App\Services\CreditWalletService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CreditsSummary extends Command
{
    protected $signature = 'credits:summary {client_site_id} {--days=30} {--ledger=25}';
    protected $description = 'Show credit wallet summary, usage breakdown, and recent ledger entries for a client site.';

    public function handle(CreditWalletService $credits): int
    {
        $clientSiteId = (string) $this->argument('client_site_id');
        $days = (int) $this->option('days');
        $ledgerLimit = (int) $this->option('ledger');

        if ($days <= 0) {
            $days = 30;
        }

        if ($ledgerLimit <= 0) {
            $ledgerLimit = 25;
        }

        $summary = $credits->getSummary($clientSiteId);

        $from = Carbon::now()->subDays($days);
        $to = Carbon::now();

        $usage = $credits->getUsageByAction($clientSiteId, $from, $to);

        $ledger = $credits->getLedger($clientSiteId, $ledgerLimit);

        $this->info('Credit summary for client_site_id: ' . $clientSiteId);
        $this->line('');

        $this->line('Wallet');
        $this->table(
            ['wallet_id', 'balance_cached', 'reserved_cached', 'available'],
            [[
                $summary['wallet_id'] ?? '',
                (string) ($summary['balance_cached'] ?? 0),
                (string) ($summary['reserved_cached'] ?? 0),
                (string) ($summary['available'] ?? 0),
            ]]
        );

        $this->line('');
        $this->line('Usage last ' . $days . ' days');

        $rows = [];
        foreach (($usage['by_action_id'] ?? []) as $actionId => $used) {
            $rows[] = [$actionId, (string) $used];
        }

        if (count($rows) === 0) {
            $this->line('No usage entries found.');
        } else {
            $this->table(['credit_action_id', 'credits_used'], $rows);
        }

        $this->line('');
        $this->line('Recent ledger entries');

        $ledgerRows = [];
        foreach ($ledger as $entry) {
            $ledgerRows[] = [
                (string) $entry->created_at,
                (string) $entry->type,
                (string) $entry->amount,
                (string) ($entry->expires_at ?? ''),
                (string) ($entry->source_type ?? ''),
                (string) ($entry->source_id ?? ''),
            ];
        }

        if (count($ledgerRows) === 0) {
            $this->line('No ledger entries found.');
        } else {
            $this->table(
                ['created_at', 'type', 'amount', 'expires_at', 'source_type', 'source_id'],
                $ledgerRows
            );
        }

        return self::SUCCESS;
    }
}
