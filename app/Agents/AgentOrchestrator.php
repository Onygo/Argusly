<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentExecution;
use App\Agents\Data\AgentResult;
use App\Agents\Support\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorkflowRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentOrchestrator
{
    public function run(AgentInterface $agent, AgentContext $context): AgentResult
    {
        return $this->runWithRecord($agent, $context)->result;
    }

    public function runWithRecord(
        AgentInterface $agent,
        AgentContext $context,
        ?AgentWorkflowRun $workflowRun = null,
        ?string $workflowStepKey = null,
    ): AgentExecution
    {
        $agentKey = $this->safeAgentKey($agent);
        $startedAt = CarbonImmutable::now();

        $run = AgentRun::query()->create([
            'agent_key' => $agentKey,
            'trigger_type' => $context->triggerType,
            'trigger_source' => $context->triggerSource,
            'status' => AgentRunStatus::RUNNING->value,
            'organization_id' => $context->organizationId,
            'workspace_id' => $context->workspaceId,
            'site_id' => $context->siteId,
            'content_id' => $context->contentId,
            'draft_id' => $context->draftId,
            'user_id' => $context->userId,
            'workflow_run_id' => $workflowRun?->getKey(),
            'workflow_step_key' => $workflowStepKey,
            'input_payload' => $context->toArray(),
            'started_at' => $startedAt,
        ]);

        Log::info('Agent run started.', [
            'agent_key' => $agentKey,
            'agent_run_id' => $run->getKey(),
            'trigger_type' => $context->triggerType,
        ]);

        try {
            if (! $agent->supports($context)) {
                $result = AgentResult::skipped(
                    agentKey: $agentKey,
                    summary: 'Agent does not support the provided context.',
                    rawPayload: ['reason' => 'unsupported_context'],
                    startedAt: $startedAt,
                    finishedAt: CarbonImmutable::now(),
                );

                $this->finalizeRun($run, $result);
                Log::info('Agent run skipped.', [
                    'agent_key' => $agentKey,
                    'agent_run_id' => $run->getKey(),
                ]);

                return new AgentExecution(
                    run: $run->fresh() ?? $run,
                    result: $result,
                );
            }

            $result = $agent->run($context)
                ->withAgentKey($agentKey)
                ->withLifecycle($startedAt, CarbonImmutable::now());

            $this->finalizeRun($run, $result);
            Log::info('Agent run finished.', [
                'agent_key' => $agentKey,
                'agent_run_id' => $run->getKey(),
                'status' => $result->status,
            ]);

            return new AgentExecution(
                run: $run->fresh() ?? $run,
                result: $result,
            );
        } catch (Throwable $exception) {
            $result = AgentResult::failed(
                agentKey: $agentKey,
                summary: 'Agent execution failed.',
                warnings: [$exception->getMessage()],
                rawPayload: [
                    'exception_class' => $exception::class,
                ],
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );

            $this->finalizeRun($run, $result, $exception->getMessage());
            Log::error('Agent run failed.', [
                'agent_key' => $agentKey,
                'agent_run_id' => $run->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new AgentExecution(
                run: $run->fresh() ?? $run,
                result: $result,
            );
        }
    }

    private function safeAgentKey(AgentInterface $agent): string
    {
        try {
            $key = trim($agent->key());

            return $key !== '' ? $key : class_basename($agent);
        } catch (Throwable) {
            return class_basename($agent);
        }
    }

    private function finalizeRun(AgentRun $run, AgentResult $result, ?string $errorMessage = null): void
    {
        $run->forceFill([
            'status' => $result->status,
            'output_payload' => $result->toArray(),
            'summary' => $result->summary !== '' ? $result->summary : null,
            'error_message' => $errorMessage,
            'finished_at' => $result->finishedAt ?? CarbonImmutable::now(),
        ])->save();
    }
}
