<?php

namespace App\Jobs\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentTask;
use App\Services\AgenticMarketing\Orchestration\AgentOrchestrationService;
use App\Services\AgenticMarketing\Orchestration\AgentTaskExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteAgenticMarketingAgentTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $taskId) {}

    public function handle(AgentTaskExecutor $executor, AgentOrchestrationService $orchestration): void
    {
        $task = AgenticMarketingAgentTask::query()->with('orchestrationRun')->findOrFail($this->taskId);

        $executed = $executor->execute($task);

        if ($executed->status === 'retry_pending') {
            self::dispatch((string) $executed->id)->onQueue('agentic-marketing');

            return;
        }

        $run = $task->orchestrationRun()->with('tasks')->firstOrFail();
        $remaining = $run->tasks->whereIn('status', ['queued', 'running', 'retry_pending'])->count();

        if ($remaining === 0) {
            $orchestration->finalize($run);
        }
    }
}
