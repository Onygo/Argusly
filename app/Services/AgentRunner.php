<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\User;
use App\Services\Llm\LlmPromptRuntime;

class AgentRunner
{
    public function __construct(private readonly LlmPromptRuntime $llm) {}

    public function start(Agent $agent, Account $account, ?Brand $brand = null, array $metadata = [], ?User $user = null): AgentRun
    {
        $response = $this->llm->generate(
            account: $account,
            brand: $brand,
            user: $user,
            purpose: 'agent_task',
            messages: [[
                'role' => 'user',
                'content' => "Start agent {$agent->name} for a guarded Argusly workflow.",
            ]],
            systemPrompt: 'You are Argusly agent runtime. Return a concise internal execution status for a guarded workflow.',
            fakeContent: 'Agent execution architecture initialized in guarded runtime.',
            metadata: [
                'agent_id' => $agent->id,
                'agent_key' => $agent->key,
                ...$metadata,
            ],
        );

        $run = AgentRun::query()->create([
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'started_at' => now(),
            'status' => 'running',
            'result' => [
                'runtime' => 'guarded',
                'message' => $response->content,
                'llm_response' => $response->toArray(),
                ...$metadata,
            ],
        ]);

        app(DomainEventService::class)->recordForSubject('AgentRunStarted', $run, $user, [
            'agent_id' => $run->agent_id,
            'status' => $run->status,
            'metadata' => $metadata,
        ], $run->started_at);

        return $run;
    }

    public function complete(AgentRun $run, array $result = [], ?User $user = null): AgentRun
    {
        $run->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => [
                ...($run->result ?? []),
                ...$result,
            ],
        ])->save();

        app(DomainEventService::class)->recordForSubject('AgentRunCompleted', $run, $user, [
            'agent_id' => $run->agent_id,
            'status' => $run->status,
            'result' => $run->result,
        ], $run->completed_at);

        return $run->refresh();
    }

    public function runPlaceholder(Agent $agent, Account $account, ?Brand $brand = null, array $result = []): AgentRun
    {
        return $this->complete($this->start($agent, $account, $brand), [
            'placeholder' => true,
            'message' => 'Placeholder agent run completed without AI execution.',
            ...$result,
        ]);
    }
}
