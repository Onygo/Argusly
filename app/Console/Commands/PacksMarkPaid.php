<?php

namespace App\Console\Commands;

use App\Models\CreditPackPurchase;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditWalletService;
use Illuminate\Console\Command;
use RuntimeException;

class PacksMarkPaid extends Command
{
    protected $signature = 'packs:mark-paid {purchase_id} {--payment_id=}';
    protected $description = 'Mark a pending pack purchase as paid and add credits to wallet.';

    public function handle(
        CreditPackPurchaseService $packs,
        CreditWalletService $wallets
    ): int {
        $purchaseId = (string) $this->argument('purchase_id');
        $paymentId = $this->option('payment_id') ? (string) $this->option('payment_id') : null;

        $purchase = CreditPackPurchase::query()->find($purchaseId);
        if (! $purchase) {
            throw new RuntimeException('Purchase not found.');
        }

        $purchase = $packs->markPaid($purchase, $wallets, $paymentId);

        $this->info('Purchase marked paid.');
        $this->table(
            ['purchase_id', 'status', 'paid_at', 'workspace_tx_id', 'ledger_entry_id', 'provider_payment_id'],
            [[
                (string) $purchase->id,
                (string) $purchase->status,
                (string) ($purchase->paid_at ?? ''),
                (string) ($purchase->workspace_credit_transaction_id ?? ''),
                (string) ($purchase->credit_ledger_entry_id ?? ''),
                (string) ($purchase->provider_payment_id ?? ''),
            ]]
        );

        return self::SUCCESS;
    }
}
