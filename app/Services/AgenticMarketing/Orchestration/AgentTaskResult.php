<?php

namespace App\Services\AgenticMarketing\Orchestration;

class AgentTaskResult
{
    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<int,array<string,mixed>>  $recommendations
     * @param  array<int,array<string,mixed>>  $actions
     * @param  array<int,array<string,mixed>>  $claims
     * @param  array<string,mixed>  $memory
     * @param  array<string,mixed>  $metrics
     */
    public function __construct(
        public readonly string $agentKey,
        public readonly string $summary,
        public readonly float $confidenceScore,
        public readonly array $findings = [],
        public readonly array $recommendations = [],
        public readonly array $actions = [],
        public readonly array $claims = [],
        public readonly array $memory = [],
        public readonly array $metrics = [],
        public readonly array $toolPlan = [],
    ) {}

    public function toArray(): array
    {
        return [
            'schema' => 'agentic_marketing.agent_result.v1',
            'agent_key' => $this->agentKey,
            'summary' => $this->summary,
            'confidence_score' => round($this->confidenceScore, 2),
            'findings' => $this->findings,
            'recommendations' => $this->recommendations,
            'actions' => $this->actions,
            'claims' => $this->claims,
            'memory' => $this->memory,
            'metrics' => $this->metrics,
            'tool_plan' => $this->toolPlan,
        ];
    }
}
