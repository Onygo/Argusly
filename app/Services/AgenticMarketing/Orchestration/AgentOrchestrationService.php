<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Jobs\AgenticMarketing\Orchestration\ExecuteAgenticMarketingAgentTaskJob;
use App\Models\AgenticMarketingAgentTask;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOrchestrationRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgentOrchestrationService
{
    public function __construct(
        private readonly SharedMarketingContextBuilder $contextBuilder,
        private readonly AgentWorkflowPipeline $pipeline,
        private readonly AgentTaskExecutor $taskExecutor,
        private readonly AgentResultNormalizer $normalizer,
        private readonly AgentConflictResolver $conflictResolver,
        private readonly AgentTraceLogger $traceLogger,
    ) {}

    public function start(
        Workspace $workspace,
        ?string $clientSiteId = null,
        ?AgenticMarketingObjective $objective = null,
        ?User $actor = null,
        array $input = [],
        bool $runInline = false,
    ): AgenticMarketingOrchestrationRun {
        return DB::transaction(function () use ($workspace, $clientSiteId, $objective, $actor, $input, $runInline): AgenticMarketingOrchestrationRun {
            $context = $this->contextBuilder->build($workspace, $clientSiteId, $objective, $input);
            $agentKeys = $this->pipeline->resolve($input);

            $run = AgenticMarketingOrchestrationRun::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => $clientSiteId,
                'objective_id' => $objective?->id,
                'workflow_key' => (string) ($input['workflow_key'] ?? 'agentic_marketing_strategy_council'),
                'status' => $runInline ? 'running' : 'queued',
                'mode' => (string) ($input['mode'] ?? 'manual'),
                'provider_key' => (string) ($input['provider_key'] ?? 'deterministic'),
                'trigger_source' => (string) ($input['trigger_source'] ?? 'ui'),
                'shared_context' => $context,
                'input' => $input,
                'tasks_count' => count($agentKeys),
                'requested_by' => $actor?->id,
                'started_at' => now(),
            ]);

            foreach ($agentKeys as $index => $agentKey) {
                AgenticMarketingAgentTask::query()->create([
                    'orchestration_run_id' => (string) $run->id,
                    'agent_key' => $agentKey,
                    'status' => 'queued',
                    'sequence_order' => $index + 1,
                    'input' => ['workflow_key' => $run->workflow_key],
                    'tool_plan' => ['tool_calling_ready' => true],
                    'mcp_context' => ['mcp_ready' => true],
                ]);
            }

            $this->traceLogger->record($run, 'orchestration.started', ['agent_keys' => $agentKeys, 'run_inline' => $runInline]);

            if ($runInline) {
                return $this->executeRun($run);
            }

            foreach ($run->tasks()->get() as $task) {
                ExecuteAgenticMarketingAgentTaskJob::dispatch((string) $task->id)->onQueue('agentic-marketing')->afterCommit();
            }

            return $run->fresh(['tasks', 'traces']) ?? $run;
        });
    }

    public function executeRun(AgenticMarketingOrchestrationRun $run): AgenticMarketingOrchestrationRun
    {
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?: now()])->save();

        do {
            $pending = false;
            foreach ($run->tasks()->whereIn('status', ['queued', 'retry_pending'])->orderBy('sequence_order')->get() as $task) {
                try {
                    $executed = $this->taskExecutor->execute($task);
                    $pending = $pending || $executed->status === 'retry_pending';
                } catch (Throwable) {
                    continue;
                }
            }
        } while ($pending);

        return $this->finalize($run);
    }

    public function finalize(AgenticMarketingOrchestrationRun $run): AgenticMarketingOrchestrationRun
    {
        $tasks = $run->tasks()->get();
        $normalized = $this->normalizer->aggregate($tasks);
        $conflicts = $this->conflictResolver->detectAndResolve($run, $normalized);
        $failed = $tasks->whereIn('status', ['failed', 'retry_pending'])->count();
        $completed = $tasks->where('status', 'completed')->count();

        $run->forceFill([
            'status' => $failed > 0 && $completed === 0 ? 'failed' : ($failed > 0 ? 'completed_with_warnings' : 'completed'),
            'normalized_result' => array_merge($normalized, ['conflicts' => $conflicts]),
            'confidence_score' => (float) ($normalized['confidence_score'] ?? 0),
            'completed_tasks_count' => $completed,
            'failed_tasks_count' => $failed,
            'conflicts_count' => count($conflicts),
            'finished_at' => now(),
        ])->save();

        $this->traceLogger->record($run, 'orchestration.finalized', [
            'status' => $run->status,
            'completed_tasks_count' => $completed,
            'failed_tasks_count' => $failed,
            'conflicts_count' => count($conflicts),
        ]);

        return $run->fresh(['tasks', 'traces', 'conflicts']) ?? $run;
    }
}
