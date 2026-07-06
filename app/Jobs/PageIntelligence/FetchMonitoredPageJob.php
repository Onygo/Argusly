<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\MonitoredPage;
use App\Services\PageIntelligence\PageFetcher;
use App\Services\PageIntelligence\PageIntelligencePipelineOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class FetchMonitoredPageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        public readonly string $monitoredPageId,
        public readonly ?string $requestedUrl = null,
        public readonly bool $continuePipeline = false,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.fetch', config('page_intelligence.fetch.queue', 'page_intelligence_fetch')));
    }

    public function handle(PageFetcher $fetcher, PageIntelligencePipelineOrchestrator $orchestrator): void
    {
        $page = MonitoredPage::query()->find($this->monitoredPageId);
        if (! $page instanceof MonitoredPage) {
            return;
        }

        $result = $fetcher->fetch($page, $this->requestedUrl);

        if ($this->continuePipeline && $result->successful) {
            $orchestrator->dispatchSnapshotPipeline($result->snapshot);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:fetch:'.$this->monitoredPageId))->releaseAfter(60)->expireAfter(600),
            new PageIntelligenceHostRateLimit,
        ];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $page = MonitoredPage::query()->find($this->monitoredPageId);

        return $page instanceof MonitoredPage ? 'page-intelligence-host:'.$page->domain : '';
    }
}
