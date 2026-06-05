<?php

namespace App\Jobs\Stats;

use App\Services\Stats\AiSeoScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateAiSeoScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly ?string $analyticsSiteId = null
    ) {
    }

    public function handle(AiSeoScoreCalculator $calculator): void
    {
        $calculator->recalculate($this->analyticsSiteId);
    }
}
