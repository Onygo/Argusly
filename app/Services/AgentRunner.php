<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;

class AgentRunner
{
    public function start(Agent $agent, Account $account, ?Brand $brand = null): AgentRun
    {
        return AgentRun::query()->create([
            'agent_id' => $agent->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'started_at' => now(),
            'status' => 'running',
            'result' => [
                'placeholder' => true,
                'message' => 'Agent execution architecture initialized. AI execution is not enabled yet.',
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
