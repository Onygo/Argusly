<?php

namespace App\Console\Commands;

use App\Jobs\Stats\RecalculateContentMetricsJob;
use App\Services\Stats\AiSeoScoreCalculator;
use App\Services\Stats\ContentMetricsCalculator;
use Illuminate\Console\Command;

class RecalculateContentMetricsCommand extends Command
{
    protected $signature = 'stats:recalculate-content-metrics
        {--site= : Optional analytics_site_id}
        {--queue=default : Queue name when async}
        {--sync : Run immediately in this process}';

    protected $description = 'Recalculate scroll/read/ROI content metrics for tracked pages.';

    public function handle(ContentMetricsCalculator $calculator, AiSeoScoreCalculator $aiSeoCalculator): int
    {
        $siteId = trim((string) $this->option('site'));
        $siteId = $siteId !== '' ? $siteId : null;

        if ((bool) $this->option('sync')) {
            $count = $calculator->recalculate($siteId);
            $aiSeoCalculator->recalculate($siteId);
            $this->info('Content metrics recalculated rows: ' . $count);

            return self::SUCCESS;
        }

        RecalculateContentMetricsJob::dispatch($siteId)
            ->onQueue((string) $this->option('queue'));

        $this->info('Content metrics recalculation job queued.');

        return self::SUCCESS;
    }
}
