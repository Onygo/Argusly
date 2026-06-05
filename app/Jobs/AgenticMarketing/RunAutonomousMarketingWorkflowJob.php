<?php

namespace App\Jobs\AgenticMarketing;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AutonomousMarketingWorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunAutonomousMarketingWorkflowJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 600;

    /**
     * @param  array<string,mixed>  $input
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $triggerType = 'signal_monitor',
        public readonly array $input = [],
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('agentic-marketing');
    }

    public function uniqueId(): string
    {
        return 'autonomous-marketing-workflow:'.$this->workspaceId.':'.$this->triggerType;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function handle(AutonomousMarketingWorkflowEngine $engine): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;

        $engine->run($workspace, $this->triggerType, $this->input, $actor);
    }
}
