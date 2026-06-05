<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentTask;
use App\Models\AgenticMarketingAgentTrace;
use App\Models\AgenticMarketingOrchestrationRun;

class AgentTraceLogger
{
    public function record(AgenticMarketingOrchestrationRun $run, string $event, array $payload = [], ?AgenticMarketingAgentTask $task = null): void
    {
        AgenticMarketingAgentTrace::query()->create([
            'orchestration_run_id' => (string) $run->id,
            'agent_task_id' => $task?->id,
            'event' => $event,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
