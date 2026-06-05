<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AgentTask;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Briefing;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AgenticMarketingWorkflowService
{
    public function __construct(
        private readonly AgentManager $agents,
        private readonly AgentRunner $runner,
        private readonly AgentTaskPlannerService $planner,
        private readonly ApprovalService $approvals,
        private readonly DomainEventService $events,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, ?Brand $brand = null): array
    {
        $this->ensureBrandBelongsToAccount($account, $brand);
        $this->agents->ensureDefaultAgents();

        $openTasks = $this->taskQuery($account, $brand)
            ->open()
            ->with(['agent', 'brand', 'recommendation'])
            ->latest()
            ->limit(10)
            ->get();

        $latestRuns = $this->agents->latestRuns($account, $brand, 10);
        $pendingApprovals = $this->approvalQuery($account, $brand)
            ->pending()
            ->with(['subject', 'requester', 'brand'])
            ->latest('requested_at')
            ->limit(10)
            ->get();

        return [
            'stats' => [
                'briefings' => $this->briefingQuery($account, $brand)->count(),
                'planning_queue' => $this->planningQueueQuery($account, $brand)->count(),
                'open_agent_tasks' => $this->taskQuery($account, $brand)->open()->count(),
                'running_or_queued_runs' => $this->runStateCount($latestRuns, ['queued', 'running']),
                'pending_approvals' => $this->approvalQuery($account, $brand)->pending()->count(),
                'audit_events' => $this->auditQuery($account, $brand)->count(),
            ],
            'workflowStages' => $this->workflowStages($account, $brand),
            'planningQueue' => $this->planningQueueQuery($account, $brand)
                ->with(['brand', 'signal'])
                ->latest('created_at')
                ->limit(8)
                ->get(),
            'openTasks' => $openTasks,
            'latestRuns' => $latestRuns,
            'pendingApprovals' => $pendingApprovals,
            'latestBriefings' => $this->briefingQuery($account, $brand)
                ->with(['brand', 'campaign', 'creator', 'approver'])
                ->latest()
                ->limit(6)
                ->get(),
            'researchWorkspace' => $this->researchWorkspace($account, $brand),
            'auditTrail' => $this->auditQuery($account, $brand)
                ->with(['actor', 'brand'])
                ->latest('occurred_at')
                ->limit(12)
                ->get(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AgentTask>
     */
    public function paginatedTasks(Account $account, ?Brand $brand = null, int $perPage = 20): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $this->taskQuery($account, $brand)
            ->with(['agent', 'agentRun', 'brand', 'recommendation'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, \App\Models\AgentRun>
     */
    public function paginatedRuns(Account $account, ?Brand $brand = null, int $perPage = 20): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return \App\Models\AgentRun::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with(['agent', 'brand', 'tasks'])
            ->recent()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function planRecommendation(Recommendation $recommendation, User $user): ?AgentTask
    {
        $this->assertCanRunAgents($user, $recommendation->account, $recommendation->brand);

        $task = $this->planner->planForRecommendation($recommendation, $user);

        if ($task === null) {
            return null;
        }

        $this->ensureApprovalRequested($task, $user, 'Agent task requires review before execution.');
        $this->events->recordForSubject('AgenticWorkflowPlanned', $task->refresh(), $user, $this->taskPayload($task));

        return $task->refresh();
    }

    public function planBriefing(Briefing $briefing, User $user): AgentTask
    {
        $this->assertCanRunAgents($user, $briefing->account, $briefing->brand);

        $agent = $this->agents->findAgent('content');
        $task = AgentTask::query()->create([
            'agent_id' => $agent->id,
            'account_id' => $briefing->account_id,
            'brand_id' => $briefing->brand_id,
            'title' => 'Prepare content plan for '.$briefing->title,
            'description' => $briefing->objective ?: $briefing->key_message,
            'status' => 'pending',
            'payload' => [
                'source' => 'briefing',
                'briefing_id' => $briefing->id,
                'workflow' => 'briefing_to_plan',
                'channels' => $briefing->channels ?? [],
                'languages' => $briefing->languages ?? [],
            ],
        ]);

        $this->ensureApprovalRequested($task, $user, 'Approve this briefing-derived agent task before execution.');
        $this->events->recordForSubject('AgenticWorkflowPlanned', $task->refresh(), $user, $this->taskPayload($task));

        return $task->refresh();
    }

    public function requestTaskApproval(AgentTask $task, User $user, ?string $notes = null): Approval
    {
        $this->assertCanRunAgents($user, $task->account, $task->brand);

        return $this->approvals->request($task, $user, $notes);
    }

    public function queueTask(AgentTask $task, User $user): AgentTask
    {
        $this->assertCanRunAgents($user, $task->account, $task->brand);

        if (! $this->approvals->hasApproved($task) && $task->status !== 'approved') {
            throw new InvalidArgumentException('Agent task must be approved before it can be queued.');
        }

        $queued = $this->planner->queue($task, $user);
        $this->events->recordForSubject('AgenticWorkflowQueued', $queued, $user, $this->taskPayload($queued));

        return $queued;
    }

    public function runTask(AgentTask $task, User $user): AgentTask
    {
        $this->assertCanRunAgents($user, $task->account, $task->brand);

        if (! in_array($task->status, ['approved', 'queued', 'dispatched'], true)) {
            throw new InvalidArgumentException('Only approved, queued or dispatched agent tasks can be run.');
        }

        $run = $this->runner->start($task->agent, $task->account, $task->brand, [
            'workflow' => 'agentic_marketing',
            'agent_task_id' => $task->id,
        ]);

        $task->forceFill([
            'agent_run_id' => $run->id,
            'status' => 'running',
            'payload' => [
                ...($task->payload ?? []),
                'run_id' => $run->id,
                'runtime' => 'guarded',
            ],
            'dispatched_at' => $task->dispatched_at ?? now(),
        ])->save();

        $completedTask = $this->planner->complete($task->refresh(), $user, [
            'run_id' => $run->id,
            'message' => 'Agentic marketing workflow completed in guarded runtime.',
        ]);

        $this->runner->complete($run->refresh(), [
            'agent_task_id' => $completedTask->id,
            'message' => 'Agentic marketing workflow completed in guarded runtime.',
        ], $user);

        $this->events->recordForSubject('AgenticWorkflowCompleted', $completedTask->refresh(), $user, $this->taskPayload($completedTask));

        return $completedTask->refresh();
    }

    private function ensureApprovalRequested(AgentTask $task, User $user, string $notes): void
    {
        if (Approval::query()
            ->where('account_id', $task->account_id)
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists()) {
            return;
        }

        $this->approvals->request($task, $user, $notes);
    }

    /**
     * @return array<int, array{label: string, count: int, description: string}>
     */
    private function workflowStages(Account $account, ?Brand $brand): array
    {
        return [
            ['label' => 'Briefings', 'count' => $this->briefingQuery($account, $brand)->whereIn('status', ['draft', 'review'])->count(), 'description' => 'Draft and review inputs for agent work.'],
            ['label' => 'Research', 'count' => $this->planningQueueQuery($account, $brand)->count(), 'description' => 'Recommendations with actionable context.'],
            ['label' => 'Planning', 'count' => $this->taskQuery($account, $brand)->where('status', 'pending')->count(), 'description' => 'Agent tasks awaiting approval.'],
            ['label' => 'Approvals', 'count' => $this->approvalQuery($account, $brand)->pending()->count(), 'description' => 'Human review before execution.'],
            ['label' => 'Runs', 'count' => $this->taskQuery($account, $brand)->whereIn('status', ['queued', 'running', 'dispatched'])->count(), 'description' => 'Queued and active agent work.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function researchWorkspace(Account $account, ?Brand $brand): array
    {
        $recommendations = Recommendation::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );

        return [
            'actionable_recommendations' => (clone $recommendations)->whereNotNull('action_type')->open()->count(),
            'accepted_recommendations' => (clone $recommendations)->where('status', 'accepted')->count(),
            'unplanned_recommendations' => $this->planningQueueQuery($account, $brand)->count(),
        ];
    }

    private function planningQueueQuery(Account $account, ?Brand $brand): Builder
    {
        return Recommendation::query()
            ->where('account_id', $account->id)
            ->whereNotNull('action_type')
            ->open()
            ->whereNotIn('id', AgentTask::query()
                ->where('account_id', $account->id)
                ->whereNotNull('recommendation_id')
                ->select('recommendation_id'))
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    private function taskQuery(Account $account, ?Brand $brand): Builder
    {
        return AgentTask::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    private function briefingQuery(Account $account, ?Brand $brand): Builder
    {
        return Briefing::query()->forTenant($account, $brand);
    }

    private function approvalQuery(Account $account, ?Brand $brand): Builder
    {
        return Approval::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    private function auditQuery(Account $account, ?Brand $brand): Builder
    {
        return $this->events->tenantQuery($account, $brand)
            ->whereIn('event_type', [
                'AgentTaskPlanned',
                'AgentTaskApproved',
                'AgentTaskQueued',
                'AgentTaskCompleted',
                'AgentTaskFailed',
                'AgentRunStarted',
                'AgentRunCompleted',
                'AgenticWorkflowPlanned',
                'AgenticWorkflowQueued',
                'AgenticWorkflowCompleted',
                'ApprovalRequested',
                'ApprovalApproved',
                'ApprovalRejected',
                'ApprovalCancelled',
                'BriefingDraftCreatedFromRecommendation',
                'MarketingTaskCreatedFromRecommendation',
            ]);
    }

    private function runStateCount(Collection $runs, array $statuses): int
    {
        return $runs->whereIn('status', $statuses)->count();
    }

    private function assertCanRunAgents(User $user, Account $account, ?Brand $brand): void
    {
        if (! $this->permissions->userCan($user, 'run_agents', ['account_id' => $account->id, 'brand_id' => $brand?->id])) {
            throw new InvalidArgumentException('User cannot run agentic workflows for this tenant.');
        }
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Agentic marketing brand must belong to the account.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(AgentTask $task): array
    {
        return [
            'agent_id' => $task->agent_id,
            'agent_run_id' => $task->agent_run_id,
            'recommendation_id' => $task->recommendation_id,
            'status' => $task->status,
            'workflow' => $task->payload['workflow'] ?? 'recommendation_to_agent_task',
        ];
    }
}
