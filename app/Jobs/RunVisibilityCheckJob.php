<?php

namespace App\Jobs;

use App\Models\VisibilityCheck;
use App\Services\VisibilityMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunVisibilityCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $visibilityCheckId) {}

    public function handle(VisibilityMonitoringService $visibility): void
    {
        $check = VisibilityCheck::query()->findOrFail($this->visibilityCheckId);

        $visibility->runPlaceholderCheck($check);
    }
}
