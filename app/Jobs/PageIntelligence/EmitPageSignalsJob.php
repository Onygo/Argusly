<?php

namespace App\Jobs\PageIntelligence;

use App\Jobs\Middleware\PageIntelligenceHostRateLimit;
use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PageSignalEmitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class EmitPageSignalsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(public readonly string $pageSnapshotId)
    {
        $this->onQueue((string) config('page_intelligence.queues.signal', config('page_intelligence.signals.queue', 'page_intelligence_signal')));
    }

    public function handle(PageSignalEmitter $emitter): void
    {
        $snapshot = PageSnapshot::query()->findOrFail($this->pageSnapshotId);

        $emitter->emit($snapshot);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('page-intelligence:signal:'.$this->pageSnapshotId))->releaseAfter(60)->expireAfter(600), new PageIntelligenceHostRateLimit];
    }

    public function pageIntelligenceRateLimitKey(): string
    {
        $snapshot = PageSnapshot::query()->with('page')->find($this->pageSnapshotId);

        return $snapshot?->page ? 'page-intelligence-host:'.$snapshot->page->domain : '';
    }
}
