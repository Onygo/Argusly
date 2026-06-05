<?php

namespace App\Jobs;

use App\Models\SearchConsoleSite;
use App\Services\Integrations\Google\SearchConsolePerformanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSearchConsoleSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $searchConsoleSiteId,
        public readonly int $days = 30,
    ) {
        $this->onQueue(config('queue.names.integrations', 'integrations'));
    }

    public function handle(SearchConsolePerformanceService $searchConsole): void
    {
        $site = SearchConsoleSite::query()->findOrFail($this->searchConsoleSiteId);

        $searchConsole->sync($site, $this->days);
    }
}
