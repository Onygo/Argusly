<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;

class AeoAnswerEngineAgent extends AbstractMarketingAgent
{
    public const KEY = 'aeo_answer_engine';

    public function definition(): AgentDefinition
    {
        return new AgentDefinition(self::KEY, 'AEO/answer engine agent', 'Plans answer blocks, entity coverage, citations, and extractable answers.', ['aeo', 'answer_blocks', 'ai_visibility'], self::class);
    }

    protected function focus(): string
    {
        return 'aeo_answer_engine';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Add answer blocks, FAQ schema, and entity-rich summaries for '.$topic.' to improve AI visibility.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Draft three answer blocks and one FAQ section for the highest-priority '.$topic.' page.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Increases the chance that AI systems can extract, summarize, and cite the customer clearly.';
    }
}
