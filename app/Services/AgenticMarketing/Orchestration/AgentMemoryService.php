<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentMemory;
use App\Models\AgenticMarketingAgentTask;
use App\Models\AgenticMarketingOrchestrationRun;

class AgentMemoryService
{
    public function persistFromTask(AgenticMarketingOrchestrationRun $run, AgenticMarketingAgentTask $task): void
    {
        $result = (array) ($task->normalized_result ?? []);
        $memory = (array) ($result['memory'] ?? []);

        foreach ($memory as $key => $value) {
            AgenticMarketingAgentMemory::query()->updateOrCreate(
                [
                    'workspace_id' => (string) $run->workspace_id,
                    'agent_key' => $task->agent_key,
                    'memory_key' => (string) $key,
                ],
                [
                    'organization_id' => $run->organization_id,
                    'client_site_id' => $run->client_site_id,
                    'memory_type' => 'agent_context',
                    'payload' => ['value' => $value, 'source_task_id' => (string) $task->id],
                    'confidence_score' => $task->confidence_score,
                    'last_used_at' => now(),
                    'expires_at' => now()->addMonths(6),
                ]
            );
        }
    }
}
