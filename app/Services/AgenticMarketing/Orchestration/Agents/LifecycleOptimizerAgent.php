<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class LifecycleOptimizerAgent extends AbstractMarketingAgent
{
    public const KEY = 'lifecycle_optimizer';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Lifecycle optimizer agent', 'Detects refresh, decay, publishing readiness, and lifecycle timing.', ['content_lifecycle', 'refresh', 'publishing_readiness'], self::class);
    }

    protected function focus(): string
    {
        return 'lifecycle_optimization';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Blend new '.$topic.' assets with refresh candidates so lifecycle gains compound with new authority.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Identify existing pages that should be refreshed before or alongside the new '.$topic.' assets.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Protects existing content value while adding new growth assets.';
    }
}
