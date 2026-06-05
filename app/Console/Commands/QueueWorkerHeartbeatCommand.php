<?php

namespace App\Console\Commands;

use App\Support\QueueWorkerHeartbeat;
use Illuminate\Console\Command;

class QueueWorkerHeartbeatCommand extends Command
{
    protected $signature = 'queue:worker-heartbeat';
    protected $description = 'Update queue worker heartbeat cache timestamp for admin health checks.';

    public function handle(): int
    {
        QueueWorkerHeartbeat::touch();

        return self::SUCCESS;
    }
}
