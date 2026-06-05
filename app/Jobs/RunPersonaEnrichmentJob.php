<?php

namespace App\Jobs;

use App\Models\EnrichmentRun;
use App\Services\WorkspaceIntelligence\WorkspaceIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunPersonaEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $runId,
    ) {
    }

    public function handle(WorkspaceIntelligenceService $workspaceIntelligence): void
    {
        $run = EnrichmentRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        try {
            $workspaceIntelligence->generateBuyerPersonas($run);
        } catch (\Throwable $e) {
            $workspaceIntelligence->markFailed($run, $e);
            throw $e;
        }
    }
}
