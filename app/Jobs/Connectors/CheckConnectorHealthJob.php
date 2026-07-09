<?php

namespace App\Jobs\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Services\DataConnectors\ConnectorHealthCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckConnectorHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public function __construct(public readonly string $connectorAccountId)
    {
    }

    public function handle(ConnectorHealthCheckService $health): void
    {
        $account = ConnectorAccount::query()->find($this->connectorAccountId);

        if (! $account instanceof ConnectorAccount) {
            return;
        }

        $health->check($account);
    }
}
