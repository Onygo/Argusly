<?php

namespace App\Jobs;

use App\Models\SourceSync;
use App\Services\SourceRegistryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunSourceSyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $sourceSyncId)
    {
        $this->onQueue(config('queue.names.integrations', 'integrations'));
    }

    public function handle(SourceRegistryService $sources): void
    {
        $sync = SourceSync::query()->with('source')->findOrFail($this->sourceSyncId);

        $sync->forceFill([
            'status' => 'running',
            'started_at' => $sync->started_at ?? now(),
            'error' => null,
        ])->save();

        try {
            $sources->completeSync($sync, 0);
        } catch (Throwable $exception) {
            $sync->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $exception->getMessage(),
                'health' => [
                    'status' => 'critical',
                    'error' => $exception->getMessage(),
                    'checked_at' => now()->toDateTimeString(),
                ],
            ])->save();

            throw $exception;
        }
    }
}
