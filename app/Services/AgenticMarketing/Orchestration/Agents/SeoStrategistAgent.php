<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class SeoStrategistAgent extends AbstractMarketingAgent
{
    public const KEY = 'seo_strategist';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'SEO strategist agent', 'Prioritizes organic search architecture, keywords, schema, and technical SEO leverage.', ['seo', 'schema', 'search_intent'], self::class);
    }

    protected function focus(): string
    {
        return 'seo_strategy';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Prioritize an SEO hub for '.$topic.' with schema, metadata, and high-intent supporting pages.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Create or select the pillar page for '.$topic.' and define the primary keyword, schema type, and metadata brief.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Gives the customer a clearer search entry point and a stronger page to rank, cite, and link toward.';
    }
}
