<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\BuildAnalyticsRollupsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildAnalyticsRollupsCommand extends Command
{
    protected $signature = 'analytics:build-rollups
                            {--site= : Specific analytics site ID}
                            {--date= : Specific date (Y-m-d)}
                            {--days=2 : Number of days to look back}';

    protected $description = 'Build analytics rollups from raw events';

    public function handle(): int
    {
        $siteId = $this->option('site');
        $dateStr = $this->option('date');
        $days = (int) $this->option('days');

        if ($dateStr) {
            $date = Carbon::parse($dateStr);
            $this->info("Building rollups for date: {$date->toDateString()}");

            if ($siteId) {
                BuildAnalyticsRollupsJob::dispatchSync($siteId, $date);
            } else {
                BuildAnalyticsRollupsJob::dispatchSync(null, $date);
            }
        } else {
            $this->info("Building rollups for last {$days} days");
            BuildAnalyticsRollupsJob::dispatchSync($siteId);
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
