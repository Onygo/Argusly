<?php

namespace App\Jobs\PageIntelligence;

use App\Models\PageIntelligenceReport;
use App\Services\PageIntelligence\Reports\PageIntelligenceReportArtifactGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class GeneratePageIntelligenceReportArtifactJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(public readonly string $pageIntelligenceReportId)
    {
        $this->onQueue((string) config('page_intelligence.queues.reports', 'page_intelligence_reports'));
    }

    public function handle(PageIntelligenceReportArtifactGenerator $artifacts): void
    {
        $report = PageIntelligenceReport::query()->find($this->pageIntelligenceReportId);

        if (! $report instanceof PageIntelligenceReport) {
            return;
        }

        $artifacts->generate($report);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:report-artifact:'.$this->pageIntelligenceReportId))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }
}
