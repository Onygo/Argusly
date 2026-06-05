<?php

namespace App\Console\Commands;

use App\Models\CreditPackPurchase;
use App\Services\CreditPackPurchaseService;
use Illuminate\Console\Command;

class PacksPurchase extends Command
{
    protected $signature = 'packs:purchase {client_site_id} {pack_key}';
    protected $description = 'Create a pending credit pack purchase for a client site.';

    public function handle(CreditPackPurchaseService $packs): int
    {
        $clientSiteId = (string) $this->argument('client_site_id');
        $packKey = (string) $this->argument('pack_key');

        $purchase = $packs->createPending($clientSiteId, $packKey);

        $this->info('Created purchase (pending).');
        $this->table(
            ['purchase_id', 'client_site_id', 'pack_key', 'status', 'credits', 'price_cents', 'currency'],
            [[
                (string) $purchase->id,
                (string) $purchase->client_site_id,
                (string) data_get($purchase->meta, 'pack_key', ''),
                (string) $purchase->status,
                (string) $purchase->credits_amount,
                (string) $purchase->price_cents,
                (string) $purchase->currency,
            ]]
        );

        return self::SUCCESS;
    }
}
