<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class CompetitorAnalystAgent extends AbstractMarketingAgent
{
    public const KEY = 'competitor_analyst';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Competitor analyst agent', 'Finds competitor pressure, attackable angles, and content gaps.', ['competitor_intelligence', 'gap_analysis', 'positioning'], self::class);
    }

    protected function focus(): string
    {
        return 'competitor_pressure';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Create attackable comparison and alternative angles for '.$topic.' where competitors already frame demand.';
    }

    protected function nextStep(string $topic): string
    {
        return 'List the top competitor pages for '.$topic.' and draft one comparison or alternatives page angle.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Helps the customer show up where buyers compare options and evaluate alternatives.';
    }
}
