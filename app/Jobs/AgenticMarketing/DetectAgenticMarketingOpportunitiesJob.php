<?php

namespace App\Jobs\AgenticMarketing;

use App\Services\AgenticMarketing\AgenticMarketingOpportunityDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectAgenticMarketingOpportunitiesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 240;

    public function __construct(
        public readonly ?string $objectiveId = null,
    ) {
        $this->onQueue((string) config('agentic_marketing.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'agentic-marketing-detect:' . ($this->objectiveId ?: 'all-active');
    }

    public function handle(AgenticMarketingOpportunityDetectionService $detection): void
    {
        $detection->detect($this->objectiveId);
    }
}
