<?php

namespace App\Jobs\LearningOptimization;

use App\Models\Workspace;
use App\Services\LearningOptimization\LearningOptimizationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunLearningOptimizationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $workspaceId)
    {
        $this->onQueue('intelligence');
    }

    public function uniqueId(): string
    {
        return 'learning-optimization:'.$this->workspaceId;
    }

    public function handle(LearningOptimizationEngine $engine): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);

        $engine->run($workspace);
    }
}
