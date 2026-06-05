<?php

namespace App\Jobs;

use App\Models\WorkerHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordWorkerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $workerName,
        public ?string $queueName = null,
        public string $status = 'running',
        public array $metadata = [],
    ) {
        $this->onQueue(config('queue.names.maintenance', 'maintenance'));
    }

    public function handle(): void
    {
        WorkerHeartbeat::query()->updateOrCreate(
            ['worker_name' => $this->workerName, 'queue' => $this->queueName],
            ['status' => $this->status, 'metadata' => $this->metadata, 'last_seen_at' => now()],
        );
    }
}
