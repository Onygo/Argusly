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
        public ?string $queue = null,
        public string $status = 'running',
        public array $metadata = [],
    ) {}

    public function handle(): void
    {
        WorkerHeartbeat::query()->updateOrCreate(
            ['worker_name' => $this->workerName, 'queue' => $this->queue],
            ['status' => $this->status, 'metadata' => $this->metadata, 'last_seen_at' => now()],
        );
    }
}
