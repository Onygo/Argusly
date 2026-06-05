<?php

namespace App\Jobs\AgenticMarketing\ExecutionPipeline;

use App\Models\AgenticMarketingOpportunity;
use App\Models\User;
use App\Services\AgenticMarketing\ExecutionPipeline\OpportunityExecutionPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareOpportunityExecutionPipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $opportunityId,
        public readonly string $mode = 'manual',
        public readonly ?int $actorId = null,
        public readonly array $input = [],
    ) {}

    public function handle(OpportunityExecutionPipelineService $service): void
    {
        $opportunity = AgenticMarketingOpportunity::query()->findOrFail($this->opportunityId);
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;

        $service->prepare($opportunity, $this->mode, $actor, $this->input);
    }
}
