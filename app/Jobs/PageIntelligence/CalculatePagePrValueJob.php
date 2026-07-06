<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PagePrValueCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CalculatePagePrValueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    /**
     * @param array<int,string>|null $modelKeys
     */
    public function __construct(
        public readonly string $pageSnapshotId,
        public readonly ?array $modelKeys = null,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.score', config('page_intelligence.pr_value.queue', 'page_intelligence_score')));
    }

    public function handle(PagePrValueCalculator $calculator): void
    {
        $snapshot = PageSnapshot::query()->findOrFail($this->pageSnapshotId);

        $calculator->calculate($snapshot, $this->modelKeys);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('page-intelligence:pr-value:'.$this->pageSnapshotId))->releaseAfter(60)->expireAfter(600), new PageIntelligenceHostRateLimit];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $snapshot = PageSnapshot::query()->with('page')->find($this->pageSnapshotId);

        return $snapshot?->page ? 'page-intelligence-host:'.$snapshot->page->domain : '';
    }
}
