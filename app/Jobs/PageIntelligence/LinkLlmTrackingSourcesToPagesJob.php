<?php

namespace App\Jobs\PageIntelligence;

use App\Models\LlmTrackingQueryRun;
use App\Services\PageIntelligence\Geo\PageGeoObservationBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class LinkLlmTrackingSourcesToPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        public readonly ?int $llmTrackingQueryRunId = null,
    ) {
        $this->onQueue((string) config('page_intelligence.queues.signal', 'page_intelligence_signal'));
    }

    public function handle(PageGeoObservationBuilder $builder): void
    {
        if ($this->llmTrackingQueryRunId !== null) {
            $run = LlmTrackingQueryRun::query()
                ->with('trackingQuery.workspace', 'trackingQuery.site')
                ->find($this->llmTrackingQueryRunId);

            if ($run) {
                $observations = $builder->buildForRun($run);

                if ($observations->isNotEmpty()) {
                    EvaluatePageAlertRulesJob::dispatch()->afterCommit();
                }
            }

            return;
        }

        LlmTrackingQueryRun::query()
            ->with('trackingQuery.workspace', 'trackingQuery.site')
            ->where('status', 'succeeded')
            ->chunkById(100, function ($runs) use ($builder): void {
                foreach ($runs as $run) {
                    $observations = $builder->buildForRun($run);

                    if ($observations->isNotEmpty()) {
                        EvaluatePageAlertRulesJob::dispatch()->afterCommit();
                    }
                }
            });
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('page-intelligence:llm-source-link:'.($this->llmTrackingQueryRunId ?? 'all')))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }
}
