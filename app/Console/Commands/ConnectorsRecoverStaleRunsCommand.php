<?php

namespace App\Console\Commands;

use App\Services\DataConnectors\ConnectorSyncRunLogger;
use Illuminate\Console\Command;

class ConnectorsRecoverStaleRunsCommand extends Command
{
    protected $signature = 'connectors:recover-stale-runs {--limit=100}';

    protected $description = 'Mark stale running connector sync runs as failed.';

    public function handle(ConnectorSyncRunLogger $runs): int
    {
        $recovered = $runs->recoverStaleRunning(limit: max(1, (int) $this->option('limit')));

        $this->info("Recovered {$recovered} stale connector sync run(s).");

        return self::SUCCESS;
    }
}
