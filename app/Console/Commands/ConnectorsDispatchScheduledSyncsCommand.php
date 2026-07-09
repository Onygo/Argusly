<?php

namespace App\Console\Commands;

use App\Services\DataConnectors\ConnectorSyncScheduler;
use Illuminate\Console\Command;

class ConnectorsDispatchScheduledSyncsCommand extends Command
{
    protected $signature = 'connectors:dispatch-scheduled-syncs {--limit=100} {--queue=default}';

    protected $description = 'Dispatch due scheduled data connector sync jobs.';

    public function handle(ConnectorSyncScheduler $scheduler): int
    {
        $dispatched = $scheduler->dispatchDue(
            limit: max(1, (int) $this->option('limit')),
            queue: (string) $this->option('queue') ?: null,
        );

        $this->info("Dispatched {$dispatched} connector sync job(s).");

        return self::SUCCESS;
    }
}
