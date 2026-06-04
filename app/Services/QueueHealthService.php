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
        return [
            'pending_count' => $this->pendingJobs()->count(),
            'failed_count' => $this->failedJobs()->count(),
            'pending_by_queue' => $this->pendingJobs()->groupBy('queue')->map->count(),
            'failed_by_queue' => $this->failedJobs()->groupBy('queue')->map->count(),
            'pending_jobs' => $this->pendingJobs()->take(20),
            'failed_jobs' => $this->failedJobs()->take(20),
            'heartbeats' => Schema::hasTable('worker_heartbeats')
                ? WorkerHeartbeat::query()->latest('last_seen_at')->limit(20)->get()
                : collect(),
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
}
