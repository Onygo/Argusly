<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentConflict;
use App\Models\AgenticMarketingOrchestrationRun;

class AgentConflictResolver
{
    public function detectAndResolve(AgenticMarketingOrchestrationRun $run, array $normalizedResult): array
    {
        $claims = collect((array) ($normalizedResult['claims'] ?? []));
        $conflicts = [];

        foreach ($claims->groupBy('claim_key') as $claimKey => $groupedClaims) {
            $values = $groupedClaims->pluck('value')->unique()->values();
            if ($values->count() <= 1) {
                continue;
            }

            $winner = $groupedClaims->sortByDesc('confidence_score')->first();
            $conflict = AgenticMarketingAgentConflict::query()->create([
                'orchestration_run_id' => (string) $run->id,
                'conflict_key' => (string) $claimKey,
                'status' => 'resolved',
                'claims' => $groupedClaims->values()->all(),
                'resolution' => [
                    'strategy' => 'highest_confidence',
                    'selected_value' => $winner['value'] ?? null,
                    'selected_agent_key' => $winner['agent_key'] ?? null,
                    'confidence_score' => $winner['confidence_score'] ?? 0,
                ],
            ]);
            $conflicts[] = $conflict->toArray();
        }

        return $conflicts;
    }
}
