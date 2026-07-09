<?php

namespace App\Console\Commands;

use App\Jobs\Connectors\CheckConnectorHealthJob;
use App\Models\Connectors\ConnectorAccount;
use Illuminate\Console\Command;

class ConnectorsHealthCheckCommand extends Command
{
    protected $signature = 'connectors:health-check {--limit=100} {--queue=default}';

    protected $description = 'Dispatch health checks for connected data connector accounts.';

    public function handle(): int
    {
        $queue = (string) $this->option('queue') ?: null;
        $count = 0;

        ConnectorAccount::query()
            ->whereIn('status', [
                ConnectorAccount::STATUS_CONNECTED,
                ConnectorAccount::STATUS_EXPIRED,
                ConnectorAccount::STATUS_ERROR,
            ])
            ->orderByRaw('COALESCE(health_checked_at, connected_at, created_at)')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id')
            ->each(function (string $accountId) use (&$count, $queue): void {
                $job = new CheckConnectorHealthJob($accountId);

                if ($queue) {
                    $job->onQueue($queue);
                }

                dispatch($job);
                $count++;
            });

        $this->info("Dispatched {$count} connector health check job(s).");

        return self::SUCCESS;
    }
}
