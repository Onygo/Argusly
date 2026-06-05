<?php

namespace App\Services;

use App\Models\WorkerHeartbeat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queues = collect(config('queue.names'))->unique()->values();
        $pending = $this->pendingJobs();
        $failed = $this->failedJobs();
        $heartbeats = Schema::hasTable('worker_heartbeats')
            ? WorkerHeartbeat::query()->latest('last_seen_at')->limit(50)->get()
            : collect();

        return [
            'pending_count' => $pending->count(),
            'failed_count' => $failed->count(),
            'pending_by_queue' => $pending->groupBy('queue')->map->count(),
            'failed_by_queue' => $failed->groupBy('queue')->map->count(),
            'queue_matrix' => $queues->map(fn (string $queue): array => [
                'name' => $queue,
                'pending' => $pending->where('queue', $queue)->count(),
                'failed' => $failed->where('queue', $queue)->count(),
                'workers' => $heartbeats->where('queue', $queue)->count(),
                'status' => $this->queueStatus($queue, $pending, $failed, $heartbeats),
            ]),
            'pending_jobs' => $pending->take(20),
            'failed_jobs' => $failed->take(20),
            'heartbeats' => $heartbeats,
            'stale_heartbeats' => $heartbeats->filter(fn (WorkerHeartbeat $heartbeat): bool => $heartbeat->last_seen_at?->lt(now()->subMinutes(5)) ?? true),
        ];
    }

    public function retryFailedJob(int $id): bool
    {
        if (! Schema::hasTable('failed_jobs')) {
            return false;
        }

        return DB::table('failed_jobs')->where('id', $id)->exists();
    }

    private function pendingJobs(): Collection
    {
        if (! Schema::hasTable('jobs')) {
            return collect();
        }

        return DB::table('jobs')
            ->orderBy('available_at')
            ->limit(100)
            ->get()
            ->map(function (object $job): object {
                $job->available_at_human = date('Y-m-d H:i:s', (int) $job->available_at);
                $job->created_at_human = date('Y-m-d H:i:s', (int) $job->created_at);

                return $job;
            });
    }

    private function failedJobs(): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit(100)
            ->get();
    }

    private function queueStatus(string $queue, Collection $pending, Collection $failed, Collection $heartbeats): string
    {
        if ($failed->where('queue', $queue)->count() > 0) {
            return 'critical';
        }

        if ($pending->where('queue', $queue)->count() > 50) {
            return 'warning';
        }

        $stale = $heartbeats
            ->where('queue', $queue)
            ->filter(fn (WorkerHeartbeat $heartbeat): bool => $heartbeat->last_seen_at?->lt(now()->subMinutes(5)) ?? true)
            ->isNotEmpty();

        return $stale ? 'warning' : 'healthy';
    }
}
