<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class BuyerIntentAnalystAgent extends AbstractMarketingAgent
{
    public const KEY = 'buyer_intent_analyst';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Buyer intent analyst agent', 'Maps urgency, funnel stage, buyer role, and commercial impact.', ['query_intent', 'buyer_roles', 'funnel_mapping'], self::class);
    }

    protected function focus(): string
    {
        return 'buyer_intent';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Map '.$topic.' across awareness, consideration, decision, and retention before generating assets.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Assign each planned asset for '.$topic.' to a funnel stage, buyer role, and primary query intent.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Makes sure the plan serves actual buyer questions instead of only generic traffic.';
    }
}
