<?php

namespace App\Jobs\Stats;

use App\Services\Stats\AiVisibilityMetricsBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateContentAiVisibilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly ?string $analyticsSiteId = null
    ) {}

    public function handle(AiVisibilityMetricsBuilder $builder): void
    {
        $builder->rebuild($this->analyticsSiteId);

        RecalculateAiSeoScoresJob::dispatch($this->analyticsSiteId)->onQueue($this->queue ?? 'default');
    }
}
