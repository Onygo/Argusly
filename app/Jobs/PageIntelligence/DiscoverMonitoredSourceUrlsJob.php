<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\MonitoredSource;
use App\Services\PageIntelligence\Discovery\MonitoredSourceUrlDiscoverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class DiscoverMonitoredSourceUrlsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(
        public readonly string $monitoredSourceId,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.discover', config('page_intelligence.discovery.queue', 'page_intelligence_discover')));
    }

    public function handle(MonitoredSourceUrlDiscoverer $discoverer): void
    {
        $source = MonitoredSource::query()->find($this->monitoredSourceId);

        if (! $source instanceof MonitoredSource) {
            return;
        }

        $discoverer->discover($source);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:discover:'.$this->monitoredSourceId))->releaseAfter(60)->expireAfter(900),
            new PageIntelligenceHostRateLimit,
        ];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $domain = (string) MonitoredSource::query()->whereKey($this->monitoredSourceId)->value('domain');

        return $domain !== '' ? 'page-intelligence-host:'.$domain : '';
    }
}
