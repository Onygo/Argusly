<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Llm\LlmPromptRuntime;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AgentTaskPlannerService
{
    public const ACTION_AGENT_MAP = [
        'refresh_content' => 'content',
        'create_answer_block' => 'seo',
        'translate_content' => 'content',
        'create_social_post' => 'social',
        'run_visibility_check' => 'visibility',
        'reconnect_integration' => 'monitoring',
        'create_campaign_task_plan' => 'content',
        'create_campaign_briefing' => 'content',
        'create_newsletter_digest' => 'content',
        'create_audience_newsletter' => 'content',
        'submit_newsletter_for_approval' => 'content',
        'schedule_newsletter' => 'content',
        'attach_content_to_campaign' => 'content',
        'attach_social_post_to_campaign' => 'social',
        'create_objective_actions' => 'content',
    ];

    public function __construct(
        private readonly AgentManager $agents,
        private readonly DomainEventService $events,
        private readonly LlmPromptRuntime $llm,
    ) {}

    public function planForRecommendation(Recommendation $recommendation, ?User $user = null): ?AgentTask
    {
        if ($recommendation->action_type === null || ! isset(self::ACTION_AGENT_MAP[$recommendation->action_type])) {
            return null;
        }

        if ($user !== null) {
            $this->assertTenantUser($recommendation, $user);
        }

        $agent = $this->matchingAgent($recommendation);
        $response = $this->llm->generate(
            account: $recommendation->account,
            brand: $recommendation->brand,
            user: $user,
            purpose: 'agent_task',
            messages: [[
                'role' => 'user',
                'content' => "Plan an agent task for this recommendation.\n\nTitle: {$recommendation->title}\n\nAction: {$recommendation->recommended_action}",
            ]],
            systemPrompt: 'You are Argusly agent planning runtime. Produce a concise internal task plan.',
            fakeContent: $recommendation->recommended_action ?: $recommendation->title,
            metadata: [
                'recommendation_id' => $recommendation->id,
                'action_type' => $recommendation->action_type,
                'agent_id' => $agent->id,
            ],
        );

        $task = AgentTask::query()->updateOrCreate(
            [
                'account_id' => $recommendation->account_id,
                'recommendation_id' => $recommendation->id,
            ],
            [
                'agent_id' => $agent->id,
                'brand_id' => $recommendation->brand_id,
                'title' => $recommendation->title,
                'description' => $recommendation->recommended_action,
                'status' => AgentTask::query()
                    ->where('account_id', $recommendation->account_id)
                    ->where('recommendation_id', $recommendation->id)
                    ->value('status') ?? 'pending',
                'payload' => [
                    'recommendation_id' => $recommendation->id,
                    'action_type' => $recommendation->action_type,
                    'action_payload' => $recommendation->action_payload,
                    'placeholder' => true,
                    'ai_execution' => 'disabled',
                    'llm_response' => $response->toArray(),
                    'plan' => $response->content,
                ],
            ],
        );

        if ($task->wasRecentlyCreated) {
            $this->events->recordForSubject('AgentTaskPlanned', $task, $user, $this->eventPayload($task));
        }

        return $task->refresh();
    }

    public function approve(AgentTask $task, User $user): AgentTask
    {
        $this->assertTaskTenantUser($task, $user);

        $task->forceFill(['status' => 'approved'])->save();
        $this->events->recordForSubject('AgentTaskApproved', $task->refresh(), $user, $this->eventPayload($task));

        return $task->refresh();
    }

    public function queue(AgentTask $task, User $user): AgentTask
    {
        $this->assertTaskTenantUser($task, $user);

        if (! in_array($task->status, ['pending', 'approved'], true)) {
            throw new InvalidArgumentException('Only pending or approved agent tasks can be queued.');
        }

        $task->forceFill([
            'status' => 'queued',
            'dispatched_at' => now(),
        ])->save();
        $this->events->recordForSubject('AgentTaskQueued', $task->refresh(), $user, $this->eventPayload($task));

        return $task->refresh();
    }

    public function complete(AgentTask $task, User $user, array $result = []): AgentTask
    {
        $this->assertTaskTenantUser($task, $user);

        $task->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'payload' => [
                ...($task->payload ?? []),
                'result' => $result,
            ],
        ])->save();
        $this->events->recordForSubject('AgentTaskCompleted', $task->refresh(), $user, $this->eventPayload($task));

        return $task->refresh();
    }

    public function fail(AgentTask $task, User $user, string $reason): AgentTask
    {
        $this->assertTaskTenantUser($task, $user);

        $task->forceFill([
            'status' => 'failed',
            'completed_at' => now(),
            'payload' => [
                ...($task->payload ?? []),
                'failure_reason' => $reason,
            ],
        ])->save();
        $this->events->recordForSubject('AgentTaskFailed', $task->refresh(), $user, [
            ...$this->eventPayload($task),
            'reason' => $reason,
        ]);

        return $task->refresh();
    }

    /**
     * @return Collection<int, AgentTask>
     */
    public function tasksForRecommendation(Recommendation $recommendation): Collection
    {
        return AgentTask::query()
            ->where('account_id', $recommendation->account_id)
            ->where('recommendation_id', $recommendation->id)
            ->with('agent')
            ->latest()
            ->get();
    }

    private function matchingAgent(Recommendation $recommendation): Agent
    {
        return $this->agents->findAgent(self::ACTION_AGENT_MAP[$recommendation->action_type]);
    }

    private function assertTenantUser(Recommendation $recommendation, User $user): void
    {
        if (! $user->accounts()->whereKey($recommendation->account_id)->exists()) {
            throw new InvalidArgumentException('User cannot plan agent tasks outside their account.');
        }

        if ($recommendation->brand_id !== null && ! $user->brands()->whereKey($recommendation->brand_id)->exists()) {
            throw new InvalidArgumentException('User cannot plan agent tasks outside their brand.');
        }
    }

    private function assertTaskTenantUser(AgentTask $task, User $user): void
    {
        if (! $user->accounts()->whereKey($task->account_id)->exists()) {
            throw new InvalidArgumentException('User cannot update agent tasks outside their account.');
        }

        if ($task->brand_id !== null && ! $user->brands()->whereKey($task->brand_id)->exists()) {
            throw new InvalidArgumentException('User cannot update agent tasks outside their brand.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(AgentTask $task): array
    {
        return [
            'agent_id' => $task->agent_id,
            'recommendation_id' => $task->recommendation_id,
            'status' => $task->status,
            'action_type' => $task->payload['action_type'] ?? null,
        ];
    }
}
