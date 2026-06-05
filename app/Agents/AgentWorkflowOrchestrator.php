<?php

namespace App\Agents;

use App\Agents\Contracts\AgentWorkflowInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentWorkflowResult;
use App\Agents\Data\AgentWorkflowStep;
use App\Agents\Support\AgentRunStatus;
use App\Models\AgentWorkflowRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentWorkflowOrchestrator
{
    public function __construct(
        private readonly AgentOrchestrator $agentOrchestrator,
    ) {
    }

    public function run(AgentWorkflowInterface $workflow, AgentContext $context): AgentWorkflowResult
    {
        $workflowKey = trim($workflow->key()) !== '' ? trim($workflow->key()) : class_basename($workflow);
        $startedAt = CarbonImmutable::now();

        $run = AgentWorkflowRun::query()->create([
            'workflow_key' => $workflowKey,
            'trigger_type' => $context->triggerType,
            'trigger_source' => $context->triggerSource,
            'status' => AgentRunStatus::RUNNING->value,
            'organization_id' => $context->organizationId,
            'workspace_id' => $context->workspaceId,
            'site_id' => $context->siteId,
            'content_id' => $context->contentId,
            'draft_id' => $context->draftId,
            'user_id' => $context->userId,
            'input_payload' => $context->toArray(),
            'started_at' => $startedAt,
        ]);

        Log::info('Agent workflow started.', [
            'workflow_key' => $workflowKey,
            'workflow_run_id' => $run->getKey(),
            'trigger_type' => $context->triggerType,
        ]);

        try {
            if (! $workflow->supports($context)) {
                $result = AgentWorkflowResult::skipped(
                    workflowKey: $workflowKey,
                    summary: 'Workflow does not support the provided context.',
                    startedAt: $startedAt,
                    finishedAt: CarbonImmutable::now(),
                );

                $this->finalizeRun($run, $result);

                return $result;
            }

            $completedSteps = [];

            foreach ($workflow->steps($context) as $index => $step) {
                if (! $step instanceof AgentWorkflowStep) {
                    continue;
                }

                $stepNumber = $index + 1;

                if (! $step->shouldExecute($context, $completedSteps)) {
                    $completedSteps[] = [
                        'step_key' => $step->key,
                        'step_number' => $stepNumber,
                        'agent_key' => $step->agent->key(),
                        'status' => AgentRunStatus::SKIPPED->value,
                        'summary' => 'Step condition not met.',
                        'agent_run_id' => null,
                    ];

                    continue;
                }

                $resolvedContext = $step->resolveContext($context, $completedSteps);
                $execution = $this->agentOrchestrator->runWithRecord(
                    agent: $step->agent,
                    context: $resolvedContext,
                    workflowRun: $run,
                    workflowStepKey: $step->key,
                );

                $completedSteps[] = [
                    'step_key' => $step->key,
                    'step_number' => $stepNumber,
                    'agent_key' => $execution->run->agent_key,
                    'status' => $execution->result->status,
                    'summary' => $execution->result->summary,
                    'agent_run_id' => (string) $execution->run->getKey(),
                ];

                if ($step->shouldHaltAfter($execution->result->status)) {
                    break;
                }
            }

            $result = $this->buildResult(
                workflowKey: $workflowKey,
                completedSteps: $completedSteps,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );

            $this->finalizeRun($run, $result);

            Log::info('Agent workflow finished.', [
                'workflow_key' => $workflowKey,
                'workflow_run_id' => $run->getKey(),
                'status' => $result->status,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $result = AgentWorkflowResult::make(
                workflowKey: $workflowKey,
                status: AgentRunStatus::FAILED->value,
                summary: 'Workflow execution failed.',
                rawPayload: [
                    'exception_class' => $exception::class,
                ],
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            );

            $this->finalizeRun($run, $result, $exception->getMessage());

            Log::error('Agent workflow failed.', [
                'workflow_key' => $workflowKey,
                'workflow_run_id' => $run->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $completedSteps
     */
    private function buildResult(
        string $workflowKey,
        array $completedSteps,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
    ): AgentWorkflowResult {
        $statusCounts = collect($completedSteps)
            ->countBy(fn (array $step): string => (string) ($step['status'] ?? AgentRunStatus::SKIPPED->value))
            ->all();

        $executedCount = count(array_filter($completedSteps, fn (array $step): bool => ($step['agent_run_id'] ?? null) !== null));
        $failedCount = (int) ($statusCounts[AgentRunStatus::FAILED->value] ?? 0);
        $warningCount = (int) ($statusCounts[AgentRunStatus::WARNING->value] ?? 0);
        $successCount = (int) ($statusCounts[AgentRunStatus::SUCCESS->value] ?? 0);
        $skippedCount = (int) ($statusCounts[AgentRunStatus::SKIPPED->value] ?? 0);

        $status = match (true) {
            $executedCount === 0 => AgentRunStatus::SKIPPED->value,
            $failedCount > 0 && $successCount === 0 && $warningCount === 0 => AgentRunStatus::FAILED->value,
            $failedCount > 0 || $warningCount > 0 => AgentRunStatus::WARNING->value,
            default => AgentRunStatus::SUCCESS->value,
        };

        $summary = match ($status) {
            AgentRunStatus::SKIPPED->value => 'Workflow finished without executing any agent steps.',
            AgentRunStatus::FAILED->value => sprintf('Workflow failed after %d step(s).', max(1, $executedCount)),
            AgentRunStatus::WARNING->value => sprintf(
                'Workflow finished with %d success, %d warning, %d failed, and %d skipped step(s).',
                $successCount,
                $warningCount,
                $failedCount,
                $skippedCount,
            ),
            default => sprintf('Workflow finished successfully with %d executed step(s).', $executedCount),
        };

        return AgentWorkflowResult::make(
            workflowKey: $workflowKey,
            status: $status,
            summary: $summary,
            steps: $completedSteps,
            metrics: [
                'executed_step_count' => $executedCount,
                'success_count' => $successCount,
                'warning_count' => $warningCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
            ],
            rawPayload: [
                'status_counts' => $statusCounts,
            ],
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    private function finalizeRun(AgentWorkflowRun $run, AgentWorkflowResult $result, ?string $errorMessage = null): void
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

