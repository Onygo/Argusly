<?php

namespace App\Console\Commands\PageIntelligence;

use App\Jobs\PageIntelligence\DiscoverMonitoredSourceUrlsJob;
use App\Models\MonitoredSource;
use App\Services\PageIntelligence\Discovery\MonitoredSourceUrlDiscoverer;
use Illuminate\Console\Command;

class DiscoverMonitoredSourceUrlsCommand extends Command
{
    protected $signature = 'page-intelligence:discover
        {sourceId : Monitored source UUID}
        {--sync : Discover immediately instead of dispatching a queued job}';

    protected $description = 'Discover URLs from a monitored source and upsert canonical monitored pages.';

    public function handle(MonitoredSourceUrlDiscoverer $discoverer): int
    {
        $source = MonitoredSource::query()->find((string) $this->argument('sourceId'));
        if (! $source instanceof MonitoredSource) {
            $this->error('Monitored source not found.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('sync')) {
            DiscoverMonitoredSourceUrlsJob::dispatch((string) $source->id);

            $this->info('Monitored source discovery queued.');
            $this->line('Source: '.$source->id);
            $this->line('Type: '.$source->source_type);

            return self::SUCCESS;
        }

        $result = $discoverer->discover($source);

        if ($result->skipped) {
            $this->warn('Monitored source discovery skipped: '.$result->message);

            return self::SUCCESS;
        }

        if (! $result->successful) {
            $this->error('Monitored source discovery failed: '.$result->message);

            return self::FAILURE;
        }

        $this->info('Monitored source discovery completed.');
        $this->line('Source: '.$result->source->id);
        $this->line('Discovered: '.$result->discovered);
        $this->line('Created: '.$result->created);
        $this->line('Updated: '.$result->updated);
        $this->line('Fetch jobs queued: '.$result->fetchJobsQueued);
        $this->line('Failed URLs: '.$result->failedUrls);

        return self::SUCCESS;
    }
}
