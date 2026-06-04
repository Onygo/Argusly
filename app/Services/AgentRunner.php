<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Services\Llm\LlmPromptRuntime;

class AgentRunner
{
    public function __construct(private readonly LlmPromptRuntime $llm) {}

    public function start(Agent $agent, Account $account, ?Brand $brand = null): AgentRun
    {
        $response = $this->llm->generate(
            account: $account,
            brand: $brand,
            user: null,
            purpose: 'agent_task',
            messages: [[
                'role' => 'user',
                'content' => "Start agent {$agent->name} in placeholder mode.",
            ]],
            systemPrompt: 'You are Argusly agent runtime. Return a concise placeholder execution status.',
            fakeContent: 'Agent execution architecture initialized. AI execution is not enabled yet.',
            metadata: [
                'agent_id' => $agent->id,
                'agent_key' => $agent->key,
            ],
        );

        return AgentRun::query()->create([
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'started_at' => now(),
            'status' => 'running',
            'result' => [
                'placeholder' => true,
                'message' => $response->content,
                'llm_response' => $response->toArray(),
            ],
        ]);
    }

    public function complete(AgentRun $run, array $result = []): AgentRun
    {
        $run->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => [
                'placeholder' => true,
                ...$result,
            ],
        ])->save();

        app(DomainEventService::class)->recordForSubject('AgentRunCompleted', $run, null, [
            'agent_id' => $run->agent_id,
            'status' => $run->status,
            'result' => $run->result,
        ], $run->completed_at);

        return $run->refresh();
    }

    public function runPlaceholder(Agent $agent, Account $account, ?Brand $brand = null, array $result = []): AgentRun
    {
        return $this->complete($this->start($agent, $account, $brand), [
            'message' => 'Placeholder agent run completed without AI execution.',
            ...$result,
        ]);
    }
}
