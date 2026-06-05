<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class InternalLinkingAgent extends AbstractMarketingAgent
{
    public const KEY = 'internal_linking_agent';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'Internal linking agent', 'Designs link architecture, anchors, dependencies, and supporting context.', ['internal_links', 'content_graph', 'anchors'], self::class);
    }

    protected function focus(): string
    {
        return 'internal_linking';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Use hub-and-spoke internal links for '.$topic.' with pillar, implementation, comparison, and FAQ cross-links.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Define the pillar URL, target support pages, and anchor text rules for '.$topic.'.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Improves discoverability, topical authority, and conversion paths across the customer site.';
    }
}
