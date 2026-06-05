<?php

namespace App\Jobs\Analytics;

use App\Models\AnalyticsRollupDaily;
use App\Models\AnalyticsSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BuildAnalyticsRollupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    private ?string $siteId;

    private ?Carbon $date;

    public function __construct(?string $siteId = null, ?Carbon $date = null)
    {
        $this->siteId = $siteId;
        $this->date = $date;
    }

    public function handle(): void
    {
        $lookbackDays = (int) config('analytics.rollup.lookback_days', 2);

        if ($this->siteId && $this->date) {
            // Process specific site and date
            $this->buildRollupForSiteAndDate($this->siteId, $this->date);

            return;
        }

        // Process all sites for lookback period
        $sites = AnalyticsSite::where('is_enabled', true)->pluck('id');

        foreach ($sites as $siteId) {
            for ($i = 0; $i < $lookbackDays; $i++) {
                $date = now()->subDays($i)->startOfDay();
                $this->buildRollupForSiteAndDate($siteId, $date);
            }
        }
    }

    private function buildRollupForSiteAndDate(string $siteId, Carbon $date): void
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Aggregate events by path
        $aggregates = DB::table('analytics_events')
            ->select([
                'path',
                'path_hash',
                DB::raw('MAX(title) as title'),
                DB::raw('MAX(article_id) as article_id'),
                DB::raw('COUNT(CASE WHEN event_type = \'page_view\' THEN 1 END) as page_views'),
                DB::raw('COUNT(DISTINCT CASE WHEN event_type = \'page_view\' THEN visitor_hash END) as unique_visitors'),
                DB::raw('COUNT(CASE WHEN event_type = \'scroll_50\' THEN 1 END) as scroll_50'),
                DB::raw('COUNT(CASE WHEN event_type = \'scroll_100\' THEN 1 END) as scroll_100'),
                DB::raw('COUNT(CASE WHEN event_type = \'heartbeat\' THEN 1 END) as heartbeats'),
            ])
            ->where('analytics_site_id', $siteId)
            ->whereBetween('event_time', [$startOfDay, $endOfDay])
            ->groupBy('path', 'path_hash')
            ->get();

        foreach ($aggregates as $agg) {
            // Calculate engaged views (page_view + at least one scroll or heartbeat)
            $engagedViews = $this->calculateEngagedViews($siteId, $agg->path_hash, $startOfDay, $endOfDay);

            // Calculate total time from heartbeats (15 sec each)
            $totalTimeSeconds = $agg->heartbeats * 15;

            // Upsert rollup using DB upsert for better concurrency
            DB::table('analytics_rollups_daily')->upsert(
                [
                    'analytics_site_id' => $siteId,
                    'date' => $date->toDateString(),
                    'path_hash' => $agg->path_hash,
                    'path' => $agg->path,
                    'article_id' => $agg->article_id,
                    'title' => $agg->title,
                    'page_views' => $agg->page_views,
                    'unique_visitors' => $agg->unique_visitors,
                    'scroll_50' => $agg->scroll_50,
                    'scroll_100' => $agg->scroll_100,
                    'heartbeats' => $agg->heartbeats,
                    'engaged_views' => $engagedViews,
                    'total_time_seconds' => $totalTimeSeconds,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['analytics_site_id', 'date', 'path_hash'],
                ['path', 'article_id', 'title', 'page_views', 'unique_visitors', 'scroll_50', 'scroll_100', 'heartbeats', 'engaged_views', 'total_time_seconds', 'updated_at']
            );
        }

        Log::info('Built analytics rollups', [
            'site_id' => $siteId,
            'date' => $date->toDateString(),
            'paths' => count($aggregates),
        ]);
    }

    private function calculateEngagedViews(string $siteId, string $pathHash, Carbon $start, Carbon $end): int
    {
        // A session is engaged if it has a page_view AND (scroll_50 OR scroll_100 OR heartbeat)
        return (int) DB::table('analytics_events as e1')
            ->where('e1.analytics_site_id', $siteId)
            ->where('e1.path_hash', $pathHash)
            ->whereBetween('e1.event_time', [$start, $end])
            ->where('e1.event_type', 'page_view')
            ->whereExists(function ($query) use ($siteId, $pathHash, $start, $end) {
                $query->select(DB::raw(1))
                    ->from('analytics_events as e2')
                    ->where('e2.analytics_site_id', $siteId)
                    ->where('e2.path_hash', $pathHash)
                    ->whereBetween('e2.event_time', [$start, $end])
                    ->whereColumn('e2.session_hash', 'e1.session_hash')
                    ->whereIn('e2.event_type', ['scroll_50', 'scroll_100', 'heartbeat']);
            })
            ->count(DB::raw('DISTINCT e1.session_hash'));
    }
}
