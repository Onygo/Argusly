<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class ContentStrategistAgent extends AbstractMarketingAgent
{
    public const KEY = 'content_strategist';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Content strategist agent', 'Shapes narrative, formats, content mix, and editorial sequence.', ['content_strategy', 'editorial_planning', 'campaigns'], self::class);
    }

    protected function focus(): string
    {
        return 'content_strategy';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Build a content mix around '.$topic.' using pillar, use-case, FAQ, and implementation assets.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Turn the recommendation into a short editorial plan with one pillar, two support articles, one FAQ, and one implementation guide.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Moves the customer from isolated content ideas to a coherent content system.';
    }
}
