<?php

namespace App\Jobs\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorDriverManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DiscoverConnectorDatasetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly string $connectorAccountId)
    {
    }

    public function handle(ConnectorDriverManager $drivers): void
    {
        $account = ConnectorAccount::query()->find($this->connectorAccountId);

        if (! $account instanceof ConnectorAccount) {
            return;
        }

        $drivers->driver($account->provider_key)->discoverDatasets($account);
    }
}
