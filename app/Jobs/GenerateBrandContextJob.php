<?php

namespace App\Jobs;

use App\Models\EnrichmentRun;
use App\Services\BrandContext\BrandContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateBrandContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly string $runId,
    ) {
    }

    public function handle(BrandContextService $brandContextService): void
    {
        $run = EnrichmentRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        try {
            $brandContextService->generateBrandContext($run);
        } catch (\Throwable $e) {
            $brandContextService->markFailed($run, $e);
            throw $e;
        }
    }
}
