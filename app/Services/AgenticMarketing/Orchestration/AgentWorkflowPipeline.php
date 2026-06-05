<?php

namespace App\Services\AgenticMarketing\Orchestration;

class AgentWorkflowPipeline
{
    public function defaultAgentKeys(): array
    {
        return [
            'seo_strategist',
            'content_strategist',
            'competitor_analyst',
            'buyer_intent_analyst',
            'campaign_planner',
            'lifecycle_optimizer',
            'internal_linking_agent',
            'aeo_answer_engine',
        ];
    }

    public function resolve(array $input = []): array
    {
        $requested = array_values(array_filter((array) ($input['agent_keys'] ?? [])));

        return $requested ?: $this->defaultAgentKeys();
    }
}
