<?php

namespace App\Services\PageIntelligence;

use App\Jobs\PageIntelligence\AnalyzePageEntitiesJob;
use App\Jobs\PageIntelligence\AnalyzePageSentimentJob;
use App\Jobs\PageIntelligence\CalculateBasicPageScoresJob;
use App\Jobs\PageIntelligence\CalculatePagePrValueJob;
use App\Jobs\PageIntelligence\ClassifyPageTopicsJob;
use App\Jobs\PageIntelligence\DiscoverMonitoredSourceUrlsJob;
use App\Jobs\PageIntelligence\EmitPageSignalsJob;
use App\Jobs\PageIntelligence\EvaluatePageAlertRulesJob;
use App\Jobs\PageIntelligence\ExtractPageContentJob;
use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Jobs\PageIntelligence\RunPageRelationshipMatchingJob;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageSnapshot;
use Illuminate\Support\Facades\Bus;

class PageIntelligencePipelineOrchestrator
{
    public function dispatchDiscovery(MonitoredSource $source): void
    {
        DiscoverMonitoredSourceUrlsJob::dispatch((string) $source->id);
    }

    public function dispatchFetch(MonitoredPage $page, ?string $requestedUrl = null): void
    {
        FetchMonitoredPageJob::dispatch((string) $page->id, $requestedUrl, continuePipeline: true);
    }

    public function dispatchSnapshotPipeline(PageSnapshot $snapshot): void
    {
        Bus::chain([
            new ExtractPageContentJob((string) $snapshot->id),
            new AnalyzePageEntitiesJob((string) $snapshot->id),
            new ClassifyPageTopicsJob((string) $snapshot->id),
            new AnalyzePageSentimentJob((string) $snapshot->id),
            new RunPageRelationshipMatchingJob((string) $snapshot->monitored_page_id),
            new CalculateBasicPageScoresJob((string) $snapshot->id),
            new CalculatePagePrValueJob((string) $snapshot->id),
            new EmitPageSignalsJob((string) $snapshot->id),
            new EvaluatePageAlertRulesJob(),
        ])->dispatch();
    }
}
