<?php

namespace App\Services\AgenticMarketing\Orchestration\Agents;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;
use App\Services\AgenticMarketing\Orchestration\AgentTaskResult;
use App\Services\AgenticMarketing\Orchestration\Contracts\MarketingAgent;
use Illuminate\Support\Str;

abstract class AbstractMarketingAgent implements MarketingAgent
{
    public function handle(array $sharedContext, array $taskInput = []): AgentTaskResult
    {
        $topic = $this->topic($sharedContext);
        $signals = $this->signals($sharedContext);
        $finding = $this->finding($topic, $signals, $sharedContext);
        $recommendation = $this->recommendation($topic, $signals, $sharedContext);
        $confidence = $this->confidence($signals, $sharedContext);
        $definition = $this->definition();

        return new AgentTaskResult(
            agentKey: $definition->key,
            summary: $this->summary($topic, $signals),
            confidenceScore: $confidence,
            findings: [$finding],
            recommendations: [$recommendation],
            actions: [$this->action($topic, $recommendation)],
            claims: [$this->claim($topic, $recommendation, $confidence)],
            memory: [
                'last_topic' => $topic,
                'strongest_signal' => $signals[0] ?? 'company_intelligence',
                'recommendation_key' => $recommendation['recommendation_key'],
            ],
            metrics: [
                'signals_seen' => count($signals),
                'context_version' => data_get($sharedContext, 'schema'),
            ],
            toolPlan: [
                'provider_agnostic' => true,
                'future_tool_calls' => $this->futureTools(),
                'mcp_compatible_context_keys' => array_keys($sharedContext),
            ],
        );
    }

    abstract public function definition(): AgentDefinition;

    abstract protected function focus(): string;

    /**
     * @return array<int,string>
     */
    protected function futureTools(): array
    {
        return ['read_context', 'score_signal', 'write_memory'];
    }

    protected function summary(string $topic, array $signals): string
    {
        return sprintf('%s reviewed %s with %d supporting signal(s).', $this->definition()->name, $topic, count($signals));
    }

    protected function finding(string $topic, array $signals, array $context): array
    {
        return [
            'type' => $this->focus(),
            'title' => Str::headline($this->focus()).' signal for '.$topic,
            'detail' => $this->findingText($topic),
            'evidence' => array_slice($signals, 0, 5),
        ];
    }

    protected function recommendation(string $topic, array $signals, array $context): array
    {
        return [
            'recommendation_key' => $this->focus().':'.$this->normalizedTopicKey($topic),
            'topic' => $topic,
            'priority' => count($signals) >= 3 ? 'high' : 'medium',
            'recommendation' => $this->recommendationText($topic),
            'owner_agent' => $this->definition()->key,
        ];
    }

    protected function action(string $topic, array $recommendation): array
    {
        return [
            'type' => 'planning_recommendation',
            'title' => $recommendation['recommendation'],
            'topic' => $topic,
            'status' => 'proposed',
            'owner_agent' => $this->definition()->key,
            'priority' => $recommendation['priority'],
            'impact' => $recommendation['priority'] === 'high' ? 'high' : 'medium',
            'effort' => $this->effort(),
            'next_step' => $this->nextStep($topic),
            'customer_value' => $this->customerValue($topic),
        ];
    }

    protected function claim(string $topic, array $recommendation, float $confidence): array
    {
        return [
            'claim_key' => 'topic_priority:'.$this->normalizedTopicKey($topic),
            'value' => $recommendation['priority'],
            'confidence_score' => $confidence,
            'agent_key' => $this->definition()->key,
            'reason' => $recommendation['recommendation'],
        ];
    }

    protected function findingText(string $topic): string
    {
        return 'The shared context contains enough signal to include '.$topic.' in Agentic Marketing planning.';
    }

    protected function recommendationText(string $topic): string
    {
        return 'Include '.$topic.' in the next planning pass.';
    }

    protected function nextStep(string $topic): string
    {
        return 'Review this recommendation and decide whether to add it to the customer action plan.';
    }

    protected function customerValue(string $topic): string
    {
        return 'Improves the customer plan for '.$topic.'.';
    }

    protected function effort(): string
    {
        return 'medium';
    }

    protected function confidence(array $signals, array $context): float
    {
        return min(96, 62 + (count($signals) * 6));
    }

    protected function topic(array $context): string
    {
        $topic = data_get($context, 'focus.topic')
            ?: data_get($context, 'company.primary_topics.0')
            ?: data_get($context, 'opportunities.0.topic')
            ?: data_get($context, 'opportunities.0.title')
            ?: 'agentic marketing';

        return Str::of((string) $topic)->lower()->replaceMatches('/\s+/', ' ')->trim()->value();
    }

    protected function signals(array $context): array
    {
        return collect([
            data_get($context, 'company.company_name') ? 'company_intelligence' : null,
            count((array) data_get($context, 'opportunities', [])) > 0 ? 'content_opportunities' : null,
            count((array) data_get($context, 'competitor_gaps', [])) > 0 ? 'competitor_intelligence' : null,
            count((array) data_get($context, 'campaign_clusters', [])) > 0 ? 'campaign_clusters' : null,
            count((array) data_get($context, 'existing_content', [])) > 0 ? 'content_inventory' : null,
            count((array) data_get($context, 'memories', [])) > 0 ? 'agent_memory' : null,
        ])->filter()->values()->all();
    }

    protected function normalizedTopicKey(string $topic): string
    {
        return Str::slug($topic) ?: 'general';
    }
}
