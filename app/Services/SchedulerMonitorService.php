<?php

namespace App\Services;

use App\Models\VisibilityRunSchedule;
use Illuminate\Support\Facades\Cache;

class SchedulerMonitorService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $lastRun = Cache::get('scheduler:last_run_at');
        $dueVisibility = class_exists(VisibilityRunSchedule::class)
            ? VisibilityRunSchedule::query()->due()->count()
            : 0;

        return [
            'status' => $lastRun && now()->parse($lastRun)->gt(now()->subMinutes(10)) ? 'healthy' : 'warning',
            'last_run_at' => $lastRun,
            'due_visibility_schedules' => $dueVisibility,
            'monitored_commands' => [
                'visibility:run-due',
                'linkedin:check-token-health',
                'google:check-token-health',
                'queue:restart',
            ],
        ];
    }

    public function markHeartbeat(): void
    {
        Cache::put('scheduler:last_run_at', now()->toIso8601String(), now()->addDay());
    }
}
