<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Services\Llm\LlmPromptRuntime;

class AgentTaskDispatcher
{
    public function __construct(private readonly LlmPromptRuntime $llm) {}

    public function dispatch(
        Agent $agent,
        Account $account,
        ?Brand $brand,
        string $title,
        ?string $description = null,
        ?Recommendation $recommendation = null,
        ?AgentRun $run = null,
        array $payload = [],
    ): AgentTask {
        $response = $this->llm->generate(
            account: $account,
            brand: $brand,
            user: null,
            purpose: 'agent_task',
            messages: [[
                'role' => 'user',
                'content' => "Dispatch agent task: {$title}\n\n{$description}",
            ]],
            systemPrompt: 'You are Argusly agent task runtime. Return a concise dispatch note.',
            fakeContent: $description ?: $title,
            metadata: [
                'agent_id' => $agent->id,
                'agent_run_id' => $run?->id,
                'recommendation_id' => $recommendation?->id,
            ],
        );

        return AgentTask::query()->create([
            'agent_id' => $agent->id,
            'agent_run_id' => $run?->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'recommendation_id' => $recommendation?->id,
            'title' => $title,
            'description' => $description,
            'status' => 'dispatched',
            'payload' => [
                'placeholder' => true,
                'ai_execution' => 'disabled',
                'llm_response' => $response->toArray(),
                'dispatch_note' => $response->content,
                ...$payload,
            ],
            'dispatched_at' => now(),
        ]);
    }

    public function dispatchRecommendation(Agent $agent, Recommendation $recommendation, ?AgentRun $run = null): AgentTask
    {
        return $this->dispatch(
            agent: $agent,
            account: $recommendation->account,
            brand: $recommendation->brand,
            title: $recommendation->title,
            description: $recommendation->recommended_action,
            recommendation: $recommendation,
            run: $run,
            payload: [
                'recommendation_id' => $recommendation->id,
                'impact_score' => $recommendation->impact_score,
                'confidence_score' => $recommendation->confidence_score,
            ],
        );
    }
}
