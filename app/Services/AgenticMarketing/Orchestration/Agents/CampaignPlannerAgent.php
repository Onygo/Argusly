<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class CampaignPlannerAgent extends AbstractMarketingAgent
{
    public const KEY = 'campaign_planner';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Campaign planner agent', 'Turns opportunities into campaign clusters, dependencies, timelines, and CTAs.', ['campaign_clusters', 'timeline', 'cta_strategy'], self::class);
    }

    protected function focus(): string
    {
        return 'campaign_planning';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Plan '.$topic.' as a campaign cluster with a pillar-first publishing sequence and clear CTA progression.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Create a campaign cluster for '.$topic.' with publish dates, dependencies, and CTA placement.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Gives the customer a timeline and campaign structure they can actually execute.';
    }
}
