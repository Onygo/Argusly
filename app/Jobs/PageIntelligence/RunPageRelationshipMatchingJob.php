<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\MonitoredPage;
use App\Services\PageIntelligence\Matching\PageBrandMatcher;
use App\Services\PageIntelligence\Matching\PageCampaignMatcher;
use App\Services\PageIntelligence\Matching\PageCompetitorMatcher;
use App\Services\PageIntelligence\Matching\PageMarketPackMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunPageRelationshipMatchingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(public readonly string $monitoredPageId)
    {
        $this->onQueue((string) config('page_intelligence.queues.analyze', 'page_intelligence_analyze'));
    }

    public function handle(
        PageBrandMatcher $brandMatcher,
        PageCampaignMatcher $campaignMatcher,
        PageCompetitorMatcher $competitorMatcher,
        PageMarketPackMatcher $marketPackMatcher,
    ): void {
        $page = MonitoredPage::query()->find($this->monitoredPageId);
        if (! $page instanceof MonitoredPage) {
            return;
        }

        $brandMatcher->match($page);
        $campaignMatcher->match($page);
        $competitorMatcher->match($page);
        $marketPackMatcher->match($page);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:match:'.$this->monitoredPageId))->releaseAfter(60)->expireAfter(600),
            new PageIntelligenceHostRateLimit,
        ];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $domain = (string) MonitoredPage::query()->whereKey($this->monitoredPageId)->value('domain');

        return $domain !== '' ? 'page-intelligence-host:'.$domain : '';
    }
}
