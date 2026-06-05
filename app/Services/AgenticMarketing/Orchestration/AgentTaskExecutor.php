<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentTask;
use Throwable;

class AgentTaskExecutor
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly AgentTraceLogger $traceLogger,
        private readonly AgentMemoryService $memoryService,
    ) {}

    public function execute(AgenticMarketingAgentTask $task): AgenticMarketingAgentTask
    {
        $task->loadMissing('orchestrationRun');
        $run = $task->orchestrationRun;

        $task->forceFill([
            'status' => 'running',
            'attempts' => $task->attempts + 1,
            'started_at' => now(),
            'error_message' => null,
        ])->save();
        $this->traceLogger->record($run, 'task.started', ['agent_key' => $task->agent_key, 'attempts' => $task->attempts], $task);

        try {
            $agent = $this->registry->get($task->agent_key);
            $result = $agent->handle((array) $run->shared_context, (array) $task->input);

            $task->forceFill([
                'status' => 'completed',
                'normalized_result' => $result->toArray(),
                'confidence_score' => $result->confidenceScore,
                'tool_plan' => $result->toolPlan,
                'mcp_context' => [
                    'compatible' => true,
                    'context_schema' => data_get($run->shared_context, 'schema'),
                    'agent_key' => $task->agent_key,
                ],
                'finished_at' => now(),
            ])->save();

            $this->memoryService->persistFromTask($run, $task);
            $this->traceLogger->record($run, 'task.completed', ['agent_key' => $task->agent_key, 'confidence_score' => $result->confidenceScore], $task);

            return $task->refresh();
        } catch (Throwable $exception) {
            $retryable = ($task->attempts < $task->max_attempts);
            $task->forceFill([
                'status' => $retryable ? 'retry_pending' : 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
            $this->traceLogger->record($run, $retryable ? 'task.retry_pending' : 'task.failed', [
                'agent_key' => $task->agent_key,
                'error' => $exception->getMessage(),
            ], $task);

            if (! $retryable) {
                throw $exception;
            }

            return $task->refresh();
        }
    }
}
