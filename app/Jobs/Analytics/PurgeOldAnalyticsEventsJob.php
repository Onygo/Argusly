<?php

namespace App\Jobs\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeOldAnalyticsEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $sites = AnalyticsSite::where('retention_days', '>', 0)->get();

        foreach ($sites as $site) {
            $cutoff = now()->subDays($site->retention_days);

            $deleted = DB::table('analytics_events')
                ->where('analytics_site_id', $site->id)
                ->where('event_time', '<', $cutoff)
                ->delete();

            if ($deleted > 0) {
                Log::info('Purged old analytics events', [
                    'site_id' => $site->id,
                    'retention_days' => $site->retention_days,
                    'deleted' => $deleted,
                ]);
            }

            // Also purge old rollups (keep 2x retention for historical data)
            $rollupCutoff = now()->subDays($site->retention_days * 2);

            DB::table('analytics_rollups_daily')
                ->where('analytics_site_id', $site->id)
                ->where('date', '<', $rollupCutoff)
                ->delete();
        }
    }
}
