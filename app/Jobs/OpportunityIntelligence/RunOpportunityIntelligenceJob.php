<?php

namespace App\Jobs\OpportunityIntelligence;

use App\Models\Workspace;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunOpportunityIntelligenceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $workspaceId,
    ) {
        $this->onQueue('intelligence');
    }

    public function uniqueId(): string
    {
        return 'opportunity-intelligence:'.$this->workspaceId;
    }

    public function handle(OpportunityIntelligenceEngine $engine): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);

        $engine->run($workspace);
    }
}
