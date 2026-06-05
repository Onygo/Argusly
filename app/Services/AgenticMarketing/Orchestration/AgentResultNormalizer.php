<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentTask;
use Illuminate\Support\Collection;

class AgentResultNormalizer
{
    /**
     * @param  Collection<int,AgenticMarketingAgentTask>  $tasks
     */
    public function aggregate(Collection $tasks): array
    {
        $completed = $tasks->where('status', 'completed');
        $results = $completed->map(fn (AgenticMarketingAgentTask $task): array => (array) $task->normalized_result)->values();

        return [
            'schema' => 'agentic_marketing.orchestration_result.v1',
            'agent_count' => $tasks->count(),
            'completed_agent_count' => $completed->count(),
            'confidence_score' => round((float) $completed->avg('confidence_score'), 2),
            'findings' => $results->flatMap(fn (array $result): array => (array) ($result['findings'] ?? []))->values()->all(),
            'recommendations' => $results->flatMap(fn (array $result): array => (array) ($result['recommendations'] ?? []))->values()->all(),
            'actions' => $results->flatMap(fn (array $result): array => (array) ($result['actions'] ?? []))->values()->all(),
            'next_actions' => $this->nextActions($results),
            'claims' => $results->flatMap(fn (array $result): array => (array) ($result['claims'] ?? []))->values()->all(),
            'agent_summaries' => $results->map(fn (array $result): array => [
                'agent_key' => $result['agent_key'] ?? null,
                'summary' => $result['summary'] ?? '',
                'confidence_score' => $result['confidence_score'] ?? 0,
            ])->all(),
            'tool_plans' => $results->mapWithKeys(fn (array $result): array => [
                (string) ($result['agent_key'] ?? 'unknown') => (array) ($result['tool_plan'] ?? []),
            ])->all(),
        ];
    }

    private function nextActions(Collection $results): array
    {
        return $results
            ->flatMap(fn (array $result): array => (array) ($result['actions'] ?? []))
            ->map(function (array $action): array {
                $priority = (string) ($action['priority'] ?? 'medium');

                return array_merge($action, [
                    'priority_rank' => match ($priority) {
                        'critical' => 4,
                        'high' => 3,
                        'medium' => 2,
                        default => 1,
                    },
                ]);
            })
            ->sortByDesc('priority_rank')
            ->values()
            ->all();
    }
}
