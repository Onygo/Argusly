<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\Seo\SearchConsoleIndexationSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSearchConsoleIndexationJob implements ShouldQueue
{
    use Queueable;

    public function handle(SearchConsoleIndexationSyncService $sync): void
    {
        $contents = Content::query()
            ->where('type', 'article')
            ->limit(200)
            ->get();

        $sync->sync($contents);
    }
}
