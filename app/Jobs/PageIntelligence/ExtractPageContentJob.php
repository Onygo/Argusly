<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PageContentExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ExtractPageContentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        public readonly string $pageSnapshotId,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.extract', config('page_intelligence.extract.queue', 'page_intelligence_extract')));
    }

    public function handle(PageContentExtractor $extractor): void
    {
        $snapshot = PageSnapshot::query()->find($this->pageSnapshotId);
        if (! $snapshot instanceof PageSnapshot) {
            return;
        }

        $extractor->extract($snapshot);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:extract:'.$this->pageSnapshotId))->releaseAfter(60)->expireAfter(600),
            new PageIntelligenceHostRateLimit,
        ];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $snapshot = PageSnapshot::query()->with('page')->find($this->pageSnapshotId);

        return $snapshot?->page ? 'page-intelligence-host:'.$snapshot->page->domain : '';
    }
}
